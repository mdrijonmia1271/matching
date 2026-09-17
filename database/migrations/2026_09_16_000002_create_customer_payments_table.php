<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer due collections: one receipt per "collect due" action. The money is
 * applied to the opening due first, then to unpaid orders oldest first; the
 * order parts are ordinary order payments linked back to the receipt.
 *
 * Adds a table and a nullable column only; no existing data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('opening_due_paid', 12, 2)->default(0);
            $table->string('method', 30);
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamps();

            $table->index(['customer_id', 'paid_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('customer_payment_id')->nullable()->after('order_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_payment_id');
        });

        Schema::dropIfExists('customer_payments');
    }
};
