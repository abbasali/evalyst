<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->morphs('subject');
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('succeeded');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
        });

        Schema::create('question_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('prompt');
            $table->json('type_counts');
            $table->string('difficulty');
            $table->boolean('include_code_output')->default(true);
            $table->json('tag_ids')->nullable();
            $table->string('status');
            $table->json('drafts')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedSmallInteger('accepted_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('question_generation_id')->nullable()->after('source')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('question_generation_id');
        });
        Schema::dropIfExists('question_generations');
        Schema::dropIfExists('ai_runs');
    }
};
