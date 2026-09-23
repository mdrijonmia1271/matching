<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advance orders (bookings): the customer pays something up front and takes the
 * goods later. An advance order is an ordinary order with `channel = advance`,
 * so payments, dues, returns, refunds and reports all keep working — the one
 * difference is that its stock does not leave until it is delivered.
 *
 * `stock_taken_at` makes that difference explicit for every order, instead of
 * being guessed from the channel and status. Online and counter sales take
 * stock the moment they are created, so existing rows are backfilled with their
 * creation time; a cancelled order has already had its stock put back, so it is
 * left null and cannot be restocked twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('expected_at')->nullable()->after('channel');
            $table->timestamp('stock_taken_at')->nullable()->after('delivered_at');
        });

        DB::table('orders')->where('status', '!=', 'cancelled')->update([
            'stock_taken_at' => DB::raw('created_at'),
        ]);

        // Advance orders are a sales job: the roles that already sell get to take them.
        $roles = DB::table('roles')->whereIn('slug', ['manager', 'sales_staff'])->pluck('id');

        foreach ($roles as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission' => 'orders.advance',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'orders.advance')->delete();

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['expected_at', 'stock_taken_at']);
        });
    }
};
