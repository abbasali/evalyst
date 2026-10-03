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
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->longText('body');
            $table->string('code_language')->nullable();
            $table->decimal('default_marks', 8, 2)->default(1);
            $table->string('scoring_policy')->nullable();
            $table->longText('model_answer')->nullable();
            $table->longText('rubric')->nullable();
            $table->longText('explanation')->nullable();
            $table->string('difficulty')->nullable();
            $table->string('source')->default('manual');
            $table->boolean('needs_verification')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'type']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });

        Schema::create('question_tag', function (Blueprint $table) {
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['question_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_tag');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('tags');
    }
};
