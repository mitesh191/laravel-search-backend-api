<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_records', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->text('content');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement(
                'CREATE INDEX search_records_content_trgm_idx ON search_records USING GIN (content gin_trgm_ops)',
            );
        }

        Schema::create('search_usage_counters', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('total_selections')->default(0);
        });

        Schema::create('search_usage_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('search_record_id')->constrained('search_records')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('search_usage_counters')->insert([
            'id' => 1,
            'total_selections' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('search_usage_events');
        Schema::dropIfExists('search_usage_counters');
        Schema::dropIfExists('search_records');
    }
};
