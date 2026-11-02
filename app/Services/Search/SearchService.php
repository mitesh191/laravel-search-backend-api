<?php

namespace App\Services\Search;

use App\Models\SearchRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SearchService
{
    public function suggestions(string $input, int $limit = 8): Collection
    {
        $normalized = mb_strtolower(trim($input));

        if (mb_strlen($normalized) < 3) {
            return new Collection;
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $matches);
        $terms = array_values(array_unique($matches[0]));

        if ($terms === [] || count($terms) > 5) {
            return new Collection;
        }

        $indexedTerms = array_values(array_filter(
            $terms,
            fn (string $term): bool => mb_strlen($term) >= 3,
        ));
        $driver = DB::connection()->getDriverName();

        if ($indexedTerms === [] && $driver !== 'pgsql') {
            return new Collection;
        }

        return match ($driver) {
            'mysql', 'mariadb' => $this->searchMySql($indexedTerms, $limit),
            'pgsql' => $this->searchPostgres($normalized, $indexedTerms, $limit),
            'sqlite' => $this->searchSqlite($indexedTerms, $limit),
            default => throw new RuntimeException('Search requires MySQL, MariaDB, or PostgreSQL.'),
        };
    }

    private function searchMySql(array $terms, int $limit): Collection
    {
        if ($terms === []) {
            return new Collection;
        }

        $limit = min(max($limit, 1), 8);
        usort($terms, fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        $query = SearchRecord::query()
            ->select(['search_records.id', 'search_records.content'])
            ->join(
                'search_record_terms as candidate_terms',
                'candidate_terms.search_record_id',
                '=',
                'search_records.id',
            )
            ->where('candidate_terms.term', 'like', $terms[0].'%');

        foreach (array_slice($terms, 1) as $index => $term) {
            $alias = 'matched_terms_'.$index;

            $query->whereExists(function ($query) use ($alias, $term): void {
                $query->selectRaw('1')
                    ->from('search_record_terms as '.$alias)
                    ->whereColumn($alias.'.search_record_id', 'candidate_terms.search_record_id')
                    ->where($alias.'.term', 'like', $term.'%');
            });
        }

        $suggestions = $query
            ->orderBy('candidate_terms.term')
            ->orderBy('candidate_terms.search_record_id')
            ->limit($limit * 5)
            ->get();

        $suggestions = $suggestions->unique('id')->take($limit)->values();

        return $suggestions;
    }

    private function searchPostgres(string $normalized, array $terms, int $limit): Collection
    {
        $query = SearchRecord::query()
            ->select(['id', 'content'])
            ->where(function ($query) use ($normalized, $terms): void {
                if ($terms !== []) {
                    $query->where(function ($query) use ($terms): void {
                        foreach ($terms as $term) {
                            $query->whereRaw('content ILIKE ?', ['%'.$term.'%']);
                        }
                    })->orWhereRaw('? <% content', [$normalized]);
                } else {
                    $query->whereRaw('? <% content', [$normalized]);
                }
            });

        if ($terms === []) {
            $query->selectRaw('0 AS prefix_match');
        } else {
            $prefixMatches = implode(' AND ', array_fill(0, count($terms), 'content ILIKE ?'));
            $query->selectRaw(
                "CASE WHEN {$prefixMatches} THEN 1 ELSE 0 END AS prefix_match",
                array_map(fn (string $term): string => '%'.$term.'%', $terms),
            );
        }

        return $query
            ->orderByDesc('prefix_match')
            ->orderByRaw('word_similarity(?, content) DESC', [$normalized])
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function searchSqlite(array $terms, int $limit): Collection
    {
        return $this->searchByContent($terms, $limit);
    }

    private function searchByContent(array $terms, int $limit): Collection
    {
        if ($terms === []) {
            return new Collection;
        }

        return SearchRecord::query()
            ->select(['id', 'content'])
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->where('content', 'like', '%'.$term.'%');
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
