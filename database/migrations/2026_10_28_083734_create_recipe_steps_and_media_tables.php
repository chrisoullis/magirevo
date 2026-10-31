<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step_number');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->timestamps();
            $table->unique(['recipe_id', 'step_number']);
        });

        Schema::create('recipe_step_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_step_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->text('instruction');
            $table->timestamps();
            $table->unique(['recipe_step_id', 'locale']);
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 50)->default('public');
            $table->string('file_path', 512);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
            $table->unique(['disk', 'file_path']);
        });

        Schema::create('media_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('alt_text', 255);
            $table->text('caption')->nullable();
            $table->timestamps();
            $table->unique(['media_id', 'locale']);
        });

        Schema::create('recipe_media', function (Blueprint $table): void {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('gallery');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['recipe_id', 'media_id']);
            $table->index(['recipe_id', 'role', 'sort_order']);
            $table->index('media_id');
        });

        Schema::create('recipe_step_media', function (Blueprint $table): void {
            $table->foreignId('recipe_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['recipe_step_id', 'media_id']);
            $table->index(['recipe_step_id', 'sort_order']);
            $table->index('media_id');
        });

        DB::statement("ALTER TABLE recipe_step_translations ADD CONSTRAINT recipe_step_translations_locale_check CHECK (locale IN ('en', 'el'))");
        DB::statement("ALTER TABLE media_translations ADD CONSTRAINT media_translations_locale_check CHECK (locale IN ('en', 'el'))");
        DB::statement("ALTER TABLE recipe_media ADD CONSTRAINT recipe_media_role_check CHECK (role IN ('cover', 'gallery'))");
        DB::statement('ALTER TABLE recipe_steps ADD CONSTRAINT recipe_steps_step_number_positive_check CHECK (step_number > 0)');
        DB::statement('ALTER TABLE recipe_steps ADD CONSTRAINT recipe_steps_duration_positive_check CHECK (duration_minutes IS NULL OR duration_minutes > 0)');
        DB::statement('ALTER TABLE recipe_media ADD CONSTRAINT recipe_media_sort_order_nonnegative_check CHECK (sort_order >= 0)');
        DB::statement('ALTER TABLE recipe_step_media ADD CONSTRAINT recipe_step_media_sort_order_nonnegative_check CHECK (sort_order >= 0)');
        DB::statement("CREATE UNIQUE INDEX recipe_media_one_cover_unique ON recipe_media (recipe_id) WHERE role = 'cover'");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_image_mime_type_check CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp'))");
        DB::statement('ALTER TABLE media ADD CONSTRAINT media_file_size_positive_check CHECK (file_size > 0)');
        DB::statement('ALTER TABLE media ADD CONSTRAINT media_file_size_limit_check CHECK (file_size <= 10485760)');
        DB::statement('ALTER TABLE media ADD CONSTRAINT media_dimensions_valid_check CHECK (width > 0 AND height > 0 AND width <= 6000 AND height <= 6000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_step_media');
        Schema::dropIfExists('recipe_media');
        Schema::dropIfExists('media_translations');
        Schema::dropIfExists('media');
        Schema::dropIfExists('recipe_step_translations');
        Schema::dropIfExists('recipe_steps');
    }
};
