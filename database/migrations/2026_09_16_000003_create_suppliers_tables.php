<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers and money paid to them. What the shop owes a supplier is
 * calculated, never typed: opening due − payments (received purchases join
 * the formula with the purchases step).
 *
 * Adds tables only; no existing data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->index();
            $table->string('company', 150)->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->decimal('opening_due', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 30);
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamps();

            $table->index(['supplier_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('suppliers');
    }
};
