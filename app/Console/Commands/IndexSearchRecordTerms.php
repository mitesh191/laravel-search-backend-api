<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IndexSearchRecordTerms extends Command
{
    protected $signature = 'search:index-terms
        {--chunk=50000 : Records to index per database statement}
        {--fast-generated : Use fast whitespace tokenization for search:seed records}';

    protected $description = 'Build the indexed word-prefix lookup used by MySQL autocomplete';

    public function handle(): int
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('The MySQL term-prefix index is only needed for MySQL and MariaDB.');

            return self::FAILURE;
        }

        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);

        if ($chunkSize === false || $chunkSize < 1 || $chunkSize > 100000) {
            $this->error('Chunk size must be between 1 and 100000.');

            return self::FAILURE;
        }

        $fastGenerated = (bool) $this->option('fast-generated');
        $termExpression = $fastGenerated
            ? "SUBSTRING_INDEX(SUBSTRING_INDEX(records.content, ' ', positions.position), ' ', -1)"
            : "REGEXP_SUBSTR(records.content, '[[:alnum:]]+', 1, positions.position)";
        $positionFilter = $fastGenerated
            ? "AND positions.position <= CHAR_LENGTH(records.content) - CHAR_LENGTH(REPLACE(records.content, ' ', '')) + 1"
            : '';

        $records = DB::table('search_records');
        $lastId = (int) $records->max('id');
        $recordCount = (int) $records->count();

        if ($lastId === 0) {
            $this->info('There are no search records to index.');

            return self::SUCCESS;
        }

        $this->info('Building indexed terms in committed batches...');
        $this->output->progressStart($recordCount);
        $lastProcessedId = 0;

        while ($lastProcessedId < $lastId) {
            $recordIds = DB::table('search_records')
                ->where('id', '>', $lastProcessedId)
                ->where('id', '<=', $lastId)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($recordIds->isEmpty()) {
                break;
            }

            $startId = (int) $recordIds->first();
            $endId = (int) $recordIds->last();

            $sql = str_replace(
                ['{{ term_expression }}', '{{ position_filter }}'],
                [$termExpression, $positionFilter],
                <<<'SQL'
                INSERT IGNORE INTO search_record_terms (term, search_record_id)
                SELECT LOWER(parsed.term), parsed.id
                FROM (
                    SELECT
                        records.id,
                        {{ term_expression }} AS term
                    FROM search_records AS records
                    CROSS JOIN (
                        SELECT 1 AS position
                        UNION ALL SELECT 2
                        UNION ALL SELECT 3
                        UNION ALL SELECT 4
                        UNION ALL SELECT 5
                    ) AS positions
                    WHERE records.id BETWEEN ? AND ?
                        AND NOT EXISTS (
                            SELECT 1
                            FROM search_record_terms AS indexed_terms
                            WHERE indexed_terms.search_record_id = records.id
                        )
                        {{ position_filter }}
                ) AS parsed
                WHERE parsed.term IS NOT NULL
                    AND CHAR_LENGTH(parsed.term) >= 3
                    AND CHAR_LENGTH(parsed.term) <= 100
                SQL,
            );

            DB::statement($sql, [$startId, $endId]);

            $this->output->progressAdvance($recordIds->count());
            $lastProcessedId = $endId;
        }

        $this->output->progressFinish();

        $indexedRecords = DB::table('search_record_terms')->distinct()->count('search_record_id');
        $this->info(number_format($indexedRecords).' records have indexed search terms.');

        return self::SUCCESS;
    }
}
