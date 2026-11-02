<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CLASSIFICATIONS = ['unclassified', 'descriptive', 'cuisine', 'diet_style', 'nutrition_claim', 'ingredient_allergen'];

    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            // Fail closed: a tag nobody has classified is never shown publicly.
            $table->string('classification', 30)->default('unclassified')->after('code');
            $table->boolean('is_public_enabled')->default(false)->after('classification');
            $table->index(['classification', 'is_active']);
        });

        $list = "'".implode("','", self::CLASSIFICATIONS)."'";
        DB::statement("ALTER TABLE tags ADD CONSTRAINT tags_classification_check CHECK (classification IN ({$list}))");

        // Existing associations are kept; only the classification of the known seeded tags is recorded.
        DB::table('tags')->whereIn('code', ['quick', 'budget-friendly', 'baking'])->update(['classification' => 'descriptive']);
        DB::table('tags')->where('code', 'vegetarian')->update(['classification' => 'diet_style', 'is_public_enabled' => true]);
        DB::table('tags')->where('code', 'gluten-free')->update(['classification' => 'ingredient_allergen', 'is_public_enabled' => false]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tags DROP CONSTRAINT IF EXISTS tags_classification_check');

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropIndex(['classification', 'is_active']);
            $table->dropColumn(['classification', 'is_public_enabled']);
        });
    }
};
