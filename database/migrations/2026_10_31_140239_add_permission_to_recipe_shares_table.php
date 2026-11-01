<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_shares', function (Blueprint $table): void {
            $table->string('permission', 20)->default('view')->after('user_id');
        });

        DB::statement("ALTER TABLE recipe_shares ADD CONSTRAINT recipe_shares_permission_check CHECK (permission IN ('view'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recipe_shares DROP CONSTRAINT IF EXISTS recipe_shares_permission_check');

        Schema::table('recipe_shares', function (Blueprint $table): void {
            $table->dropColumn('permission');
        });
    }
};
