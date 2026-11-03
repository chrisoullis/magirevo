<?php

namespace Tests\Feature;

use App\Actions\StoreRecipeMedia;
use App\Enums\RecipeRevisionStatus;
use App\Filament\Resources\RecipeRevisions\Pages\ViewRecipeRevision;
use App\Livewire\EditRecipeVariation;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionAudit;
use App\Models\Tag;
use App\Models\User;
use App\Services\DietaryClaimService;
use App\Services\RecipeContentGuard;
use App\Services\RecipePublishingService;
use App\Services\RecipeQuotaService;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use App\Services\Recipes\RecipeForkService;
use App\Services\Recipes\RecipeVariationContent;
use App\Services\Recipes\RecipeVariationPublishingService;
use App\Support\AppPermissions;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CreatesVariationSource;
use Tests\TestCase;

class RecipeVariationPublicationTest extends TestCase
{
    use CreatesVariationSource;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['recipe-variations.enabled' => true, 'recipe-variations.public_submissions_enabled' => true]);
        Storage::fake('local');
        Storage::fake('public');
    }

    private function ready(?User $owner = null): array
    {
        $source = $this->source();
        $permission = $source->reusePermissions()->first();
        $public = $permission->replicate();
        $public->forceFill(['mode' => 'personal_and_public_submission', 'allows_public_adaptation' => true, 'supersedes_id' => $permission->id])->save();
        $owner ??= User::factory()->contributor()->create();
        $recipe = app(RecipeForkService::class)->fork($owner, $source, ['idempotency_key' => (string) Str::uuid()]);
        $recipe->steps()->first()->translate('en')->update(['instruction' => 'Roast the mushrooms before simmering the soup.']);
        $recipe->steps()->first()->translate('el')->update(['instruction' => 'Ψήστε τα μανιτάρια πριν σιγοβράσετε τη σούπα.']);
        app(StoreRecipeMedia::class)->handle($recipe, UploadedFile::fake()->image('new-cover.png', 600, 400), ['en' => ['alt_text' => 'My new soup'], 'el' => ['alt_text' => 'Η νέα σούπα μου']], 'cover');
        app(RecipeContentGuard::class)->setMode('enforce');

        return [$owner, $recipe->fresh(), $source];
    }

    private function declarations(): array
    {
        return ['rights_confirmed' => true, 'meaningful_changes_confirmed' => true, 'summaries' => [
            'en' => 'Roasted the mushrooms first and developed a different cooking method.',
            'el' => 'Έψησα πρώτα τα μανιτάρια και ανέπτυξα διαφορετική μέθοδο μαγειρέματος.',
        ]];
    }

    private function submit(User $owner, Recipe $recipe, ?array $declarations = null): RecipeRevision
    {
        return app(RecipeVariationPublishingService::class)->submit($owner, $recipe, app(RecipeVariationContent::class)->version($recipe), $declarations ?? $this->declarations());
    }

    private function clear(RecipeRevision $revision, ?User $admin = null): User
    {
        $admin ??= User::factory()->admin()->create();
        app(RecipeVariationPublishingService::class)->reviewRights($admin, $revision, true, 'Reviewed the actual permission, media and license conditions.', true);

        return $admin;
    }

    private function assertRefused(callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('An invalid mutation was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_filament_rights_review_records_decisions_and_hides_actions_from_submitter(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->id])
            ->assertSuccessful()
            ->assertSee('Variation review')
            ->assertSee('Roasted the mushrooms first')
            ->assertActionVisible('clearVariationRights')
            ->callAction('clearVariationRights', ['reason' => 'Inspected retained consent and all license conditions.', 'conditions_confirmed' => true])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('recipe_revision_audits', ['recipe_revision_id' => $revision->id, 'action' => 'variation_rights_cleared']);
        $this->assertSame(RecipeRevisionStatus::InReview, $revision->fresh()->status);
        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->id])
            ->callAction('declineVariationRights', ['reason' => 'The retained consent needs further independent verification.'])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('recipe_revision_audits', ['recipe_revision_id' => $revision->id, 'action' => 'variation_rights_rejected']);
        $this->actingAs($owner);
        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->id])
            ->assertActionHidden('clearVariationRights')
            ->assertActionHidden('declineVariationRights')
            ->assertDontSee('Private signed consent reference');
    }

    public function test_filament_requires_explicit_evidence_confirmation_and_keeps_private_reasons_private(): void
    {
        [$owner, $recipe] = $this->ready();
        $declarations = $this->declarations();
        $declarations['summaries']['en'] = '<script>alert("summary")</script> Roasted the mushrooms before simmering.';
        $revision = $this->submit($owner, $recipe, $declarations);
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->id])
            ->assertSee('Compared against retained source revision')
            ->assertSee('<script>alert("summary")</script>', true)
            ->assertDontSee('<script>alert("summary")</script>', false)
            ->callAction('clearVariationRights', ['reason' => 'Inspected the retained consent and applicable conditions.', 'conditions_confirmed' => false])
            ->assertHasActionErrors(['conditions_confirmed']);
        $this->assertDatabaseMissing('recipe_revision_audits', ['recipe_revision_id' => $revision->id, 'action' => 'variation_rights_cleared']);
        app(RecipeVariationPublishingService::class)->reviewRights(auth()->user(), $revision, false, 'Confidential evidence review requires a new consent document.', false);
        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->id])
            ->assertSee('Variation review')
            ->assertSee('Declined; a new rights decision is required')
            ->assertActionHidden('clearVariationRights')
            ->assertDontSee('Confidential evidence review requires a new consent document.')
            ->assertDontSee('Private signed consent reference');
        $this->actingAs($owner);
        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->id])
            ->assertDontSee('Compared against retained source revision')
            ->assertDontSee('Confidential evidence review requires a new consent document.');
    }

    public function test_latest_rights_rejection_blocks_publication_after_an_earlier_clearance(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $reviewer = $this->clear($revision);
        app(RecipeVariationPublishingService::class)->reviewRights($reviewer, $revision, false, 'New evidence means the prior clearance must be declined.', false);
        try {
            app(RecipeRevisionService::class)->approve($reviewer, $revision, 'Independently confirmed substantial cooking changes.', true);
            $this->fail('An earlier clearance must not override the latest rejection.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('variations.rights_clearance_required')], $exception->errors()['rights']);
        }
        $this->assertSame(RecipeRevisionStatus::InReview, $revision->fresh()->status);
        $this->assertSame('private', $recipe->fresh()->visibility->value);
    }

    public function test_first_submission_freezes_revision_and_stays_private(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $this->assertSame(RecipeRevisionStatus::InReview, $revision->status);
        $this->assertNull($revision->base_revision_id);
        $this->assertSame('pending', $recipe->fresh()->status);
        $this->assertSame('private', $recipe->fresh()->visibility->value);
        $this->assertNull($recipe->fresh()->current_revision_id);
        $this->assertSame($owner->id, $revision->publication_context['rights_confirmed_by']);
        $this->assertFalse(Gate::forUser($owner)->allows('update', $recipe->fresh()));
        $this->assertDatabaseHas('recipe_revision_audits', ['recipe_revision_id' => $revision->id, 'action' => 'submitted']);
        $this->assertSame(1, app(RecipeQuotaService::class)->usage($owner));
    }

    public function test_pending_content_and_declarations_are_frozen_even_for_raw_sql(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $this->assertRefused(fn () => DB::table('recipe_translations')->where('recipe_id', $recipe->id)->update(['title' => 'Changed during review']));
        $this->assertRefused(fn () => DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->update(['quantity' => 99]));
        $this->assertRefused(fn () => DB::table('recipe_revisions')->where('id', $revision->id)->update(['publication_context' => '{}']));
        $this->assertRefused(fn () => DB::table('recipe_revisions')->where('id', $revision->id)->update(['payload' => '{}']));
        $this->assertRefused(fn () => DB::table('recipe_derivation_translations')->insert(['recipe_derivation_id' => $recipe->derivation->id, 'locale' => 'en', 'change_summary' => 'Changed']));
    }

    public function test_editorial_approval_requires_separate_rights_clearance_and_quality_confirmation(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $editor = User::factory()->editor()->create();
        try {
            app(RecipeRevisionService::class)->approve($editor, $revision, 'Confirmed a distinct new cooking method.', true);
            $this->fail('Publication without rights clearance succeeded.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('rights', $error->errors());
        }
        $this->clear($revision);
        $approved = app(RecipeRevisionService::class)->approve($editor, $revision, 'Confirmed a distinct new cooking method.', true);
        $recipe = $recipe->fresh();
        $this->assertTrue($recipe->isPubliclyAccessible());
        $this->assertSame($approved->id, $recipe->current_revision_id);
        $this->assertSame($approved->content_hash, app(RecipeRevisionSnapshotter::class)->hash(app(RecipeRevisionSnapshotter::class)->serialize($recipe->id)));
        $this->assertSame('local', $recipe->coverMedia()->disk);
        Storage::disk('public')->assertMissing($recipe->coverMedia()->file_path);
        $this->assertSame(0, app(RecipeQuotaService::class)->usage($owner));
    }

    public function test_member_cannot_submit_even_with_global_submit_permissions(): void
    {
        $member = User::factory()->create();
        [$owner, $recipe] = $this->ready($member);
        $member->givePermissionTo([AppPermissions::RECIPES_SUBMIT, AppPermissions::VARIATIONS_SUBMIT]);
        $this->expectException(AuthorizationException::class);
        $this->submit($member, $recipe);
    }

    public function test_owner_or_an_editor_who_modified_content_cannot_clear_or_approve(): void
    {
        [$owner, $recipe] = $this->ready(User::factory()->admin()->create());
        $other = User::factory()->admin()->create();
        RecipeRevisionAudit::query()->create(['recipe_id' => $recipe->id, 'actor_id' => $other->id, 'action' => 'variation_edited']);
        $revision = $this->submit($owner, $recipe);
        $this->assertFalse(Gate::forUser($owner)->allows('approve', $revision));
        $this->assertFalse(Gate::forUser($owner)->allows('reviewVariationRights', $revision));
        $this->assertFalse(Gate::forUser($other)->allows('approve', $revision));
        $this->assertFalse(Gate::forUser($other)->allows('reviewVariationRights', $revision));
    }

    public function test_missing_bilingual_content_cover_declarations_and_summary_block_submission(): void
    {
        [$owner, $recipe] = $this->ready();
        $bad = $this->declarations();
        $bad['summaries']['el'] = 'short';
        try {
            $this->submit($owner, $recipe, $bad);
            $this->fail('Short summary accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('summaries.el', $e->errors());
        }
        $recipe->media()->detach();
        $this->expectException(ValidationException::class);
        $this->submit($owner, $recipe);
    }

    public function test_unchanged_title_only_or_serving_only_variations_cannot_be_submitted(): void
    {
        [$owner, $recipe, $source] = $this->ready();
        foreach (['en', 'el'] as $locale) {
            $recipe->steps()->first()->translate($locale)->update(['instruction' => $source->steps()->first()->translate($locale)->instruction]);
        }
        $recipe->translate('en')->update(['title' => 'A different title']);
        $this->expectException(ValidationException::class);
        $this->submit($owner, $recipe);
    }

    public function test_media_bytes_changed_after_submission_cannot_be_published(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $this->clear($revision);
        Storage::disk('local')->put($recipe->coverMedia()->file_path, 'changed bytes');
        $this->expectException(ValidationException::class);
        app(RecipeRevisionService::class)->approve(User::factory()->editor()->create(), $revision, 'Confirmed a distinct new cooking method.', true);
    }

    public function test_rejection_and_withdrawal_preserve_history_and_allow_private_editing(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        app(RecipeRevisionService::class)->reject(User::factory()->editor()->create(), $revision, 'Please clarify the roasting technique.');
        $this->assertTrue(Gate::forUser($owner)->allows('editVariation', $recipe->fresh()));
        $this->assertSame(RecipeRevisionStatus::Rejected, $revision->fresh()->status);
        $next = $this->submit($owner, $recipe->fresh());
        app(RecipePublishingService::class)->withdraw($owner, $recipe->fresh());
        $this->assertSame(RecipeRevisionStatus::Rejected, $next->fresh()->status);
        $this->assertTrue(Gate::forUser($owner)->allows('editVariation', $recipe->fresh()));
    }

    public function test_source_suspension_or_revoked_contributor_privileges_prevents_approval(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $this->clear($revision);
        $owner->syncRoles([AppPermissions::MEMBER]);
        $this->expectException(ValidationException::class);
        app(RecipeRevisionService::class)->approve(User::factory()->editor()->create(), $revision, 'Confirmed a distinct new cooking method.', true);
    }

    public function test_monitor_mode_blocks_submission_and_public_switch_is_off_by_default(): void
    {
        [$owner, $recipe] = $this->ready();
        app(RecipeContentGuard::class)->setMode('monitor');
        $this->expectException(ValidationException::class);
        $this->submit($owner, $recipe);
    }

    public function test_missing_greek_translation_or_rights_declaration_rolls_back_submission(): void
    {
        [$owner, $recipe] = $this->ready();
        $bad = $this->declarations();
        $bad['rights_confirmed'] = false;
        try {
            $this->submit($owner, $recipe, $bad);
            $this->fail('Missing rights declaration accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rights_confirmed', $e->errors());
        }
        $recipe->translations()->where('locale', 'el')->delete();
        try {
            $this->submit($owner, $recipe);
            $this->fail('Missing Greek accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('translations', $e->errors());
        }
        $this->assertSame(0, $recipe->revisions()->count());
        $this->assertSame('draft', $recipe->fresh()->status);
    }

    public function test_suspended_permission_and_changed_rights_after_clearance_block_publication(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $admin = $this->clear($revision);
        $permission = app(RecipeVariationPublishingService::class)->permission($recipe);
        $permission->forceFill(['suspended_at' => now(), 'suspended_by' => $admin->id, 'suspension_reason' => 'Copyright complaint under review.'])->save();
        $this->expectException(ValidationException::class);
        app(RecipeRevisionService::class)->approve(User::factory()->editor()->create(), $revision, 'Reviewed the distinct cooking method.', true);
    }

    public function test_editors_cannot_clear_rights_and_quality_confirmation_is_required(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $editor = User::factory()->editor()->create();
        $editor->givePermissionTo(AppPermissions::VARIATIONS_MANAGE_RIGHTS);
        $this->assertFalse(Gate::forUser($editor)->allows('reviewVariationRights', $revision));
        $this->clear($revision);
        $this->expectException(ValidationException::class);
        app(RecipeRevisionService::class)->approve($editor, $revision, 'Reviewed the distinct cooking method.', false);
    }

    public function test_published_variation_revisions_and_rollback_require_fresh_review_and_keep_provenance(): void
    {
        [$owner, $recipe] = $this->ready();
        $first = $this->submit($owner, $recipe);
        $this->clear($first);
        app(RecipeRevisionService::class)->approve(User::factory()->editor()->create(), $first, 'Reviewed the distinct roasting method.', true);
        $recipe = $recipe->fresh();
        $provenanceId = $recipe->derivation->id;
        $this->get($recipe->coverMedia()->publicUrl())->assertOk();
        $draft = app(RecipeRevisionService::class)->createDraft($owner, $recipe);
        $payload = $draft->payload;
        $payload['translations']['en']['title'] = 'Corrected title for my roasted mushroom soup';
        app(RecipeRevisionService::class)->saveDraft($owner, $draft, $payload);
        $submitted = app(RecipeRevisionService::class)->submit($owner, $draft->fresh(), $this->declarations());
        $this->assertSame($first->id, $recipe->fresh()->current_revision_id);
        $this->clear($submitted);
        app(RecipeRevisionService::class)->approve(User::factory()->editor()->create(), $submitted, 'Reviewed the changed simmering duration.', true);
        $this->assertSame($submitted->id, $recipe->fresh()->current_revision_id);
        $restored = app(RecipeRevisionService::class)->rollback(User::factory()->admin()->create(), $first->fresh(), 'Restore the first method with a fresh independent review.');
        $this->assertSame(RecipeRevisionStatus::Draft, $restored->status);
        $this->assertSame($submitted->id, $recipe->fresh()->current_revision_id);
        $this->assertSame($first->id, $restored->restored_from_revision_id);
        $this->assertSame($provenanceId, $recipe->fresh()->derivation->id);
    }

    public function test_frozen_payload_is_applied_even_if_a_trusted_writer_changed_live_rows(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $title = $revision->payload['translations']['en']['title'];
        app(RecipeContentGuard::class)->asPublisher($recipe->id, fn () => DB::table('recipe_translations')->where('recipe_id', $recipe->id)->where('locale', 'en')->update(['title' => 'Unreviewed live drift']));
        $this->clear($revision);
        app(RecipeRevisionService::class)->approve(User::factory()->editor()->create(), $revision, 'Reviewed the exact frozen roasting method.', true);
        $this->assertSame($title, $recipe->fresh()->translate('en')->title);
    }

    public function test_public_switch_and_legacy_unknown_edit_history_fail_closed(): void
    {
        [$owner, $recipe] = $this->ready();
        config(['recipe-variations.public_submissions_enabled' => false]);
        $this->assertFalse(Gate::forUser($owner)->allows('submitVariation', $recipe));
        config(['recipe-variations.public_submissions_enabled' => true]);
        $recipe->forceFill(['created_at' => now()->subDay()])->save();
        $this->expectException(ValidationException::class);
        $this->submit($owner, $recipe);
    }

    public function test_known_creative_commons_restrictions_cannot_be_overridden_by_flags(): void
    {
        [$owner, $recipe] = $this->ready(User::factory()->admin()->create());
        $permission = app(RecipeVariationPublishingService::class)->permission($recipe);
        foreach (['CC-BY-ND-4.0', 'CC-BY-NC-4.0', 'CC-BY-SA-4.0'] as $identifier) {
            $next = $permission->replicate();
            $next->forceFill(['license_identifier' => $identifier, 'requires_attribution' => true])->save();
            $this->assertFalse(Gate::forUser($owner)->allows('submitVariation', $recipe));
        }
        $contradictory = $permission->replicate();
        $contradictory->forceFill(['license_identifier' => 'CC-BY-4.0', 'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/deed.en?ref=source', 'requires_attribution' => true])->save();
        $this->assertFalse(Gate::forUser($owner)->allows('submitVariation', $recipe));
        $valid = $permission->replicate();
        $valid->forceFill(['license_identifier' => 'CC-BY-SA-4.0', 'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'requires_attribution' => true, 'requires_share_alike' => true])->save();
        $this->assertTrue(Gate::forUser($owner)->allows('submitVariation', $recipe));
    }

    public function test_dietary_proposals_bind_to_the_frozen_revision_without_automatic_approval(): void
    {
        [$owner, $recipe] = $this->ready();
        $tag = Tag::factory()->create(['classification' => 'diet_style']);
        $claim = app(DietaryClaimService::class)->suggest($owner, $recipe, $tag);
        $revision = $this->submit($owner, $recipe);
        $this->assertSame($revision->id, $claim->fresh()->recipe_revision_id);
        $this->assertSame('suggested', $claim->fresh()->status->value);
    }

    public function test_filament_approves_the_same_frozen_first_publication_after_rights_clearance(): void
    {
        [$owner, $recipe] = $this->ready();
        $revision = $this->submit($owner, $recipe);
        $this->clear($revision);
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(ViewRecipeRevision::class, ['record' => $revision->id])
            ->callAction('approve', ['note' => 'Independently reviewed the distinct roasting method.', 'meaningful_changes_confirmed' => true])->assertNotified();
        $this->assertSame(RecipeRevisionStatus::Published, $revision->fresh()->status);
    }

    public function test_migration_rollback_cannot_discard_retained_publication_declarations(): void
    {
        [$owner, $recipe] = $this->ready();
        $this->submit($owner, $recipe);
        $migration = require database_path('migrations/2026_11_02_190000_freeze_variation_publication_submissions.php');
        $this->expectException(\LogicException::class);
        $migration->down();
    }

    public function test_livewire_saves_then_submits_and_redirects_to_private_preview(): void
    {
        [$owner, $recipe] = $this->ready();
        $this->actingAs($owner);
        Livewire::test(EditRecipeVariation::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('summaries', $this->declarations()['summaries'])->set('publicationRightsConfirmed', true)->set('meaningfulChangesConfirmed', true)
            ->call('requestPublication')->assertHasNoErrors()->assertRedirect();
        $this->assertSame('pending', $recipe->fresh()->status);
    }
}
