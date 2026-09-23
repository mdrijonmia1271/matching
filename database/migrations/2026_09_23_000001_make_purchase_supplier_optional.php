<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A purchase may be bought without a supplier (e.g. cash from the market).
 * Such a purchase bills nobody; money paid for it is posted straight to the
 * ledger. Existing purchases all keep their supplier; no data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->change();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('purchases')->whereNull('supplier_id')->exists()) {
            throw new RuntimeException('Some purchases have no supplier. Give them one before rolling this back.');
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable(false)->change();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });
    }
};
