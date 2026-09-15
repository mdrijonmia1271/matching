<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app timezone changed from UTC to Asia/Dhaka (UTC+6, no daylight saving).
 * Laravel stores local wall-clock times, so every existing timestamp is moved
 * forward 6 hours to keep showing the moment it really happened.
 *
 * Reversible: down() moves them back. All date columns of a table are updated
 * in one statement so no ON UPDATE column can overwrite another.
 */
return new class extends Migration
{
    protected const HOURS = 6;

    public function up(): void
    {
        $this->shift('DATE_ADD');
    }

    public function down(): void
    {
        $this->shift('DATE_SUB');
    }

    protected function shift(string $function): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $columns = collect(DB::select(
            "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('timestamp', 'datetime')"
        ))->groupBy('table_name');

        foreach ($columns as $table => $tableColumns) {
            $assignments = $tableColumns
                ->map(fn ($column) => sprintf('`%1$s` = %2$s(`%1$s`, INTERVAL %3$d HOUR)', $column->column_name, $function, self::HOURS))
                ->implode(', ');

            // NULL stays NULL: DATE_ADD(NULL, ...) is NULL.
            DB::statement("UPDATE `{$table}` SET {$assignments}");
        }
    }
};
