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
        Schema::table('assessments', function (Blueprint $table) {
            $table->string('late_policy')->nullable()->after('require_fullscreen');
            $table->string('penalty_type')->nullable()->after('late_policy');
            $table->decimal('penalty_value', 8, 2)->nullable()->after('penalty_type');
            $table->decimal('penalty_cap', 8, 2)->nullable()->after('penalty_value');
            $table->unsignedSmallInteger('grace_minutes')->default(0)->after('penalty_cap');
            $table->dateTime('hard_cutoff_at')->nullable()->after('grace_minutes');
            $table->boolean('allow_resubmission')->default(true)->after('hard_cutoff_at');
            $table->boolean('show_rules_to_students')->default(true)->after('allow_resubmission');
            $table->json('extra_ignored_paths')->nullable()->after('show_rules_to_students');
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->dateTime('deadline_override_at')->nullable()->after('access_code');
            $table->string('late_override')->nullable()->after('deadline_override_at');
            $table->boolean('penalty_waived')->default(false)->after('late_override');
            $table->decimal('penalty_override', 8, 2)->nullable()->after('penalty_waived');
            $table->text('override_note')->nullable()->after('penalty_override');
        });

        Schema::create('assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('check')->nullable();
            $table->json('config')->nullable();
            $table->decimal('marks', 8, 2);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index(['assessment_id', 'position']);
        });

        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('repo_url');
            $table->string('repo_owner');
            $table->string('repo_name');
            $table->string('commit_sha', 40);
            $table->string('default_branch');
            $table->dateTime('submitted_at');
            $table->boolean('is_current')->default(true);
            $table->unsignedInteger('minutes_late')->default(0);
            $table->string('status');
            $table->json('manifest')->nullable();
            $table->decimal('raw_score', 8, 2)->nullable();
            $table->decimal('penalty', 8, 2)->default(0);
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2);
            $table->text('feedback')->nullable();
            $table->json('ai_flags')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->text('error')->nullable();
            $table->json('review_reasons')->nullable();
            $table->timestamps();

            $table->index(['participant_id', 'is_current']);
            $table->index('status');
        });

        Schema::create('submission_rule_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_rule_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 8, 2);
            $table->decimal('max_score', 8, 2);
            $table->boolean('passed')->nullable();
            $table->text('reasoning')->nullable();
            $table->json('evidence')->nullable();
            $table->decimal('ai_confidence', 3, 2)->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['submission_id', 'assignment_rule_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_rule_results');
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('assignment_rules');

        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn(['deadline_override_at', 'late_override', 'penalty_waived', 'penalty_override', 'override_note']);
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['late_policy', 'penalty_type', 'penalty_value', 'penalty_cap', 'grace_minutes', 'hard_cutoff_at', 'allow_resubmission', 'show_rules_to_students', 'extra_ignored_paths']);
        });
    }
};
