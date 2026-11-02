<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BenchmarkSearchApi extends Command
{
    protected $signature = 'search:benchmark
        {query=London Road : Search phrase to request}
        {--iterations=100 : Number of measured requests}
        {--warmup=10 : Number of unmeasured warmup requests}
        {--url= : Search endpoint URL}
        {--max-ms=50 : Maximum acceptable response time in milliseconds}';

    protected $description = 'Measure end-to-end autocomplete API latency against the configured database';

    public function handle(): int
    {
        $iterations = filter_var($this->option('iterations'), FILTER_VALIDATE_INT);
        $warmup = filter_var($this->option('warmup'), FILTER_VALIDATE_INT);
        $maxMilliseconds = filter_var($this->option('max-ms'), FILTER_VALIDATE_FLOAT);

        if ($iterations === false || $iterations < 1 || $warmup === false || $warmup < 0
            || $maxMilliseconds === false || $maxMilliseconds <= 0) {
            $this->error('Iterations must be positive; warmup must be non-negative; max-ms must be positive.');

            return self::FAILURE;
        }

        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/api/search';
        $query = (string) $this->argument('query');
        $request = Http::timeout(5)->setClient(new Client);

        try {
            for ($index = 0; $index < $warmup; $index++) {
                $response = $request->get($url, ['q' => $query]);

                if (! $response->successful()) {
                    $this->error("Warmup request failed with HTTP {$response->status()}.");

                    return self::FAILURE;
                }
            }

            $durations = [];

            for ($index = 0; $index < $iterations; $index++) {
                $startedAt = hrtime(true);
                $response = $request->get($url, ['q' => $query]);
                $duration = (hrtime(true) - $startedAt) / 1_000_000;

                if (! $response->successful()) {
                    $this->error("Benchmark request failed with HTTP {$response->status()}.");

                    return self::FAILURE;
                }

                $durations[] = $duration;
            }
        } catch (ConnectionException $exception) {
            $this->error('Could not reach the running API: '.$exception->getMessage());

            return self::FAILURE;
        }

        sort($durations);
        $p50 = $this->percentile($durations, 0.50);
        $p95 = $this->percentile($durations, 0.95);
        $maximum = max($durations);

        $this->table(
            ['Metric', 'Latency'],
            [
                ['Requests', (string) $iterations],
                ['p50', number_format($p50, 2).' ms'],
                ['p95', number_format($p95, 2).' ms'],
                ['max', number_format($maximum, 2).' ms'],
                ['max target', number_format($maxMilliseconds, 2).' ms'],
            ],
        );

        if ($maximum > $maxMilliseconds) {
            $this->error('At least one response exceeded the maximum response-time target.');

            return self::FAILURE;
        }

        $this->info('Every measured response met the configured maximum response time.');

        return self::SUCCESS;
    }

    private function percentile(array $sortedValues, float $percentile): float
    {
        $index = (int) ceil(count($sortedValues) * $percentile) - 1;

        return $sortedValues[max(0, $index)];
    }
}
