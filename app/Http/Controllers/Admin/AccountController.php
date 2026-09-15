<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Services\AccountService;
use App\Services\AuditLogger;
use App\Support\CsvExport;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class AccountController extends Controller implements HasMiddleware
{
    public function __construct(protected AccountService $accounts) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounting.view', only: ['index', 'show', 'export']),
            new Middleware('can:reports.export', only: ['export']),
            new Middleware('can:accounting.edit', only: ['store', 'edit', 'update']),
            new Middleware('can:accounting.create', only: ['storeEntry', 'storeTransfer']),
        ];
    }

    public function index()
    {
        $accounts = Account::orderBy('sort_order')->orderBy('name')->get();
        $balances = $this->accounts->balances();

        return view('admin.accounts.index', [
            'accounts' => $accounts,
            'balances' => $balances,
            'total' => round($accounts->where('is_active', true)->sum(fn (Account $account) => $balances[$account->id] ?? 0), 2),
            'recent' => AccountTransaction::with(['account:id,name', 'user:id,name', 'reference'])
                ->orderByDesc('transacted_at')->orderByDesc('id')->take(15)->get(),
            'types' => Account::TYPES,
        ]);
    }

    public function show(Request $request, Account $account)
    {
        $query = $this->transactions($request, $account);

        $totals = (clone $query)->reorder()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) AS money_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) AS money_out")
            ->toBase()
            ->first();

        return view('admin.accounts.show', [
            'account' => $account,
            'balance' => $account->balance(),
            'transactions' => $query->with(['user:id,name', 'reference'])->paginate(25)->withQueryString(),
            'moneyIn' => (float) $totals->money_in,
            'moneyOut' => (float) $totals->money_out,
            'types' => AccountTransaction::TYPES,
        ]);
    }

    public function export(Request $request, Account $account)
    {
        $rows = (function () use ($request, $account) {
            foreach ($this->transactions($request, $account)->with(['user:id,name', 'reference'])->lazy(500) as $transaction) {
                yield [
                    $transaction->transacted_at?->format('Y-m-d H:i'),
                    $transaction->type_label,
                    $transaction->direction === 'in' ? (float) $transaction->amount : null,
                    $transaction->direction === 'out' ? (float) $transaction->amount : null,
                    $transaction->reference?->order_number ?? '',
                    (string) $transaction->note,
                    (string) $transaction->user?->name,
                ];
            }
        })();

        return CsvExport::download(Str::slug($account->name) . '-transactions-' . now()->format('Y-m-d-His') . '.csv',
            ['Date', 'Type', 'Money in', 'Money out', 'Order', 'Note', 'By'], $rows);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:accounts,name'],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'account_number' => ['nullable', 'string', 'max:60'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        $base = Str::slug($data['name'], '_') ?: 'account';
        $code = $base;
        $i = 2;

        while (Account::where('code', $code)->exists()) {
            $code = $base . '_' . $i++;
        }

        $account = Account::create($data + [
            'code' => $code,
            'opening_balance' => $data['opening_balance'] ?? 0,
            'is_active' => true,
            'sort_order' => (int) Account::max('sort_order') + 1,
        ]);

        AuditLogger::log('accounting', 'account_created', $account, 'Account ' . $account->name . ' created',
            new: $account->only(['name', 'type', 'opening_balance']));

        return back()->with('success', 'Account ' . $account->name . ' added.');
    }

    public function edit(Account $account)
    {
        return view('admin.accounts.edit', ['account' => $account, 'types' => Account::TYPES]);
    }

    public function update(Request $request, Account $account)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('accounts', 'name')->ignore($account->id)],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'account_number' => ['nullable', 'string', 'max:60'],
            'opening_balance' => ['required', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        if (! $data['is_active'] && $account->code === Settings::get('online_payment_account')) {
            return back()->withInput()->with('error', $account->name . ' receives online payments. Choose another account in Settings before deactivating it.');
        }

        $account->fill($data);
        [$old, $new] = AuditLogger::changes($account);
        $account->save();

        if ($new) {
            AuditLogger::log('accounting', 'account_updated', $account, 'Account ' . $account->name . ' updated', $old, $new);
        }

        return redirect()->route('admin.accounts.show', $account)->with('success', 'Account updated.');
    }

    public function storeEntry(Request $request)
    {
        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000'],
            'note' => ['required', 'string', 'max:300'],
        ], [
            'note.required' => 'Write what the money is for, so the entry can be understood later.',
        ]);

        try {
            $this->accounts->entry(Account::findOrFail($data['account_id']), $data['direction'], (float) $data['amount'], $data['note']);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', Money::format($data['amount']) . ' recorded as money ' . $data['direction'] . '.');
    }

    public function storeTransfer(Request $request)
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'exists:accounts,id'],
            'to_account_id' => ['required', 'exists:accounts,id', 'different:from_account_id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            'to_account_id.different' => 'Choose two different accounts.',
        ]);

        try {
            $this->accounts->transfer(
                Account::findOrFail($data['from_account_id']),
                Account::findOrFail($data['to_account_id']),
                (float) $data['amount'],
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfer of ' . Money::format($data['amount']) . ' recorded.');
    }

    protected function transactions(Request $request, Account $account): Builder
    {
        return AccountTransaction::where('account_id', $account->id)
            ->when(array_key_exists((string) $request->input('type'), AccountTransaction::TYPES), fn ($q) => $q->where('type', $request->input('type')))
            ->when(in_array($request->input('direction'), ['in', 'out'], true), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('q'), fn ($q) => $q->where('note', 'like', '%' . trim((string) $request->input('q')) . '%'))
            ->when($request->filled('from'), fn ($q) => $q->where('transacted_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('transacted_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('transacted_at')
            ->orderByDesc('id');
    }
}
