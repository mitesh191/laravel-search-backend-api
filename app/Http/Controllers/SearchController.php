<?php

namespace App\Http\Controllers;

use App\Models\SearchRecord;
use App\Services\Search\SearchSelectionToken;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function index(Request $request, SearchService $search, SearchSelectionToken $tokens): JsonResponse
    {
        $startedAt = hrtime(true);
        $validated = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = trim($validated['q'] ?? '');

        $suggestions = $search->suggestions($query)->map(fn (SearchRecord $record): array => [
            'id' => $record->id,
            'content' => $record->content,
            'selection_token' => $tokens->issue($record->id),
        ]);

        $response = response()->json(['data' => $suggestions]);
        $duration = (hrtime(true) - $startedAt) / 1_000_000;

        return $response->header('Server-Timing', 'search;dur='.number_format($duration, 2, '.', ''));
    }

    public function select(Request $request, SearchSelectionToken $tokens): JsonResponse
    {
        $validated = $request->validate([
            'selection_token' => ['required', 'string', 'max:2048'],
        ]);
        $claims = $tokens->verify($validated['selection_token']);

        if ($claims === null) {
            return response()->json(['message' => 'The selection token is invalid or expired.'], 422);
        }

        if (! SearchRecord::query()->whereKey($claims['record_id'])->exists()) {
            return response()->json(['message' => 'The selected suggestion no longer exists.'], 404);
        }

        $tokenHash = hash('sha256', $validated['selection_token']);

        $counted = DB::transaction(function () use ($claims, $tokenHash): bool {
            $inserted = DB::table('search_usage_events')->insertOrIgnore([
                'token_hash' => $tokenHash,
                'search_record_id' => $claims['record_id'],
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            $updated = DB::table('search_usage_counters')
                ->where('id', 1)
                ->increment('total_selections');

            if ($updated !== 1) {
                throw new \RuntimeException('The search usage counter row is missing.');
            }

            return true;
        });

        $total = DB::table('search_usage_counters')->where('id', 1)->value('total_selections');

        return response()->json([
            'counted' => $counted,
            'total_selections' => (int) $total,
        ]);
    }
}
