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
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('type');
            $table->string('title', 150);
            $table->longText('instructions')->nullable();
            $table->string('status')->default('draft');
            $table->string('access_mode')->default('roster');
            $table->string('shared_code', 12)->nullable()->unique();
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('closes_at');
            $table->string('release_mode')->default('manual');
            $table->dateTime('results_released_at')->nullable();
            $table->decimal('auto_publish_threshold', 3, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Quiz-only (assignment-only columns are added in M09.1).
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->boolean('show_answers_after_release')->default(true);
            $table->boolean('track_focus')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'type', 'status']);
        });

        Schema::create('assessment_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->decimal('marks', 8, 2);
            $table->timestamps();

            $table->unique(['assessment_id', 'question_id']);
        });

        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('access_code', 12)->nullable()->unique();
            $table->dateTime('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id']);
        });

        // Created early (D-018) so M05 can lock quizzes once students start; M06 fills it.
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->unique()->constrained()->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('status');
            $table->dateTime('started_at');
            $table->dateTime('deadline_at');
            $table->dateTime('submitted_at')->nullable();
            $table->boolean('auto_submitted')->default(false);
            $table->json('question_order');
            $table->json('option_order')->nullable();
            $table->string('resume_token', 64);
            $table->dateTime('resume_override_until')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2);
            $table->unsignedInteger('focus_lost_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attempts');
        Schema::dropIfExists('participants');
        Schema::dropIfExists('assessment_questions');
        Schema::dropIfExists('assessments');
    }
};
