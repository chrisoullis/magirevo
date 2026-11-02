<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_dietary_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('suggested');
            $table->foreignId('suggested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('verification_method', 40)->nullable();
            $table->jsonb('evidence')->nullable();
            $table->char('revision_hash', 64);
            $table->unsignedSmallInteger('rule_version');
            $table->text('review_notes')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'tag_id']);
            $table->index(['tag_id', 'status']);
            $table->index(['status', 'reviewed_at']);
        });

        DB::statement("ALTER TABLE recipe_dietary_claims ADD CONSTRAINT recipe_dietary_claims_status_check CHECK (status IN ('suggested', 'approved', 'rejected', 'suspended', 'invalidated'))");
        DB::statement('ALTER TABLE recipe_dietary_claims ADD CONSTRAINT recipe_dietary_claims_no_self_review_check CHECK (reviewed_by IS NULL OR suggested_by IS NULL OR reviewed_by <> suggested_by)');
        DB::statement("ALTER TABLE recipe_dietary_claims ADD CONSTRAINT recipe_dietary_claims_approval_complete_check CHECK (status <> 'approved' OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND verification_method IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_dietary_claims');
    }
};
