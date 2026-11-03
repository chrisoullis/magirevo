<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('alias', 255);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE ingredient_aliases ADD CONSTRAINT ingredient_aliases_locale_check CHECK (locale IN ('en', 'el'))");
        DB::statement('ALTER TABLE ingredient_aliases ADD CONSTRAINT ingredient_aliases_text_check CHECK (alias = btrim(alias) AND length(alias) > 0)');
        DB::statement('CREATE UNIQUE INDEX ingredient_aliases_normalized_unique ON ingredient_aliases (ingredient_id, locale, lower(alias))');
        if (DB::selectOne("SELECT to_regprocedure('f_unaccent(text)') IS NOT NULL AS available")->available) {
            DB::statement('CREATE INDEX ingredient_aliases_search_idx ON ingredient_aliases USING gin (f_unaccent(lower(alias)) gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_aliases');
    }
};
