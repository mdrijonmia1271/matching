<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product variants (size / colour), brands and subcategories.
 *
 * Existing data is preserved:
 * - every existing product gets one "default" variant carrying its current SKU and stock;
 * - cart items, order items and stock movements are pointed at that variant;
 * - products whose stock was set before the stock ledger existed get an
 *   "opening balance" movement so ledger totals match the stock on hand.
 * Nothing is deleted or overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('categories')->restrictOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('subcategory_id')->nullable()->after('category_id')->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->after('subcategory_id')->constrained()->nullOnDelete();
            $table->decimal('cost_price', 12, 2)->nullable()->after('description');
            $table->json('tags')->nullable()->after('image');
            $table->boolean('is_new_arrival')->default(false)->after('is_featured');
            $table->boolean('has_variants')->default(false)->after('is_new_arrival');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 80)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('size', 40)->nullable();
            $table->string('color', 40)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'is_active']);
            $table->index('stock');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->cascadeOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            $table->string('sku', 80)->nullable()->after('product_name');
            $table->string('variant_label', 100)->nullable()->after('sku');
            $table->decimal('unit_cost', 12, 2)->nullable()->after('price');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('unit_cost', 12, 2)->nullable()->after('quantity');
            $table->nullableMorphs('reference');
        });

        $this->backfillDefaultVariants();

        // One line per variant in a cart (was one line per product).
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['cart_id', 'variant_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'product_id']);
        });
    }

    protected function backfillDefaultVariants(): void
    {
        $now = now();

        DB::table('products')->orderBy('id')->each(function (object $product) use ($now) {
            $variantId = DB::table('product_variants')->insertGetId([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'stock' => (int) $product->stock,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => $product->created_at ?? $now,
                'updated_at' => $now,
                'deleted_at' => $product->deleted_at,
            ]);

            DB::table('cart_items')->where('product_id', $product->id)->update(['variant_id' => $variantId]);
            DB::table('order_items')->where('product_id', $product->id)->update(['variant_id' => $variantId, 'sku' => $product->sku]);
            DB::table('stock_movements')->where('product_id', $product->id)->update(['variant_id' => $variantId]);

            $in = (int) DB::table('stock_movements')->where('variant_id', $variantId)->where('type', 'in')->sum('quantity');
            $out = (int) DB::table('stock_movements')->where('variant_id', $variantId)->where('type', 'out')->sum('quantity');
            $difference = (int) $product->stock - ($in - $out);

            if ($difference !== 0) {
                DB::table('stock_movements')->insert([
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'type' => $difference > 0 ? 'in' : 'out',
                    'reason' => 'opening',
                    'quantity' => abs($difference),
                    'stock_before' => (int) $product->stock - $difference,
                    'stock_after' => (int) $product->stock,
                    'note' => 'Opening balance: stock that was recorded before the stock ledger existed.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['cart_id', 'product_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'variant_id']);
            $table->dropConstrainedForeignId('variant_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
            $table->dropMorphs('reference');
            $table->dropColumn('unit_cost');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
            $table->dropColumn(['sku', 'variant_label', 'unit_cost']);
        });

        Schema::dropIfExists('product_variants');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subcategory_id');
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['cost_price', 'tags', 'is_new_arrival', 'has_variants']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });

        Schema::dropIfExists('brands');
    }
};
