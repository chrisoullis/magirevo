<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->after('code')->constrained('categories')->restrictOnDelete();
            $table->index(['parent_id', 'sort_order']);
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_parent_not_self_check CHECK (parent_id IS NULL OR parent_id <> id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE categories DROP CONSTRAINT IF EXISTS categories_parent_not_self_check');

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['parent_id', 'sort_order']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
