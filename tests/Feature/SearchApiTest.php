<?php

namespace Tests\Feature;

use App\Models\SearchRecord;
use App\Services\Search\SearchSelectionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_matching_suggestions_without_counting_usage(): void
    {
        SearchRecord::query()->create(['content' => '25 London Road Market Park']);
        SearchRecord::query()->create(['content' => 'Oxford High Street Garden']);

        $response = $this->getJson('/api/search?q=London+Ro');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', '25 London Road Market Park')
            ->assertHeader('Server-Timing')
            ->assertJsonStructure(['data' => [['id', 'content', 'selection_token']]]);

        $this->assertDatabaseCount('search_usage_events', 0);
        $this->assertDatabaseHas('search_usage_counters', [
            'id' => 1,
            'total_selections' => 0,
        ]);
    }

    public function test_search_requires_every_indexed_term_to_match(): void
    {
        SearchRecord::query()->create(['content' => '25 London Road Market Park']);
        SearchRecord::query()->create(['content' => 'London Avenue Park']);
        SearchRecord::query()->create(['content' => 'Cambridge Road Garden']);

        $this->getJson('/api/search?q=London+Road')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', '25 London Road Market Park');
    }

    public function test_a_suggestion_token_counts_at_most_one_selection(): void
    {
        $record = SearchRecord::query()->create(['content' => '25 London Road Market Park']);
        $token = app(SearchSelectionToken::class)->issue($record->id);

        $this->postJson('/api/search/selections', ['selection_token' => $token])
            ->assertOk()
            ->assertJson([
                'counted' => true,
                'total_selections' => 1,
            ]);

        $this->postJson('/api/search/selections', ['selection_token' => $token])
            ->assertOk()
            ->assertJson([
                'counted' => false,
                'total_selections' => 1,
            ]);

        $this->assertDatabaseCount('search_usage_events', 1);
    }

    public function test_invalid_selection_tokens_do_not_count_usage(): void
    {
        $this->postJson('/api/search/selections', ['selection_token' => 'not-a-valid-token'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('search_usage_events', 0);
        $this->assertDatabaseHas('search_usage_counters', [
            'id' => 1,
            'total_selections' => 0,
        ]);
    }

    public function test_expired_selection_tokens_do_not_count_usage(): void
    {
        $record = SearchRecord::query()->create(['content' => '25 London Road Market Park']);
        $token = app(SearchSelectionToken::class)->issue($record->id);

        $this->travel(16)->minutes();

        $this->postJson('/api/search/selections', ['selection_token' => $token])
            ->assertUnprocessable();

        $this->assertDatabaseCount('search_usage_events', 0);
    }

    public function test_short_queries_return_no_suggestions(): void
    {
        SearchRecord::query()->create(['content' => '25 London Road Market Park']);

        $this->getJson('/api/search?q=Lo')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
