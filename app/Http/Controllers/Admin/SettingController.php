<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AuditLogger;
use App\Services\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingController extends Controller implements HasMiddleware
{
    public function __construct(protected SettingsRepository $settings) {}

    public static function middleware(): array
    {
        return [new Middleware('can:settings.manage')];
    }

    public function edit()
    {
        return view('admin.settings.edit', [
            'settings' => $this->settings->all(),
            'paymentMethods' => config('shop.payment_methods'),
            'accounts' => Account::active()->orderBy('sort_order')->get(['id', 'name', 'code']),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:120'],
            'store_phone' => ['nullable', 'string', 'max:40'],
            'store_email' => ['nullable', 'email', 'max:150'],
            'store_address' => ['nullable', 'string', 'max:500'],
            'store_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
            'currency_symbol' => ['required', 'string', 'max:5'],
            'order_prefix' => ['required', 'alpha_dash', 'max:10'],
            'delivery_charge' => ['required', 'numeric', 'min:0', 'max:100000'],
            'free_delivery_threshold' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:100000'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => [Rule::in(array_keys(config('shop.payment_methods')))],
            'invoice_note' => ['nullable', 'string', 'max:500'],
            'online_payment_account' => ['nullable', Rule::exists('accounts', 'code')->where('is_active', true)],
        ], [
            'payment_methods.required' => 'Enable at least one payment method.',
        ]);

        if (! array_intersect($data['payment_methods'], ['cod', 'online'])) {
            return back()->withInput()->with('error', 'Enable cash on delivery or the online gateway, otherwise customers cannot check out.');
        }

        $values = [
            'store_name' => $data['store_name'],
            'store_phone' => $data['store_phone'] ?? '',
            'store_email' => $data['store_email'] ?? '',
            'store_address' => $data['store_address'] ?? '',
            'currency_symbol' => $data['currency_symbol'],
            'order_prefix' => strtoupper($data['order_prefix']),
            'delivery_charge' => (float) $data['delivery_charge'],
            'free_delivery_threshold' => (float) $data['free_delivery_threshold'],
            'low_stock_threshold' => (int) $data['low_stock_threshold'],
            'allow_negative_stock' => $request->boolean('allow_negative_stock'),
            'payment_methods' => $data['payment_methods'],
            'invoice_note' => $data['invoice_note'] ?? '',
        ];

        if (filled($data['online_payment_account'] ?? null)) {
            $values['online_payment_account'] = $data['online_payment_account'];
        }

        $currentLogo = $this->settings->get('store_logo');

        if ($request->hasFile('store_logo')) {
            $values['store_logo'] = $request->file('store_logo')->store('settings', 'public');
        } elseif ($request->boolean('remove_logo')) {
            $values['store_logo'] = '';
        }

        [$old, $new] = $this->settings->set($values);

        if (array_key_exists('store_logo', $new) && $currentLogo) {
            Storage::disk('public')->delete($currentLogo);
        }

        if ($new) {
            AuditLogger::log('settings', 'updated', null, 'Store settings updated: ' . implode(', ', array_keys($new)), $old, $new);
        }

        return back()->with('success', $new ? 'Settings saved.' : 'Nothing changed.');
    }
}
