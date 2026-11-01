<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('media_type', 20)->default('image');
            $table->string('source', 20)->default('local');
        });

        DB::table('media')->where('disk', 'remote')->update(['source' => 'remote']);

        Schema::table('recipe_step_media', function (Blueprint $table): void {
            $table->string('kind', 20)->default('image');
        });

        DB::statement('ALTER TABLE media DROP CONSTRAINT media_source_check');
        DB::statement('ALTER TABLE media DROP CONSTRAINT media_image_mime_type_check');
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_type_check CHECK (media_type IN ('image', 'video'))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_source_type_check CHECK ((source IN ('local', 'remote') AND media_type = 'image') OR (source = 'embed' AND media_type = 'video'))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_source_check CHECK ((source = 'local' AND disk <> 'remote' AND file_path IS NOT NULL AND url IS NULL) OR (source IN ('remote', 'embed') AND disk = 'remote' AND file_path IS NULL AND url IS NOT NULL))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_mime_type_check CHECK ((media_type = 'image' AND (mime_type IS NULL OR mime_type IN ('image/jpeg', 'image/png', 'image/webp'))) OR (media_type = 'video' AND mime_type = 'video/embed'))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_embed_url_check CHECK (source <> 'embed' OR url ~ '^https://(www[.]youtube-nocookie[.]com/embed/[A-Za-z0-9_-]{11}|player[.]vimeo[.]com/video/[0-9]+)$')");
        DB::statement("ALTER TABLE recipe_step_media ADD CONSTRAINT recipe_step_media_kind_check CHECK (kind IN ('image', 'video'))");
        DB::statement("CREATE UNIQUE INDEX recipe_step_media_one_video_per_step_unique ON recipe_step_media (recipe_step_id) WHERE kind = 'video'");
    }

    public function down(): void
    {
        DB::table('recipe_step_media')->where('kind', 'video')->delete();
        DB::table('media')->where('source', 'embed')->delete();

        DB::statement('DROP INDEX recipe_step_media_one_video_per_step_unique');
        DB::statement('ALTER TABLE recipe_step_media DROP CONSTRAINT recipe_step_media_kind_check');
        DB::statement('ALTER TABLE media DROP CONSTRAINT media_embed_url_check');
        DB::statement('ALTER TABLE media DROP CONSTRAINT media_mime_type_check');
        DB::statement('ALTER TABLE media DROP CONSTRAINT media_source_check');
        DB::statement('ALTER TABLE media DROP CONSTRAINT media_source_type_check');
        DB::statement('ALTER TABLE media DROP CONSTRAINT media_type_check');
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_source_check CHECK ((disk = 'remote' AND url IS NOT NULL AND file_path IS NULL) OR (disk <> 'remote' AND file_path IS NOT NULL AND url IS NULL))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_image_mime_type_check CHECK (mime_type IS NULL OR mime_type IN ('image/jpeg', 'image/png', 'image/webp'))");

        Schema::table('recipe_step_media', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });

        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn(['media_type', 'source']);
        });
    }
};
