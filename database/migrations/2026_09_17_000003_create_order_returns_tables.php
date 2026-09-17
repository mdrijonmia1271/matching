<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods coming back from a customer.
 *
 * A return records goods, never money: what to refund is Step 14's job, which
 * is why `refund_total` is only the value of the goods that came back.
 *
 * The table is `order_returns` rather than `returns`, because RETURNS is a
 * reserved word in MySQL and would need quoting everywhere.
 *
 * Adds tables only; no existing data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('requested')->index();
            $table->string('reason', 60);
            $table->string('note', 500)->nullable();
            // What the returned goods were sold for. The money itself moves with a refund.
            $table->decimal('refund_total', 12, 2)->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            // restock = back on the shelf; damaged = written off, both legs kept in the stock ledger.
            $table->string('condition', 20)->default('restock');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['order_return_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('order_returns');
    }
};
