<?php

namespace Tests\Feature;

use App\Livewire\PersonalRecipeNotes;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use App\Models\UserRecipeNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PersonalRecipeNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_update_and_delete_their_recipe_notes(): void
    {
        $user = User::factory()->create();
        $recipe = $this->recipe($user);

        Livewire::actingAs($user)->test(PersonalRecipeNotes::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('body', 'Add lemon zest before serving.')
            ->set('lastCookedOn', '2026-10-25')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('user_recipe_notes', [
            'user_id' => $user->id,
            'recipe_id' => $recipe->id,
            'body' => 'Add lemon zest before serving.',
            'last_cooked_on' => '2026-10-25',
        ]);

        Livewire::actingAs($user)->test(PersonalRecipeNotes::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->set('body', 'Use a smaller pan.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, UserRecipeNote::query()->where('user_id', $user->id)->where('recipe_id', $recipe->id)->count());
        $this->assertDatabaseHas('user_recipe_notes', [
            'user_id' => $user->id,
            'recipe_id' => $recipe->id,
            'body' => 'Use a smaller pan.',
        ]);

        Livewire::actingAs($user)->test(PersonalRecipeNotes::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('user_recipe_notes', ['user_id' => $user->id, 'recipe_id' => $recipe->id]);
    }

    public function test_note_is_private_from_recipe_author_and_administrator(): void
    {
        $author = User::factory()->contributor()->create();
        $noteOwner = User::factory()->create();
        $recipe = $this->recipe($author, 'shared');
        RecipeShare::create(['recipe_id' => $recipe->id, 'user_id' => $noteOwner->id, 'shared_by' => $author->id]);
        $note = $noteOwner->recipeNotes()->create([
            'recipe_id' => $recipe->id,
            'body' => 'Private note content.',
        ]);
        $administrator = User::factory()->admin()->create();

        $this->assertFalse($author->can('view', $note));
        $this->assertFalse($administrator->can('view', $note));

        Livewire::actingAs($author)->test(PersonalRecipeNotes::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->assertDontSee('Private note content.')
            ->assertSee(__('site.notes_private'));

        Livewire::actingAs($administrator)->test(PersonalRecipeNotes::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->assertDontSee('Private note content.');
    }

    public function test_notes_remain_saved_when_recipe_access_is_revoked(): void
    {
        $author = User::factory()->contributor()->create();
        $recipient = User::factory()->create();
        $recipe = $this->recipe($author, 'shared');
        $share = RecipeShare::create(['recipe_id' => $recipe->id, 'user_id' => $recipient->id, 'shared_by' => $author->id]);
        $note = $recipient->recipeNotes()->create([
            'recipe_id' => $recipe->id,
            'body' => 'Keep this note private.',
        ]);

        $share->update(['revoked_at' => now()]);

        $this->assertDatabaseHas('user_recipe_notes', ['id' => $note->id, 'body' => 'Keep this note private.']);
        Livewire::actingAs($recipient)->test(PersonalRecipeNotes::class, ['recipeId' => $recipe->id, 'locale' => 'en'])
            ->assertNotFound();
    }

    public function test_guests_do_not_receive_notes_in_public_recipe_html(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = $this->recipe($author);
        $recipe->forceFill(['status' => 'published', 'visibility' => 'public', 'published_at' => now()])->saveQuietly();
        $author->recipeNotes()->create(['recipe_id' => $recipe->id, 'body' => 'Only in my account.']);

        $this->get('/en/recipes/'.$recipe->getTranslation('en')->slug)
            ->assertOk()
            ->assertDontSee('Only in my account.');
    }

    public function test_authenticated_recipe_pages_are_not_shared_cached_and_show_their_own_note(): void
    {
        $author = User::factory()->contributor()->create();
        $recipe = $this->recipe($author);
        $recipe->forceFill(['status' => 'published', 'visibility' => 'public', 'published_at' => now()])->saveQuietly();
        $author->recipeNotes()->create(['recipe_id' => $recipe->id, 'body' => 'Only in my account.']);

        $response = $this->actingAs($author)->get('/en/recipes/'.$recipe->getTranslation('en')->slug);

        $response->assertOk()->assertSee('Only in my account.');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    private function recipe(User $author, string $visibility = 'private'): Recipe
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $author->id,
            'visibility' => $visibility,
            'status' => 'draft',
        ]);
        $recipe->fill([
            'en' => ['title' => 'Private supper', 'slug' => 'private-supper-'.$recipe->id],
            'el' => ['title' => 'Ιδιωτικό δείπνο', 'slug' => 'idiotiko-deipno-'.$recipe->id],
        ])->save();

        return $recipe;
    }
}
