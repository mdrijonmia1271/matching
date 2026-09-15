<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order fulfilment tracking: a status history per order, courier and tracking
 * details, an internal admin note and the time each milestone was reached.
 *
 * Existing orders get their history backfilled from what is known: placed at
 * created_at, and (if no longer pending) moved to their current status at updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->text('admin_note')->nullable()->after('note');
            $table->string('courier_name', 80)->nullable()->after('admin_note');
            $table->string('tracking_number', 100)->nullable()->after('courier_name');
            $table->timestamp('confirmed_at')->nullable()->after('paid_at');
            $table->timestamp('shipped_at')->nullable()->after('confirmed_at');
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');
            $table->timestamp('cancelled_at')->nullable()->after('delivered_at');

            $table->index('tracking_number');
        });

        DB::table('orders')->orderBy('id')->each(function (object $order) {
            $rows = [[
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => 'pending',
                'note' => 'Order placed',
                'created_at' => $order->created_at,
            ]];

            if ($order->status !== 'pending') {
                $rows[] = [
                    'order_id' => $order->id,
                    'from_status' => 'pending',
                    'to_status' => $order->status,
                    'note' => 'Recorded when order history tracking was introduced',
                    'created_at' => $order->updated_at ?? $order->created_at,
                ];
            }

            DB::table('order_status_histories')->insert($rows);
        });

        DB::table('orders')->where('status', 'delivered')->whereNull('delivered_at')->update(['delivered_at' => DB::raw('updated_at')]);
        DB::table('orders')->where('status', 'cancelled')->whereNull('cancelled_at')->update(['cancelled_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tracking_number']);
            $table->dropColumn(['admin_note', 'courier_name', 'tracking_number', 'confirmed_at', 'shipped_at', 'delivered_at', 'cancelled_at']);
        });

        Schema::dropIfExists('order_status_histories');
    }
};
