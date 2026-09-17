<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock bought from suppliers. A purchase is written while it is a draft or on
 * order, and only "receive" moves stock and adds to what the supplier is owed.
 *
 * Adds tables only; no existing data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->date('purchase_date');
            $table->string('invoice_number', 100)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            // Transport, labour and anything else that makes the goods cost more than their price.
            $table->decimal('additional_cost', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index('purchase_date');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            // Items belong to their purchase: editing a draft replaces them.
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 12, 2);
            // Unit cost plus this line's share of the discount and additional cost.
            $table->decimal('landed_unit_cost', 12, 2)->nullable();
            $table->timestamps();

            $table->index(['purchase_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
