<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand logos, shown in the home page brand strip.
 *
 * Nullable in the database because brands created before this migration have
 * no logo yet; the admin form requires one, so every brand gets a logo the
 * next time it is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('logo');
        });
    }
};
