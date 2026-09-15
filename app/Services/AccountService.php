<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Posts money movements to accounts. The single place account balances change. */
class AccountService
{
    /**
     * Append one ledger entry. Callers are responsible for the surrounding
     * transaction and any locking their business rule needs.
     */
    public function post(
        Account $account,
        string $direction,
        float $amount,
        string $type,
        ?Model $reference = null,
        ?string $note = null,
        ?Payment $payment = null,
        ?string $transferGroup = null,
        ?CarbonInterface $at = null,
    ): AccountTransaction {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new InvalidArgumentException('Direction must be "in" or "out".');
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('Amounts must be more than zero.');
        }

        return AccountTransaction::create([
            'account_id' => $account->id,
            'direction' => $direction,
            'amount' => $amount,
            'type' => $type,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'payment_id' => $payment?->id,
            'transfer_group' => $transferGroup,
            'user_id' => Auth::id(),
            'note' => $note ? Str::limit($note, 500, '') : null,
            'transacted_at' => $at ?? now(),
        ]);
    }

    /** @return array<int, float> account id => balance */
    public function balances(): array
    {
        $net = AccountTransaction::selectRaw("account_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS net")
            ->groupBy('account_id')
            ->pluck('net', 'account_id');

        return Account::all()
            ->mapWithKeys(fn (Account $account) => [$account->id => round((float) $account->opening_balance + (float) ($net[$account->id] ?? 0), 2)])
            ->all();
    }

    /** Money put into or taken out of an account outside of sales and purchases. */
    public function entry(Account $account, string $direction, float $amount, string $note): AccountTransaction
    {
        return DB::transaction(function () use ($account, $direction, $amount, $note) {
            $locked = Account::lockForUpdate()->findOrFail($account->id);
            $this->ensureUsable($locked);

            if ($direction === 'out') {
                $this->ensureCovers($locked, $amount);
            }

            $transaction = $this->post($locked, $direction, $amount, $direction === 'in' ? 'deposit' : 'withdrawal', note: $note);

            AuditLogger::log('accounting', $direction === 'in' ? 'money_in' : 'money_out', $transaction,
                sprintf('%s %s %s: %s', Money::format($amount), $direction === 'in' ? 'into' : 'out of', $locked->name, $note));

            return $transaction;
        });
    }

    /** @return array{0: AccountTransaction, 1: AccountTransaction} [out, in] */
    public function transfer(Account $from, Account $to, float $amount, ?string $note = null): array
    {
        if ($from->is($to)) {
            throw new RuntimeException('Choose two different accounts.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $note) {
            // Lock both rows in id order so two opposite transfers cannot deadlock.
            $locked = Account::whereKey([$from->id, $to->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked[$from->id];
            $target = $locked[$to->id];

            $this->ensureUsable($source);
            $this->ensureUsable($target);
            $this->ensureCovers($source, $amount);

            $group = (string) Str::uuid();
            $out = $this->post($source, 'out', $amount, 'transfer_out', note: $note ?: 'Transfer to ' . $target->name, transferGroup: $group);
            $in = $this->post($target, 'in', $amount, 'transfer_in', note: $note ?: 'Transfer from ' . $source->name, transferGroup: $group);

            AuditLogger::log('accounting', 'transfer', $out,
                sprintf('%s moved from %s to %s', Money::format($amount), $source->name, $target->name),
                new: array_filter(['from' => $source->name, 'to' => $target->name, 'amount' => round($amount, 2), 'note' => $note]));

            return [$out, $in];
        });
    }

    protected function ensureUsable(Account $account): void
    {
        if (! $account->is_active) {
            throw new RuntimeException($account->name . ' is inactive. Activate it before recording money against it.');
        }
    }

    protected function ensureCovers(Account $account, float $amount): void
    {
        $balance = $account->balance();

        if (round($amount, 2) > $balance) {
            throw new RuntimeException('Only ' . Money::format($balance) . ' is available in ' . $account->name . '.');
        }
    }
}
