<?php

namespace Tests\Feature;

use App\Actions\StoreRecipeMedia;
use App\Enums\RecipeVisibility;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use App\Services\RecipePublishingService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeAccessTest extends TestCase
{
    use RefreshDatabase;

    private function privateRecipe(User $owner, string $visibility = 'private', string $slug = 'secret-dish'): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $owner->id, 'visibility' => $visibility]);
        $recipe->fill(['en' => ['title' => 'Secret dish', 'slug' => $slug, 'summary' => 'Only for me.']])->save();

        return $recipe;
    }

    private function share(Recipe $recipe, User $recipient): RecipeShare
    {
        return RecipeShare::create(['recipe_id' => $recipe->id, 'user_id' => $recipient->id, 'shared_by' => $recipe->author_id]);
    }

    private function url(Recipe $recipe, string $locale = 'en'): string
    {
        return "/{$locale}/recipes/".$recipe->getTranslation($locale, false)->slug;
    }

    public function test_owner_can_read_their_private_recipe_with_noindex_headers(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->privateRecipe($owner);

        $this->actingAs($owner)->get($this->url($recipe))
            ->assertOk()
            ->assertSee('Secret dish')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('noindex, nofollow, noarchive', false)
            ->assertDontSee('application/ld+json', false);
    }

    public function test_guests_and_other_members_get_404_for_private_recipes(): void
    {
        $recipe = $this->privateRecipe(User::factory()->contributor()->create());

        $this->get($this->url($recipe))->assertNotFound();
        $this->actingAs(User::factory()->create())->get($this->url($recipe))->assertNotFound();
        $this->actingAs(User::factory()->contributor()->create())->get($this->url($recipe))->assertNotFound();
    }

    public function test_editors_do_not_get_blanket_access_to_private_recipes(): void
    {
        $editor = User::factory()->editor()->create();
        $recipe = $this->privateRecipe(User::factory()->contributor()->create());

        $this->assertFalse($editor->can('view', $recipe));
        $this->assertFalse($editor->can('update', $recipe));
        $this->actingAs($editor)->get($this->url($recipe))->assertNotFound();
    }

    public function test_editors_can_manage_the_editorial_pool(): void
    {
        $editor = User::factory()->editor()->create();
        $author = User::factory()->contributor()->create();

        $this->assertTrue($editor->can('update', Recipe::factory()->published()->create(['author_id' => $author->id])));
        $this->assertTrue($editor->can('update', Recipe::factory()->pending()->create(['author_id' => $author->id])));
        $this->assertTrue($editor->can('update', $this->privateRecipe($editor)));
    }

    public function test_admin_moderation_access_is_explicit_and_audited(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create();
        $recipe = $this->privateRecipe(User::factory()->contributor()->create());

        $this->assertTrue($admin->can('view', $recipe));

        $this->actingAs($admin)->get($this->url($recipe))->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'audit.private_recipe_moderation_access'
            && $context['recipe_id'] === $recipe->id
            && ! array_key_exists('title', $context))->once();
    }

    public function test_contributor_edits_own_draft_but_not_other_peoples_recipes(): void
    {
        $contributor = User::factory()->contributor()->create();

        $this->assertTrue($contributor->can('update', $this->privateRecipe($contributor)));
        $this->assertFalse($contributor->can('update', $this->privateRecipe(User::factory()->contributor()->create(), slug: 'other')));
    }

    public function test_contributor_cannot_edit_their_recipe_while_it_is_public(): void
    {
        $contributor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->published()->create(['author_id' => $contributor->id]);

        $this->assertFalse($contributor->can('update', $recipe));
        $this->assertFalse($contributor->can('delete', $recipe));
    }

    public function test_contributor_cannot_publish_or_approve(): void
    {
        $contributor = User::factory()->contributor()->create();
        $recipe = Recipe::factory()->pending()->create(['author_id' => $contributor->id]);

        $this->assertFalse($contributor->can('publish', $recipe));
        $this->assertFalse($contributor->can('approve', $recipe));
        $this->assertFalse($contributor->can('changeVisibility', [$recipe, RecipeVisibility::Public]));
    }

    public function test_reviewer_cannot_approve_their_own_submission(): void
    {
        $editor = User::factory()->editor()->create();

        $this->assertFalse($editor->can('approve', Recipe::factory()->pending()->create(['author_id' => $editor->id])));
        $this->assertTrue($editor->can('approve', Recipe::factory()->pending()->create()));
    }

    public function test_shared_recipe_is_visible_to_invited_users_only(): void
    {
        $recipient = User::factory()->create();
        $recipe = $this->privateRecipe(User::factory()->contributor()->create(), 'shared');
        $this->share($recipe, $recipient);

        $this->actingAs($recipient)->get($this->url($recipe))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->actingAs(User::factory()->create())->get($this->url($recipe))->assertNotFound();
        auth()->logout();
        $this->get($this->url($recipe))->assertNotFound();
    }

    public function test_revoked_share_removes_access(): void
    {
        $recipient = User::factory()->create();
        $recipe = $this->privateRecipe(User::factory()->contributor()->create(), 'shared');
        $share = $this->share($recipe, $recipient);

        $share->update(['revoked_at' => now()]);

        $this->assertFalse($recipient->can('view', $recipe));
        $this->actingAs($recipient)->get($this->url($recipe))->assertNotFound();
    }

    public function test_making_a_shared_recipe_private_suspends_grants_without_deleting_them(): void
    {
        $recipient = User::factory()->create();
        $recipe = $this->privateRecipe(User::factory()->contributor()->create(), 'shared');
        $this->share($recipe, $recipient);

        app(RecipePublishingService::class)->changeVisibility($recipe, RecipeVisibility::Private);

        $this->assertFalse($recipient->can('view', $recipe->fresh()));
        $this->assertSame(1, $recipe->shares()->active()->count());

        app(RecipePublishingService::class)->changeVisibility($recipe->fresh(), RecipeVisibility::Shared);

        $this->assertTrue($recipient->can('view', $recipe->fresh()));
    }

    public function test_duplicate_active_shares_are_rejected_but_a_revoked_one_can_be_regranted(): void
    {
        $recipient = User::factory()->create();
        $recipe = $this->privateRecipe(User::factory()->contributor()->create(), 'shared');
        $share = $this->share($recipe, $recipient);

        try {
            DB::transaction(fn () => $this->share($recipe, $recipient));
            $this->fail('A duplicate active share was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $share->update(['revoked_at' => now()]);
        $this->share($recipe, $recipient);

        $this->assertSame(1, $recipe->shares()->active()->count());
    }

    public function test_only_the_owner_with_share_permission_can_share(): void
    {
        $owner = User::factory()->contributor()->create();
        $recipe = $this->privateRecipe($owner);

        $this->assertTrue($owner->can('share', $recipe));
        $this->assertTrue($owner->can('revokeShare', $recipe));
        $this->assertFalse(User::factory()->contributor()->create()->can('share', $recipe));
        $this->assertFalse(User::factory()->create()->can('share', $recipe));
        $this->assertFalse(User::factory()->admin()->create()->can('share', $recipe));
    }

    public function test_public_recipes_are_visible_to_guests_until_made_private(): void
    {
        $recipe = Recipe::factory()->published()->create();

        $this->get($this->url($recipe))->assertOk()->assertDontSee('noindex', false);

        app(RecipePublishingService::class)->changeVisibility($recipe, RecipeVisibility::Private);

        $this->get($this->url($recipe))->assertNotFound();
    }

    public function test_unpublishing_makes_a_public_recipe_private_in_the_same_write(): void
    {
        $recipe = Recipe::factory()->published()->create();

        app(RecipePublishingService::class)->unpublish($recipe);

        $this->assertSame(RecipeVisibility::Private, $recipe->fresh()->visibility);
        $this->get($this->url($recipe))->assertNotFound();
    }

    public function test_publishing_makes_the_recipe_public(): void
    {
        $recipe = Recipe::factory()->complete()->create();

        $this->assertSame(RecipeVisibility::Private, $recipe->fresh()->visibility);

        app(RecipePublishingService::class)->publish($recipe);

        $this->assertSame(RecipeVisibility::Public, $recipe->fresh()->visibility);
    }

    public function test_going_public_requires_published_status_and_complete_content(): void
    {
        $service = app(RecipePublishingService::class);
        $draft = $this->privateRecipe(User::factory()->contributor()->create());

        try {
            $service->changeVisibility($draft, RecipeVisibility::Public);
            $this->fail('A draft was made public.');
        } catch (ValidationException) {
            $this->assertSame(RecipeVisibility::Private, $draft->fresh()->visibility);
        }

        // Published but never given ingredients, steps or a cover, so the completeness gate must refuse it.
        $incomplete = Recipe::factory()->published()->create(['visibility' => 'private']);

        $this->expectException(ValidationException::class);
        $service->changeVisibility($incomplete, RecipeVisibility::Public);
    }

    public function test_approval_publishes_and_rejection_keeps_the_recipe_non_public(): void
    {
        $service = app(RecipePublishingService::class);
        $reviewer = User::factory()->editor()->create();

        $approved = $service->approve(Recipe::factory()->complete()->pending()->create(), $reviewer)->fresh();
        $this->assertSame('published', $approved->status);
        $this->assertSame(RecipeVisibility::Public, $approved->visibility);
        $this->assertSame($reviewer->id, $approved->reviewed_by);

        $rejected = $service->reject(Recipe::factory()->complete()->pending()->create(), $reviewer, 'Needs better photos')->fresh();
        $this->assertSame('draft', $rejected->status);
        $this->assertSame(RecipeVisibility::Private, $rejected->visibility);
        $this->assertSame('Needs better photos', $rejected->rejection_reason);
        $this->get($this->url($rejected))->assertNotFound();
    }

    public function test_approval_of_incomplete_content_is_blocked(): void
    {
        $recipe = Recipe::factory()->pending()->create();
        $recipe->fill(['en' => ['title' => 'Half done', 'slug' => 'half-done']])->save();

        $this->expectException(ValidationException::class);

        app(RecipePublishingService::class)->approve($recipe, User::factory()->editor()->create());
    }

    public function test_rejection_requires_a_reason(): void
    {
        $this->expectException(ValidationException::class);

        app(RecipePublishingService::class)->reject(Recipe::factory()->pending()->create(), User::factory()->editor()->create(), ' ');
    }

    public function test_database_rejects_invalid_status_visibility_and_public_drafts(): void
    {
        $recipe = Recipe::factory()->create();

        foreach ([['status' => 'bogus'], ['visibility' => 'bogus'], ['visibility' => 'public']] as $invalid) {
            try {
                DB::table('recipes')->where('id', $recipe->id)->update($invalid);
                $this->fail('Invalid recipe state was stored: '.json_encode($invalid));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_model_refuses_a_public_recipe_that_is_not_published(): void
    {
        $this->expectException(ValidationException::class);

        Recipe::factory()->create(['visibility' => 'public', 'status' => 'draft']);
    }

    public function test_private_recipes_may_have_one_language_and_still_render(): void
    {
        $owner = User::factory()->contributor()->create();
        $this->privateRecipe($owner);
        $greekOnly = Recipe::factory()->create(['author_id' => $owner->id]);
        $greekOnly->fill(['el' => ['title' => 'Μόνο ελληνικά', 'slug' => 'mono-ellinika']])->save();

        $this->actingAs($owner)->get('/en/recipes/secret-dish')->assertOk();
        $this->actingAs($owner)->get('/el/recipes/mono-ellinika')->assertOk()->assertSee('Μόνο ελληνικά');
    }

    public function test_language_switching_does_not_bypass_authorization(): void
    {
        $recipe = $this->privateRecipe(User::factory()->contributor()->create());
        $recipe->fill(['el' => ['title' => 'Μυστικό', 'slug' => 'mystiko']])->save();

        $this->actingAs(User::factory()->create())->get('/el/recipes/mystiko')->assertNotFound();
    }

    public function test_non_public_recipes_are_excluded_from_listings_and_the_sitemap(): void
    {
        $owner = User::factory()->contributor()->create();
        $private = $this->privateRecipe($owner);
        $shared = $this->privateRecipe($owner, 'shared', 'shared-dish');
        $public = Recipe::factory()->published()->create();

        $ids = Recipe::query()->published()->pluck('id')->all();

        $this->assertSame([$public->id], $ids);
        $this->assertNotContains($private->id, $ids);
        $this->assertNotContains($shared->id, $ids);

        $this->get('/sitemap.xml')->assertOk()->assertDontSee('secret-dish')->assertDontSee('shared-dish');
    }

    public function test_panel_lists_only_manageable_recipes(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament table rendering requires the PHP intl extension.');
        }

        Filament::setCurrentPanel('admin');
        $editor = User::factory()->editor()->create();
        $others = $this->privateRecipe(User::factory()->contributor()->create());
        $own = $this->privateRecipe($editor, slug: 'editor-own');
        $public = Recipe::factory()->published()->create();
        $pending = Recipe::factory()->pending()->create();

        Livewire::actingAs($editor)->test(ListRecipes::class)
            ->assertCanSeeTableRecords([$own, $public, $pending])
            ->assertCanNotSeeTableRecords([$others]);

        Livewire::actingAs(User::factory()->admin()->create())->test(ListRecipes::class)
            ->assertCanSeeTableRecords([$own, $public, $pending, $others]);
    }

    private function pngContent(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('N2C5', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat(str_repeat("\0", $width * 3 + 1), $height)))
            .$chunk('IEND', '');
    }

    private function uploadTo(Recipe $recipe, string $role = 'cover'): Media
    {
        return app(StoreRecipeMedia::class)->handle($recipe, UploadedFile::fake()->createWithContent('dish.png', $this->pngContent(800, 600)), [
            'en' => ['alt_text' => 'A dish'],
            'el' => ['alt_text' => 'Ένα πιάτο'],
        ], $role);
    }

    public function test_private_uploads_go_to_the_private_disk_and_are_not_publicly_reachable(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $owner = User::factory()->contributor()->create();
        $recipe = $this->privateRecipe($owner);

        $media = $this->uploadTo($recipe);

        $this->assertSame('local', $media->disk);
        Storage::disk('local')->assertExists($media->file_path);
        Storage::disk('public')->assertMissing($media->file_path);
        $this->assertSame(route('media.show', $media, false), $media->publicUrl());
        $this->assertStringNotContainsString($media->file_path, $media->publicUrl());

        $this->get($media->publicUrl())->assertNotFound();
        $this->actingAs(User::factory()->create())->get($media->publicUrl())->assertNotFound();
        $this->actingAs($owner)->get($media->publicUrl())->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_page_html_never_exposes_private_storage_paths(): void
    {
        Storage::fake('local');
        $owner = User::factory()->contributor()->create();
        $recipe = $this->privateRecipe($owner);
        $media = $this->uploadTo($recipe);

        $this->actingAs($owner)->get($this->url($recipe))
            ->assertOk()
            ->assertSee(route('media.show', $media, false), false)
            ->assertDontSee($media->file_path, false)
            ->assertDontSee('signature=', false);
    }

    public function test_shared_media_follows_share_authorization_and_revocation(): void
    {
        Storage::fake('local');
        $recipient = User::factory()->create();
        $recipe = $this->privateRecipe(User::factory()->contributor()->create(), 'shared');
        $media = $this->uploadTo($recipe);
        $share = $this->share($recipe, $recipient);

        $this->actingAs($recipient)->get($media->publicUrl())->assertOk();
        $this->actingAs(User::factory()->create())->get($media->publicUrl())->assertNotFound();

        $share->update(['revoked_at' => now()]);

        $this->actingAs($recipient)->get($media->publicUrl())->assertNotFound();
    }

    public function test_media_moves_to_the_public_disk_on_publication_and_back_when_made_private(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $recipe = Recipe::factory()->complete()->create();
        $media = $this->uploadTo($recipe, 'gallery');
        $service = app(RecipePublishingService::class);

        $service->publish($recipe);

        $media->refresh();
        $this->assertSame('public', $media->disk);
        Storage::disk('public')->assertExists($media->file_path);
        Storage::disk('local')->assertMissing($media->file_path);
        $this->assertSame(Storage::disk('public')->url($media->file_path), $media->publicUrl());

        $service->changeVisibility($recipe->fresh(), RecipeVisibility::Private);

        $media->refresh();
        $this->assertSame('local', $media->disk);
        Storage::disk('public')->assertMissing($media->file_path);
        Storage::disk('local')->assertExists($media->file_path);
    }

    public function test_remote_images_are_never_served_through_the_private_route(): void
    {
        $media = Media::factory()->remote()->create();

        $this->actingAs(User::factory()->admin()->create())->get(route('media.show', $media, false))->assertNotFound();
    }
}
