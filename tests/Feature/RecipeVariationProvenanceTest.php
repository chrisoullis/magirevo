<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeDerivation;
use App\Models\RecipeReusePermission;
use App\Models\User;
use App\Services\RecipeRevisionService;
use App\Support\AppPermissions;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecipeVariationProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private function rights(): RecipeReusePermission
    {
        $source = Recipe::factory()->published()->create();
        $revision = app(RecipeRevisionService::class)->syncPublishedFromLive($source->id);
        $reviewer = User::factory()->admin()->create();

        $permission = new RecipeReusePermission;
        $permission->forceFill([
            'recipe_id' => $source->id,
            'recipe_revision_id' => $revision->id,
            'mode' => 'personal',
            'rights_basis' => 'written_consent',
            'permission_reference' => 'Signed permission retained by rights reviewer',
            'license_conditions' => ['attribution' => true],
            'allows_text_reproduction' => true,
            'allows_private_adaptation' => true,
            'verification_status' => 'verified',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => 'Authority and consent checked.',
            'created_by' => $reviewer->id,
        ])->save();

        return $permission;
    }

    private function variation(?RecipeReusePermission $permission = null): Recipe
    {
        $permission ??= $this->rights();

        return DB::transaction(function () use ($permission): Recipe {
            $recipe = Recipe::factory()->make();
            $recipe->derived_from_recipe_id = $permission->recipe_id;
            $recipe->save();
            $provenance = new RecipeDerivation;
            $provenance->forceFill([
                'recipe_id' => $recipe->id,
                'source_recipe_revision_id' => $permission->recipe_revision_id,
                'source_title_snapshot' => 'Historical title',
                'source_author_snapshot' => 'Historical author',
                'source_license_snapshot' => $permission->license_conditions,
                'attribution_snapshot' => ['disclosure_allowed' => false],
                'source_rights_basis' => $permission->rights_basis,
                'source_permission_evidence_id' => $permission->id,
                'created_by' => $recipe->author_id,
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => str_repeat('a', 64),
            ])->save();

            return $recipe;
        });
    }

    private function assertDatabaseRefuses(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Database accepted an invalid provenance or rights mutation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_independent_recipe_has_durable_source_and_exact_revision_relationships(): void
    {
        $permission = $this->rights();
        $recipe = $this->variation($permission);

        $this->assertNotSame($permission->recipe_id, $recipe->id);
        $this->assertSame($permission->recipe_id, $recipe->derivedFrom->id);
        $this->assertSame($permission->recipe_revision_id, $recipe->derivation->sourceRevision->id);
        $this->assertSame($permission->id, $recipe->derivation->permissionEvidence->id);
        $this->assertSame($recipe->id, $recipe->derivedFrom->variations()->first()->id);
    }

    public function test_source_changes_and_soft_deletion_preserve_historical_provenance(): void
    {
        $recipe = $this->variation();
        $source = $recipe->derivedFrom;
        $source->translate('en')->update(['title' => 'Changed source title']);
        $source->delete();

        $this->assertNotNull($recipe->fresh());
        $this->assertTrue($recipe->fresh()->derivedFrom->trashed());
        $this->assertSame('Historical title', $recipe->derivation->source_title_snapshot);
        $this->assertDatabaseRefuses(fn () => DB::table('recipes')->where('id', $source->id)->delete());
    }

    public function test_provenance_cannot_be_rewritten_deleted_or_unlinked_even_by_raw_sql(): void
    {
        $recipe = $this->variation();
        $this->assertDatabaseRefuses(fn () => DB::table('recipe_derivations')->where('recipe_id', $recipe->id)->update(['source_title_snapshot' => 'Forged']));
        $this->assertDatabaseRefuses(fn () => DB::table('recipe_derivations')->where('recipe_id', $recipe->id)->delete());
        $this->assertDatabaseRefuses(fn () => DB::table('recipes')->where('id', $recipe->id)->update(['derived_from_recipe_id' => null]));
    }

    public function test_recursive_forks_and_self_references_are_rejected(): void
    {
        $recipe = $this->variation();
        $this->assertDatabaseRefuses(function () use ($recipe): void {
            $child = Recipe::factory()->make();
            $child->derived_from_recipe_id = $recipe->id;
            $child->save();
        });
        $this->assertDatabaseRefuses(fn () => DB::table('recipes')->where('id', $recipe->id)->update(['derived_from_recipe_id' => $recipe->id]));
    }

    public function test_missing_provenance_is_rejected_by_deferred_constraint(): void
    {
        $permission = $this->rights();
        $this->assertDatabaseRefuses(function () use ($permission): void {
            $recipe = Recipe::factory()->make();
            $recipe->derived_from_recipe_id = $permission->recipe_id;
            $recipe->save();
            DB::statement('SET CONSTRAINTS recipes_require_derivation_provenance IMMEDIATE');
        });
    }

    public function test_suspension_preserves_existing_variations_but_rejects_new_provenance(): void
    {
        $permission = $this->rights();
        $recipe = $this->variation($permission);
        $permission->forceFill(['suspended_at' => now(), 'suspended_by' => $permission->created_by, 'suspension_reason' => 'Disputed consent.'])->save();

        $this->assertNotNull($recipe->fresh()->derivation);
        $this->assertDatabaseRefuses(fn () => $this->variation($permission));
        $this->assertDatabaseRefuses(fn () => DB::table('recipe_reuse_permissions')->where('id', $permission->id)->update(['suspended_at' => null, 'suspended_by' => null, 'suspension_reason' => null]));
    }

    public function test_rights_evidence_is_immutable_and_cannot_reference_another_recipes_revision(): void
    {
        $permission = $this->rights();
        $other = Recipe::factory()->create();
        $this->assertDatabaseRefuses(fn () => DB::table('recipe_reuse_permissions')->where('id', $permission->id)->update(['allows_public_adaptation' => true]));
        $this->assertDatabaseRefuses(fn () => DB::table('recipe_reuse_permissions')->where('id', $permission->id)->delete());
        $this->assertDatabaseRefuses(function () use ($permission, $other): void {
            $forged = $permission->replicate();
            $forged->recipe_id = $other->id;
            $forged->save();
        });
    }

    public function test_verified_permission_requires_evidence_and_disallows_no_derivatives(): void
    {
        $permission = $this->rights();
        foreach ([['permission_reference' => null], ['prohibits_derivatives' => true], ['review_notes' => null], ['mode' => 'personal_and_public_submission']] as $changes) {
            $this->assertDatabaseRefuses(function () use ($permission, $changes): void {
                $invalid = $permission->replicate();
                $invalid->forceFill($changes)->save();
            });
        }
    }

    public function test_new_provenance_cannot_use_wrong_revision_creator_or_legacy_escape_hatch(): void
    {
        $recipe = $this->variation();
        $template = $recipe->derivation;
        $otherRights = $this->rights();
        foreach ([['source_recipe_revision_id' => $otherRights->recipe_revision_id], ['created_by' => $otherRights->created_by], ['provenance_version' => 0]] as $changes) {
            $this->assertDatabaseRefuses(function () use ($template, $recipe, $changes): void {
                $destination = Recipe::factory()->make(['author_id' => $recipe->author_id]);
                $destination->derived_from_recipe_id = $recipe->derived_from_recipe_id;
                $destination->save();
                $copy = $template->replicate();
                $copy->forceFill(['recipe_id' => $destination->id, 'idempotency_key' => (string) Str::uuid(), ...$changes])->save();
            });
        }
    }

    public function test_idempotency_key_is_unique_per_actor(): void
    {
        $recipe = $this->variation();
        $this->assertDatabaseRefuses(function () use ($recipe): void {
            $destination = Recipe::factory()->make(['author_id' => $recipe->author_id]);
            $destination->derived_from_recipe_id = $recipe->derived_from_recipe_id;
            $destination->save();
            $copy = $recipe->derivation->replicate();
            $copy->recipe_id = $destination->id;
            $copy->save();
        });
    }

    public function test_change_summaries_use_astrotomic_translations(): void
    {
        $derivation = $this->variation()->derivation;
        $derivation->translateOrNew('en')->change_summary = 'Changed the cooking method.';
        $derivation->translateOrNew('el')->change_summary = 'Άλλαξα τον τρόπο μαγειρέματος.';
        $derivation->save();

        $this->assertSame('Changed the cooking method.', $derivation->fresh()->translate('en')->change_summary);
        $this->assertSame('Άλλαξα τον τρόπο μαγειρέματος.', $derivation->fresh()->translate('el')->change_summary);
        $this->assertDatabaseRefuses(fn () => DB::table('recipe_derivation_translations')->insert(['recipe_derivation_id' => $derivation->id, 'locale' => 'fr']));
    }

    public function test_sensitive_evidence_is_hidden_and_provenance_is_not_mass_assignable(): void
    {
        $permission = $this->rights();
        $this->assertArrayNotHasKey('permission_reference', $permission->toArray());
        $this->assertArrayNotHasKey('source_title_snapshot', $this->variation($permission)->derivation->toArray());
        $this->assertFalse(config('filesystems.disks.recipe_rights.serve'));
        $this->assertFalse(config('recipe-variations.enabled'));
        $this->assertFalse(config('recipe-variations.public_submissions_enabled'));
        $this->expectException(MassAssignmentException::class);
        new RecipeDerivation(['recipe_id' => 999]);
    }

    public function test_permissions_do_not_grant_members_contributor_or_rights_review_privileges(): void
    {
        $member = User::factory()->create();
        $this->assertTrue($member->can(AppPermissions::RECIPES_FORK));
        $this->assertFalse($member->can(AppPermissions::RECIPES_CREATE));
        $this->assertFalse($member->can(AppPermissions::VARIATIONS_SUBMIT));
        $this->assertFalse($member->can(AppPermissions::VARIATIONS_MANAGE_RIGHTS));
        $this->assertTrue(User::factory()->contributor()->create()->can(AppPermissions::VARIATIONS_SUBMIT));
        $this->assertTrue(User::factory()->admin()->create()->can(AppPermissions::VARIATIONS_MANAGE_RIGHTS));
    }

    public function test_private_evidence_requires_a_verified_authorized_administrator(): void
    {
        $permission = $this->rights();
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        $editor->givePermissionTo(AppPermissions::VARIATIONS_MANAGE_RIGHTS);
        $this->assertTrue(Gate::forUser($admin)->allows('view', $permission));
        $this->assertTrue(Gate::forUser($admin)->allows('suspend', $permission));
        $this->assertFalse(Gate::forUser($editor)->allows('view', $permission));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->unverified()->create())->allows('view', $permission));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $permission));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $permission));
    }

    public function test_evidence_files_require_private_relative_paths_and_checksums(): void
    {
        $permission = $this->rights();
        foreach ([['evidence_path' => '../public/license.pdf', 'evidence_sha256' => str_repeat('a', 64)], ['evidence_path' => 'license.pdf', 'evidence_sha256' => null], ['evidence_path' => 'https://example.com/license.pdf', 'evidence_sha256' => str_repeat('a', 64)]] as $changes) {
            $this->assertDatabaseRefuses(function () use ($permission, $changes): void {
                $invalid = $permission->replicate();
                $invalid->forceFill($changes)->save();
            });
        }
    }

    public function test_rights_versions_preserve_history_and_new_records_default_to_restricted(): void
    {
        $permission = $this->rights();
        $next = $permission->replicate();
        $next->forceFill(['supersedes_id' => $permission->id, 'mode' => 'none'])->save();
        $this->assertSame($permission->id, $next->previousVersion->id);
        $this->assertSame('personal', $permission->fresh()->mode);

        $pending = new RecipeReusePermission;
        $pending->forceFill([
            'recipe_id' => $permission->recipe_id,
            'recipe_revision_id' => $permission->recipe_revision_id,
            'rights_basis' => 'unverified_import',
            'created_by' => $permission->created_by,
        ])->save();
        $this->assertSame('none', $pending->fresh()->mode);
        $this->assertSame('pending', $pending->fresh()->verification_status);
    }

    public function test_provenance_and_rights_cannot_be_truncated(): void
    {
        $this->variation();
        $this->assertDatabaseRefuses(fn () => DB::statement('TRUNCATE recipe_derivations CASCADE'));
        $this->assertDatabaseRefuses(fn () => DB::statement('TRUNCATE recipe_reuse_permissions CASCADE'));
    }

    public function test_hard_deleting_destination_does_not_delete_source_or_rights(): void
    {
        $recipe = $this->variation();
        $sourceId = $recipe->derived_from_recipe_id;
        $evidenceId = $recipe->derivation->source_permission_evidence_id;
        DB::table('recipes')->where('id', $recipe->id)->delete();
        $this->assertDatabaseHas('recipes', ['id' => $sourceId]);
        $this->assertDatabaseHas('recipe_reuse_permissions', ['id' => $evidenceId]);
        $this->assertDatabaseMissing('recipe_derivations', ['recipe_id' => $recipe->id]);
    }

    public function test_existing_derivations_survive_migration_without_invented_rights(): void
    {
        $migration = require database_path('migrations/2026_11_02_180100_extend_recipe_derivations_with_provenance.php');
        $publicationMigration = require database_path('migrations/2026_11_02_190000_freeze_variation_publication_submissions.php');
        $publicationMigration->down();
        $migration->down();
        $source = Recipe::factory()->published()->create();
        $revision = app(RecipeRevisionService::class)->syncPublishedFromLive($source->id);
        $destination = Recipe::factory()->create();
        $id = DB::table('recipe_derivations')->insertGetId([
            'recipe_id' => $destination->id, 'source_recipe_id' => $source->id,
            'source_recipe_revision_id' => $revision->id, 'created_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();
        $publicationMigration->up();

        $this->assertSame($source->id, $destination->fresh()->derived_from_recipe_id);
        $this->assertDatabaseHas('recipe_derivations', ['id' => $id, 'provenance_version' => 0, 'source_permission_evidence_id' => null]);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('recipe_derivations', 'source_recipe_id'));
    }
}
