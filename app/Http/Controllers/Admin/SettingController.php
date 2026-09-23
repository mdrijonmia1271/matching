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

    /** How many pictures the hero can fade through. */
    public const HERO_IMAGE_SLOTS = 3;

    /** The home page hero: pictures, headline, script line and the offer card. */
    public function hero()
    {
        return view('admin.settings.hero', [
            'settings' => $this->settings->all(),
            'slots' => self::HERO_IMAGE_SLOTS,
        ]);
    }

    public function updateHero(Request $request)
    {
        $data = $request->validate([
            'hero_title' => ['required', 'string', 'max:80'],
            'hero_subtitle' => ['nullable', 'string', 'max:80'],
            'hero_offer_enabled' => ['nullable', 'boolean'],
            'hero_offer_kicker' => ['nullable', 'string', 'max:30'],
            'hero_offer_value' => ['nullable', 'string', 'max:10'],
            'hero_offer_suffix' => ['nullable', 'string', 'max:6'],
            'hero_offer_off' => ['nullable', 'string', 'max:20'],
            'hero_offer_label' => ['nullable', 'string', 'max:40'],
            'hero_offer_button' => ['nullable', 'string', 'max:30'],
            'hero_offer_link' => ['nullable', 'string', 'max:300'],
            'hero_images' => ['nullable', 'array', 'max:' . self::HERO_IMAGE_SLOTS],
            'hero_images.*' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:3072'],
            'remove_hero_images' => ['nullable', 'array'],
        ]);

        // The card is only worth showing with a figure on it.
        if ($request->boolean('hero_offer_enabled') && ! filled($data['hero_offer_value'] ?? null)) {
            return back()->withInput()->with('error', 'Give the offer a discount value, or turn the offer card off.');
        }

        $values = [
            'hero_offer_enabled' => $request->boolean('hero_offer_enabled'),
            'hero_images' => $this->heroImages($request),
        ];

        foreach (['hero_title', 'hero_subtitle', 'hero_offer_kicker', 'hero_offer_value', 'hero_offer_suffix',
            'hero_offer_off', 'hero_offer_label', 'hero_offer_button', 'hero_offer_link'] as $key) {
            $values[$key] = trim((string) ($data[$key] ?? ''));
        }

        $wasStored = (array) $this->settings->get('hero_images', []);

        [$old, $new] = $this->settings->set($values);

        // Only once the new list is saved, so a failed write cannot leave the
        // hero pointing at files that are already gone.
        foreach (array_diff($wasStored, $values['hero_images']) as $dropped) {
            Storage::disk('public')->delete($dropped);
        }

        if ($new) {
            AuditLogger::log('settings', 'updated', null, 'Hero section updated: ' . implode(', ', array_keys($new)), $old, $new);
        }

        return back()->with('success', $new ? 'Hero section saved.' : 'Nothing changed.');
    }

    /**
     * The hero pictures after this request: each slot keeps what it had unless
     * a new file replaces it or the remove box is ticked. Gaps are closed up so
     * the storefront never fades to an empty slide.
     *
     * @return array<int, string>
     */
    protected function heroImages(Request $request): array
    {
        $current = (array) $this->settings->get('hero_images', []);
        $kept = [];

        for ($slot = 0; $slot < self::HERO_IMAGE_SLOTS; $slot++) {
            $existing = $current[$slot] ?? null;

            if ($file = $request->file('hero_images.' . $slot)) {
                $kept[] = $file->store('settings', 'public');
            } elseif ($existing && ! $request->boolean('remove_hero_images.' . $slot)) {
                $kept[] = $existing;
            }
        }

        return $kept;
    }

    /** The four counters under the categories on the home page. */
    public function stats()
    {
        return view('admin.settings.stats', [
            'settings' => $this->settings->all(),
            'sources' => config('shop.home_stat_sources'),
            'icons' => config('shop.home_stat_icons'),
        ]);
    }

    public function updateStats(Request $request)
    {
        $data = $request->validate([
            'home_stats_enabled' => ['nullable', 'boolean'],
            'stats' => ['required', 'array', 'min:1', 'max:6'],
            'stats.*.label' => ['required', 'string', 'max:40'],
            'stats.*.icon' => ['required', Rule::in(array_keys(config('shop.home_stat_icons')))],
            'stats.*.source' => ['required', Rule::in(array_keys(config('shop.home_stat_sources')))],
            'stats.*.value' => ['nullable', 'string', 'max:20'],
        ]);

        $tiles = [];

        foreach ($data['stats'] as $tile) {
            $value = trim((string) ($tile['value'] ?? ''));

            // A typed tile with nothing typed in it would print an empty box.
            if ($tile['source'] === 'manual' && $value === '') {
                return back()->withInput()->with('error', 'Tile "' . $tile['label'] . '" is set to fixed text, so give it something to show.');
            }

            $tiles[] = [
                'label' => trim($tile['label']),
                'icon' => $tile['icon'],
                'source' => $tile['source'],
                'value' => $value,
            ];
        }

        [$old, $new] = $this->settings->set([
            'home_stats_enabled' => $request->boolean('home_stats_enabled'),
            'home_stats' => $tiles,
        ]);

        if ($new) {
            AuditLogger::log('settings', 'updated', null, 'Stats strip updated: ' . implode(', ', array_keys($new)), $old, $new);
        }

        return back()->with('success', $new ? 'Stats strip saved.' : 'Nothing changed.');
    }
}
