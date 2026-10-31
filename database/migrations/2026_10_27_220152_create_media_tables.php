<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A media row is either an uploaded file (disk + file_path) or a remote image (disk = 'remote' + url).
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 50)->default('public');
            $table->string('file_path', 512)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('credit', 255)->nullable();
            $table->string('credit_url', 2048)->nullable();
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

        DB::statement("ALTER TABLE media_translations ADD CONSTRAINT media_translations_locale_check CHECK (locale IN ('en', 'el'))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_source_check CHECK ((disk = 'remote' AND url IS NOT NULL AND file_path IS NULL) OR (disk <> 'remote' AND file_path IS NOT NULL AND url IS NULL))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_image_mime_type_check CHECK (mime_type IS NULL OR mime_type IN ('image/jpeg', 'image/png', 'image/webp'))");
        DB::statement('ALTER TABLE media ADD CONSTRAINT media_file_size_check CHECK (file_size IS NULL OR (file_size > 0 AND file_size <= 10485760))');
        DB::statement('ALTER TABLE media ADD CONSTRAINT media_dimensions_valid_check CHECK ((width IS NULL OR (width > 0 AND width <= 6000)) AND (height IS NULL OR (height > 0 AND height <= 6000)))');
        DB::statement("ALTER TABLE recipe_media ADD CONSTRAINT recipe_media_role_check CHECK (role IN ('cover', 'gallery'))");
        DB::statement('ALTER TABLE recipe_media ADD CONSTRAINT recipe_media_sort_order_nonnegative_check CHECK (sort_order >= 0)');
        DB::statement('ALTER TABLE recipe_step_media ADD CONSTRAINT recipe_step_media_sort_order_nonnegative_check CHECK (sort_order >= 0)');
        DB::statement("CREATE UNIQUE INDEX recipe_media_one_cover_unique ON recipe_media (recipe_id) WHERE role = 'cover'");
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_step_media');
        Schema::dropIfExists('recipe_media');
        Schema::dropIfExists('media_translations');
        Schema::dropIfExists('media');
    }
};
