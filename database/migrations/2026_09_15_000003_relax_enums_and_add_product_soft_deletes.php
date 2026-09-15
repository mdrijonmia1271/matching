<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - ENUM columns become strings (validated in PHP) so new order, payment and
 *   stock statuses don't need an ALTER TABLE each time. Existing values are kept.
 * - Stock columns become signed so "allow negative stock" can be a setting.
 * - Products get soft deletes: they are referenced by orders and the stock ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();
            $table->string('payment_status', 30)->default('unpaid')->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->string('type', 20)->default('percent')->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('type', 10)->change();
            $table->integer('stock_before')->change();
            $table->integer('stock_after')->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->integer('stock')->default(0)->change();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        // Column types are left as strings: narrowing back to ENUM could reject newer status values.
    }
};
