<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the foreign keys that MyISAM never stored, and replaces cascades that
 * would erase business history (payments, stock ledger, products in a category)
 * with RESTRICT so such deletes fail loudly instead.
 *
 * Existing rows were checked for orphans before this migration was written.
 */
return new class extends Migration
{
    /** [table, column, referenced table, on delete] */
    protected array $keys = [
        ['products', 'category_id', 'categories', 'restrict'],
        ['product_images', 'product_id', 'products', 'cascade'],
        ['carts', 'user_id', 'users', 'cascade'],
        ['cart_items', 'cart_id', 'carts', 'cascade'],
        ['cart_items', 'product_id', 'products', 'cascade'],
        ['orders', 'user_id', 'users', 'set null'],
        ['order_items', 'order_id', 'orders', 'cascade'],
        ['order_items', 'product_id', 'products', 'set null'],
        ['payments', 'order_id', 'orders', 'restrict'],
        ['reviews', 'product_id', 'products', 'cascade'],
        ['reviews', 'user_id', 'users', 'cascade'],
        ['wishlists', 'user_id', 'users', 'cascade'],
        ['wishlists', 'product_id', 'products', 'cascade'],
        ['stock_movements', 'product_id', 'products', 'restrict'],
        ['stock_movements', 'user_id', 'users', 'set null'],
        ['stock_movements', 'order_id', 'orders', 'set null'],
    ];

    public function up(): void
    {
        // SQLite (the test database) gets its keys from the original migrations.
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->keys as [$table, $column, $references, $onDelete]) {
            $existing = $this->foreignKeyName($table, $column);

            Schema::table($table, function (Blueprint $blueprint) use ($existing, $column, $references, $onDelete) {
                if ($existing) {
                    $blueprint->dropForeign($existing);
                }

                $blueprint->foreign($column)->references('id')->on($references)->onDelete($onDelete);
            });
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->keys as [$table, $column]) {
            if ($existing = $this->foreignKeyName($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($existing));
            }
        }
    }

    protected function foreignKeyName(string $table, string $column): ?string
    {
        return DB::selectOne(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        )?->name;
    }
};
