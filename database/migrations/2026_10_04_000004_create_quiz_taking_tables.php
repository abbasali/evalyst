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
        // Anti-cheating settings (D-021).
        Schema::table('assessments', function (Blueprint $table) {
            $table->boolean('one_way_navigation')->default(false)->after('track_focus');
            $table->boolean('require_fullscreen')->default(false)->after('one_way_navigation');
        });

        Schema::table('attempts', function (Blueprint $table) {
            // Highest question position reached; enforces one-way navigation.
            $table->unsignedSmallInteger('furthest_position')->default(1)->after('option_order');
        });

        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained();
            $table->json('selected_option_ids')->nullable();
            $table->longText('text_answer')->nullable();
            $table->longText('code_answer')->nullable();
            $table->boolean('flagged')->default(false);
            $table->dateTime('answered_at')->nullable();
            $table->decimal('max_score', 8, 2);
            $table->decimal('score', 8, 2)->nullable();
            $table->string('grading_status')->default('ungraded');
            $table->decimal('ai_score', 8, 2)->nullable();
            $table->text('ai_feedback')->nullable();
            $table->decimal('ai_confidence', 3, 2)->nullable();
            $table->json('ai_breakdown')->nullable();
            $table->json('ai_flags')->nullable();
            $table->json('review_reasons')->nullable();
            $table->text('grading_error')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'assessment_question_id']);
            $table->index(['question_id', 'grading_status']);
        });

        Schema::create('attempt_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->dateTime('occurred_at');
            $table->json('meta')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['attempt_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attempt_events');
        Schema::dropIfExists('answers');

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropColumn('furthest_position');
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['one_way_navigation', 'require_fullscreen']);
        });
    }
};
