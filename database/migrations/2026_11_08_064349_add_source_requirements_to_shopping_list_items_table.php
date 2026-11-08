<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table): void {
            $table->jsonb('source_requirements')->default('[]');
        });
        DB::statement("ALTER TABLE shopping_list_items ADD CONSTRAINT shopping_source_requirements_array CHECK (jsonb_typeof(source_requirements) = 'array')");
    }

    public function down(): void
    {
        if (DB::table('shopping_list_items')->whereRaw("source_requirements <> '[]'::jsonb")->exists()) {
            throw new LogicException('Generated shopping provenance requires a reviewed retention plan before rollback.');
        }
        DB::statement('ALTER TABLE shopping_list_items DROP CONSTRAINT shopping_source_requirements_array');
        Schema::table('shopping_list_items', fn (Blueprint $table) => $table->dropColumn('source_requirements'));
    }
};
