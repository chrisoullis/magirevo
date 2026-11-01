<?php

use App\Enums\RecipeStatus;
use App\Enums\RecipeVisibility;
use App\Support\AppPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->string('visibility', 20)->default(RecipeVisibility::Private->value)->after('status');
            $table->timestamp('submitted_at')->nullable()->after('published_at');
            $table->foreignId('reviewed_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            $table->index(['visibility', 'status']);
            $table->index('author_id');
        });

        // Everything already published on the site stays publicly visible.
        DB::table('recipes')->where('status', RecipeStatus::Published->value)->update(['visibility' => RecipeVisibility::Public->value]);

        $statuses = $this->quoted(RecipeStatus::values());
        $visibilities = $this->quoted(RecipeVisibility::values());

        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_status_check CHECK (status IN ({$statuses}))");
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_visibility_check CHECK (visibility IN ({$visibilities}))");
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_public_requires_published_check CHECK (visibility <> 'public' OR status = 'published')");

        Schema::create('recipe_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        // One active grant per recipient; revoked grants are kept as history.
        DB::statement('CREATE UNIQUE INDEX recipe_shares_active_unique ON recipe_shares (recipe_id, user_id) WHERE revoked_at IS NULL');

        (new RolesAndPermissionsSeeder)->run();
        DB::table('role_has_permissions')->insertOrIgnore($this->adminGrant());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_shares');

        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_public_requires_published_check');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_visibility_check');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS recipes_status_check');

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropIndex(['visibility', 'status']);
            $table->dropIndex(['author_id']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['visibility', 'submitted_at', 'reviewed_at', 'rejection_reason']);
        });
    }

    /**
     * The seeder only fills roles that have no permissions yet, so the new moderation permission is granted explicitly.
     *
     * @return list<array{permission_id: int, role_id: int}>
     */
    private function adminGrant(): array
    {
        $permissionId = DB::table('permissions')->where('name', AppPermissions::RECIPES_MODERATE_PRIVATE)->value('id');
        $roleId = DB::table('roles')->where('name', AppPermissions::ADMIN)->value('id');

        return [['permission_id' => $permissionId, 'role_id' => $roleId]];
    }

    /**
     * @param  list<string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));
    }
};
