<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('recipes')->whereNull('author_id')->exists()) {
            throw new RuntimeException(
                'Cannot require recipe authors while recipes without an author exist. Assign an author to each recipe first.'
            );
        }

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign('recipes_author_id_foreign');
        });

        DB::statement('ALTER TABLE recipes ALTER COLUMN author_id SET NOT NULL');

        Schema::table('recipes', function (Blueprint $table): void {
            $table->foreign('author_id')->references('id')->on('users')->restrictOnDelete();
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign('recipes_author_id_foreign');
            $table->dropIndex('recipes_author_id_index');
        });

        DB::statement('ALTER TABLE recipes ALTER COLUMN author_id DROP NOT NULL');

        Schema::table('recipes', function (Blueprint $table): void {
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
