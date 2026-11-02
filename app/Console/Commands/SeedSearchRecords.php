<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedSearchRecords extends Command
{
    protected $signature = 'search:seed
        {--count=10000000 : Target total number of records}
        {--append= : Number of random records to add to the existing corpus}
        {--chunk=5000 : Rows per database insert}';

    protected $description = 'Generate realistic search records in bounded database batches';

    private const LOCATIONS = [
        'London', 'Manchester', 'Bristol', 'Oxford', 'Cambridge', 'Leeds',
        'Liverpool', 'Brighton', 'York', 'Bath', 'Reading', 'Norwich',
    ];

    private const STREET_TYPES = [
        'Road', 'Street', 'Lane', 'Avenue', 'Drive', 'Close', 'Gardens',
    ];

    private const PLACE_WORDS = [
        'Market', 'Station', 'Bridge', 'Garden', 'School', 'Square', 'Park',
        'Church', 'Library', 'Mill', 'Hill', 'Green',
    ];

    public function handle(): int
    {
        $count = filter_var($this->option('count'), FILTER_VALIDATE_INT);
        $appendOption = $this->option('append');
        $append = $appendOption === null ? null : filter_var($appendOption, FILTER_VALIDATE_INT);
        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);

        if (
            $count === false || $count < 1
            || ($appendOption !== null && ($append === false || $append < 1))
            || $chunkSize === false || $chunkSize < 1 || $chunkSize > 50000
        ) {
            $this->error('Count and append must be positive, and chunk size must be between 1 and 50000.');

            return self::FAILURE;
        }

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->error('Bulk generation for the demo requires MySQL, MariaDB, or PostgreSQL.');

            return self::FAILURE;
        }

        $existingCount = DB::table('search_records')->count();
        $remaining = $append ?? max(0, $count - $existingCount);

        if ($remaining === 0) {
            $this->info('The search corpus already contains at least '.number_format($count).' records.');

            return self::SUCCESS;
        }

        $driver = DB::connection()->getDriverName();
        $indexExists = $driver === 'pgsql'
            && collect(Schema::getIndexes('search_records'))
                ->contains(fn (array $index): bool => $index['name'] === 'search_records_content_trgm_idx');

        if ($indexExists) {
            DB::statement('DROP INDEX search_records_content_trgm_idx');
            $this->warn('The PostgreSQL trigram index is temporarily offline while the bulk load runs.');
        } elseif ($driver === 'pgsql') {
            $this->warn('The PostgreSQL trigram index is missing and will be rebuilt after the bulk load.');
        }

        $this->warn('Existing rows are preserved. Ensure sufficient free disk space before starting.');

        try {
            $this->output->progressStart($remaining);
            $includeDemoRecord = $existingCount === 0 && $append === null;

            while ($remaining > 0) {
                $batchSize = min($chunkSize, $remaining);
                $batch = [];

                for ($index = 0; $index < $batchSize; $index++) {
                    $batch[] = [
                        'content' => $includeDemoRecord
                            ? '25 London Road Market Park'
                            : $this->makeContent(),
                    ];
                    $includeDemoRecord = false;
                }

                DB::table('search_records')->insert($batch);
                $this->output->progressAdvance($batchSize);
                $remaining -= $batchSize;
            }

            $this->output->progressFinish();
        } finally {
            $indexExists = $driver === 'pgsql'
                && collect(Schema::getIndexes('search_records'))
                    ->contains(fn (array $index): bool => $index['name'] === 'search_records_content_trgm_idx');

            if ($driver === 'pgsql' && ! $indexExists) {
                DB::statement('CREATE INDEX search_records_content_trgm_idx ON search_records USING GIN (content gin_trgm_ops)');
            }
        }

        $total = DB::table('search_records')->count();
        $this->info(number_format($total).' search records are available.');

        return self::SUCCESS;
    }

    private function makeContent(): string
    {
        return implode(' ', [
            mt_rand(1, 999),
            self::LOCATIONS[mt_rand(0, count(self::LOCATIONS) - 1)],
            self::STREET_TYPES[mt_rand(0, count(self::STREET_TYPES) - 1)],
            self::PLACE_WORDS[mt_rand(0, count(self::PLACE_WORDS) - 1)],
        ]);
    }
}
