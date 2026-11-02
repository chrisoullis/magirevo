<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recipe_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('rating');
            $table->text('review_text')->nullable();
            $table->string('review_status', 20)->default('pending');
            $table->string('rating_status', 20)->default('active');
            $table->text('moderation_reason')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'user_id']);
            $table->index(['recipe_id', 'rating_status', 'review_status']);
        });

        Schema::create('recipe_rating_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_rating_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['recipe_rating_id', 'reporter_id']);
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE recipe_ratings ADD CONSTRAINT recipe_ratings_value_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE recipe_ratings ADD CONSTRAINT recipe_ratings_review_status_check CHECK (review_status IN ('pending', 'published', 'rejected'))");
        DB::statement("ALTER TABLE recipe_ratings ADD CONSTRAINT recipe_ratings_rating_status_check CHECK (rating_status IN ('active', 'removed'))");
        DB::statement("ALTER TABLE recipe_ratings ADD CONSTRAINT recipe_ratings_removed_reason_check CHECK (rating_status <> 'removed' OR (moderation_reason IS NOT NULL AND btrim(moderation_reason) <> ''))");
        DB::statement("ALTER TABLE recipe_rating_reports ADD CONSTRAINT recipe_rating_reports_status_check CHECK (status IN ('pending', 'dismissed', 'upheld'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_rating_reports');
        Schema::dropIfExists('recipe_ratings');
    }
};
