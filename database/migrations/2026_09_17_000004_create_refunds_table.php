<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money given back to a customer.
 *
 * The counterpart to a return: a return records goods, a refund records money.
 * A refund may be linked to the return that caused it, but does not have to be
 * — money can also go back on a cancelled order where no goods ever moved.
 *
 * `orders.refunded_amount` already exists (Step 7); this only adds the rows
 * behind it. Adds a table only; no existing data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 30);
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->useCurrent();
            $table->timestamps();

            $table->index(['order_id', 'refunded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
