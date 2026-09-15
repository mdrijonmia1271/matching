<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money accounts (Cash, Bank, bKash, Nagad) and an append-only ledger of every
 * amount moving in or out of them. Order payments now carry an amount, method
 * and receiving account, and orders keep a paid amount derived from them.
 *
 * Backfill (approved by the owner): orders already marked paid without a
 * payment record get one successful payment for their total, dated when they
 * were paid, posted to Cash (cash on delivery) or Bank (online), and every
 * successful payment gets a matching ledger entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('code', 40)->unique();
            $table->string('type', 20);
            $table->string('account_number', 60)->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 30)->nullable()->after('gateway');
            $table->foreignId('account_id')->nullable()->after('method')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('received_by');
            $table->string('note', 500)->nullable()->after('paid_at');
        });

        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('direction', 3);
            $table->decimal('amount', 14, 2);
            $table->string('type', 30);
            $table->nullableMorphs('reference');
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('transfer_group')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('transacted_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'transacted_at']);
            $table->index(['type', 'transacted_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('paid_amount', 12, 2)->default(0)->after('total');
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('paid_amount');
        });

        $now = now();
        $ids = [];

        foreach ([
            ['cash', 'Cash', 'cash'],
            ['bank', 'Bank', 'bank'],
            ['bkash', 'bKash', 'mobile_wallet'],
            ['nagad', 'Nagad', 'mobile_wallet'],
        ] as $order => [$code, $name, $type]) {
            $ids[$code] = DB::table('accounts')->insertGetId([
                'name' => $name, 'code' => $code, 'type' => $type, 'opening_balance' => 0,
                'is_active' => true, 'sort_order' => $order + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->backfillPayments($ids['cash'], $ids['bank']);
    }

    protected function backfillPayments(int $cashId, int $bankId): void
    {
        $now = now();

        DB::table('orders')->orderBy('id')->each(function (object $order) use ($cashId, $bankId, $now) {
            $successful = DB::table('payments')->where('order_id', $order->id)->where('status', 'success')->get();

            if ($successful->isEmpty() && $order->payment_status === 'paid') {
                $paidAt = $order->paid_at ?? $order->updated_at;
                $isCash = $order->payment_method === 'cod';

                DB::table('payments')->insert([
                    'order_id' => $order->id,
                    'gateway' => 'manual',
                    'method' => $isCash ? 'cash' : 'online',
                    'account_id' => $isCash ? $cashId : $bankId,
                    'amount' => $order->total,
                    'status' => 'success',
                    'paid_at' => $paidAt,
                    'note' => 'Recorded when accounts were introduced (order was already marked paid).',
                    'created_at' => $paidAt,
                    'updated_at' => $now,
                ]);

                $successful = DB::table('payments')->where('order_id', $order->id)->where('status', 'success')->get();
            }

            $paid = 0.0;

            foreach ($successful as $payment) {
                $online = $payment->gateway === 'online' || $payment->method === 'online';
                $accountId = $payment->account_id ?? ($online ? $bankId : $cashId);
                $at = $payment->paid_at ?? $payment->updated_at ?? $now;

                DB::table('payments')->where('id', $payment->id)->update([
                    'account_id' => $accountId,
                    'method' => $payment->method ?? ($online ? 'online' : 'cash'),
                    'paid_at' => $at,
                ]);

                DB::table('account_transactions')->insert([
                    'account_id' => $accountId,
                    'direction' => 'in',
                    'amount' => $payment->amount,
                    'type' => 'sale_payment',
                    'reference_type' => \App\Models\Order::class,
                    'reference_id' => $order->id,
                    'payment_id' => $payment->id,
                    'note' => 'Payment for order ' . $order->order_number,
                    'transacted_at' => $at,
                    'created_at' => $now,
                ]);

                $paid += (float) $payment->amount;
            }

            DB::table('orders')->where('id', $order->id)->update(['paid_amount' => round($paid, 2)]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'refunded_amount']);
        });

        Schema::dropIfExists('account_transactions');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['method', 'paid_at', 'note']);
        });

        Schema::dropIfExists('accounts');
    }
};
