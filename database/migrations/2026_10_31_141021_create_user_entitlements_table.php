<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->integer('personal_recipe_limit_override')->nullable();
            $table->integer('sharing_limit_override')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE user_entitlements ADD CONSTRAINT user_entitlements_recipe_override_check CHECK (personal_recipe_limit_override IS NULL OR personal_recipe_limit_override >= 0)');
        DB::statement('ALTER TABLE user_entitlements ADD CONSTRAINT user_entitlements_sharing_override_check CHECK (sharing_limit_override IS NULL OR sharing_limit_override >= 0)');

        $freePlanId = DB::table('plans')->where('code', 'free')->value('id');

        DB::table('users')->orderBy('id')->select('id')->chunkById(500, function ($users) use ($freePlanId): void {
            DB::table('user_entitlements')->insert($users->map(fn ($user): array => [
                'user_id' => $user->id,
                'plan_id' => $freePlanId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_entitlements');
    }
};
