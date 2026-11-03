<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_catalog_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('ingredient_catalog_group_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_catalog_group_id')->constrained('ingredient_catalog_groups')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['ingredient_catalog_group_id', 'locale'], 'ingredient_catalog_group_locale_unique');
        });
        DB::statement("ALTER TABLE ingredient_catalog_groups ADD CONSTRAINT ingredient_catalog_groups_code_check CHECK (code ~ '^[a-z0-9]+([_-][a-z0-9]+)*$')");
        DB::statement("ALTER TABLE ingredient_catalog_group_translations ADD CONSTRAINT ingredient_catalog_group_locale_check CHECK (locale IN ('en', 'el'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_catalog_group_translations');
        Schema::dropIfExists('ingredient_catalog_groups');
    }
};
