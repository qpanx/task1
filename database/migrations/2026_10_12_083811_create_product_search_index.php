<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Product search requires SQLite with FTS5 enabled.');
        }

        DB::statement(<<<'SQL'
            CREATE VIRTUAL TABLE products_fts USING fts5(
                name,
                description,
                content='products',
                content_rowid='id',
                tokenize='unicode61 remove_diacritics 2'
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER products_fts_insert AFTER INSERT ON products BEGIN
                INSERT INTO products_fts(rowid, name, description)
                VALUES (new.id, new.name, new.description);
            END;

            CREATE TRIGGER products_fts_delete AFTER DELETE ON products BEGIN
                INSERT INTO products_fts(products_fts, rowid, name, description)
                VALUES ('delete', old.id, old.name, old.description);
            END;

            CREATE TRIGGER products_fts_update AFTER UPDATE OF id, name, description ON products BEGIN
                INSERT INTO products_fts(products_fts, rowid, name, description)
                VALUES ('delete', old.id, old.name, old.description);
                INSERT INTO products_fts(rowid, name, description)
                VALUES (new.id, new.name, new.description);
            END;
            SQL);

        DB::statement("INSERT INTO products_fts(products_fts) VALUES ('rebuild')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS products_fts_insert;
            DROP TRIGGER IF EXISTS products_fts_delete;
            DROP TRIGGER IF EXISTS products_fts_update;
            DROP TABLE IF EXISTS products_fts;
            SQL);
    }
};
