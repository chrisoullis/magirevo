<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredient_translations', function (Blueprint $table): void {
            $table->string('slug', 180)->nullable();
            $table->text('culinary_uses')->nullable();
            $table->text('preparation_tips')->nullable();
            $table->text('storage_tips')->nullable();
            $table->unique(['locale', 'slug']);
        });
        DB::statement("ALTER TABLE ingredient_translations ADD CONSTRAINT ingredient_translations_slug_check CHECK (slug IS NULL OR (slug = lower(slug) AND slug ~ '^[^\\s/?#]+$'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ingredient_translations DROP CONSTRAINT IF EXISTS ingredient_translations_slug_check');
        Schema::table('ingredient_translations', function (Blueprint $table): void {
            $table->dropUnique(['locale', 'slug']);
            $table->dropColumn(['slug', 'culinary_uses', 'preparation_tips', 'storage_tips']);
        });
    }
};
