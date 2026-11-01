<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->boolean('is_active')->default(true);
            // NULL means unlimited. Zero means the capability is explicitly not allowed.
            $table->integer('max_personal_recipes')->nullable();
            $table->integer('max_active_shares_per_recipe')->nullable();
            $table->integer('max_storage_mb')->nullable();
            $table->timestamps();
        });

        foreach (['max_personal_recipes', 'max_active_shares_per_recipe', 'max_storage_mb'] as $column) {
            DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_{$column}_check CHECK ({$column} IS NULL OR {$column} >= 0)");
        }

        DB::table('plans')->insert([
            'code' => 'free',
            'is_active' => true,
            'max_personal_recipes' => 20,
            'max_active_shares_per_recipe' => 5,
            'max_storage_mb' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
