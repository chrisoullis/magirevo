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
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('status', 20)->default('draft');
            $table->integer('rest_minutes')->default(0);
            $table->decimal('base_servings', 8, 2)->nullable();
            $table->softDeletes();
            $table->index(['status', 'published_at'], 'recipes_status_published_at_index');
        });

        DB::table('recipes')->whereNull('base_servings')->update([
            'base_servings' => DB::raw('COALESCE(servings, 2)'),
        ]);

        DB::table('recipes')->whereNotNull('published_at')->update(['status' => 'published']);

        Schema::table('recipes', function (Blueprint $table) {
            $table->decimal('base_servings', 8, 2)->nullable(false)->change();
            $table->string('difficulty', 20)->nullable()->change();
            $table->integer('prep_minutes')->default(0)->change();
            $table->integer('cook_minutes')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('recipes')->whereNull('difficulty')->update(['difficulty' => 'Easy']);

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex('recipes_status_published_at_index');
            $table->dropSoftDeletes();
            $table->dropColumn(['status', 'rest_minutes', 'base_servings']);
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->string('difficulty')->default('Easy')->nullable(false)->change();
            $table->unsignedSmallInteger('prep_minutes')->change();
            $table->unsignedSmallInteger('cook_minutes')->change();
        });
    }
};
