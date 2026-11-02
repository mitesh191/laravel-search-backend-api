<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_record_terms', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('term', 100);
            $table->foreignId('search_record_id')
                ->constrained('search_records')
                ->cascadeOnDelete();
            $table->primary(['term', 'search_record_id'], 'search_record_terms_term_record_primary');
            $table->index(
                ['search_record_id', 'term'],
                'search_record_terms_record_term_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_record_terms');
    }
};
