<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks where an order came from, and lets counter sales leave out the details
 * a walk-in customer does not have (email, delivery address).
 *
 * Existing orders all become `online`. No other data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 20)->default('online')->after('customer_id')->index();
            $table->string('customer_email', 191)->nullable()->change();
            $table->text('shipping_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Tightening the columns again only works once nothing is null, so blanks go back first.
        DB::table('orders')->whereNull('customer_email')->update(['customer_email' => '']);
        DB::table('orders')->whereNull('shipping_address')->update(['shipping_address' => '']);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn('channel');
            $table->string('customer_email', 191)->nullable(false)->change();
            $table->text('shipping_address')->nullable(false)->change();
        });
    }
};
