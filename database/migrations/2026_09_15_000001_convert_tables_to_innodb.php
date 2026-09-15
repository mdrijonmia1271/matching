<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The database was created on a MyISAM-default server, so transactions, row
 * locks and foreign keys silently did nothing. ALTER ... ENGINE rebuilds each
 * table in place and keeps every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $tables = DB::select(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'"
        );

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table->name}` ENGINE=InnoDB");
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: going back to MyISAM would drop the foreign keys.
    }
};
