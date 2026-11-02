<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            || ! Schema::hasTable('search_records')
        ) {
            return;
        }

        $indexExists = collect(Schema::getIndexes('search_records'))
            ->contains(fn (array $index): bool => $index['name'] === 'search_records_content_fulltext');

        if ($indexExists) {
            DB::statement('ALTER TABLE search_records DROP INDEX search_records_content_fulltext');
        }
    }

    public function down(): void
    {
        if (
            ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            || ! Schema::hasTable('search_records')
        ) {
            return;
        }

        $indexExists = collect(Schema::getIndexes('search_records'))
            ->contains(fn (array $index): bool => $index['name'] === 'search_records_content_fulltext');

        if (! $indexExists) {
            DB::statement('ALTER TABLE search_records ADD FULLTEXT INDEX search_records_content_fulltext (content)');
        }
    }
};
