<?php

namespace Tests\Feature;

use App\Livewire\RecipeIndex;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeAndAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_visitors_can_browse_recipes_and_filter_the_catalog(): void
    {
        $response = $this->get(route('home.localized', ['locale' => 'en']))
            ->assertOk()
            ->assertSee('What would you like to cook today?')
            ->assertSee('Crispy chickpeas');

        $response->assertSee('href="/login"', false);
        $this->get('/login')->assertOk()->assertSee('action="/login"', false);

        Livewire::test(RecipeIndex::class)
            ->set('search', 'miso')
            ->assertSee('Brown butter miso noodles')
            ->assertDontSee('Olive oil citrus cake');
    }

    public function test_visitors_can_view_recipe_details_with_structured_data(): void
    {
        $recipe = Recipe::whereTranslation('slug', 'brown-butter-miso-noodles', 'en')->firstOrFail();

        $this->get(route('recipes.show', [
            'locale' => 'en',
            'slug' => $recipe->translate('en')->slug,
        ]))
            ->assertOk()
            ->assertSee('application/ld+json', false)
            ->assertSee('Ingredients')
            ->assertSee('make it.');
    }

    public function test_visitors_can_view_a_recipe_using_its_greek_slug_and_translation(): void
    {
        $recipe = Recipe::whereTranslation('slug', 'brown-butter-miso-noodles', 'en')->firstOrFail();

        $this->get(route('recipes.show', [
            'locale' => 'el',
            'slug' => $recipe->translate('el')->slug,
        ]))
            ->assertOk()
            ->assertSee('<html lang="el">', false)
            ->assertSee('Νουντλς με καμένο βούτυρο και μίσο');
    }

    public function test_a_recipe_slug_is_not_available_in_the_other_locale(): void
    {
        $recipe = Recipe::whereTranslation('slug', 'brown-butter-miso-noodles', 'en')->firstOrFail();

        $this->get(route('recipes.show', [
            'locale' => 'el',
            'slug' => $recipe->translate('en')->slug,
        ]))->assertNotFound();
    }

    public function test_public_recipe_routes_only_accept_supported_locales(): void
    {
        $this->get('/fr')->assertNotFound();
    }

    public function test_livewire_catalog_rejects_an_unsupported_locale(): void
    {
        Livewire::test(RecipeIndex::class)
            ->set('locale', 'fr')
            ->assertStatus(404);
    }

    public function test_sitemap_lists_published_recipes_as_xml(): void
    {
        $recipe = Recipe::whereTranslation('slug', 'brown-butter-miso-noodles', 'en')->firstOrFail();

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertHeader('content-type', 'application/xml')
            ->assertSee(route('recipes.show', [
                'locale' => 'en',
                'slug' => $recipe->translate('en')->slug,
            ]), false)
            ->assertSee(route('recipes.show', [
                'locale' => 'el',
                'slug' => $recipe->translate('el')->slug,
            ]), false);
    }

    public function test_publishing_requires_both_english_and_greek_titles_and_slugs(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('English and Greek titles and slugs are required before publishing a recipe.');

        Recipe::create($this->recipeAttributes([
            'en' => ['title' => 'English title', 'slug' => 'english-title'],
        ], 'published'));
    }

    public function test_publishing_requires_an_english_translation_even_when_greek_is_present(): void
    {
        $this->expectException(ValidationException::class);

        Recipe::create($this->recipeAttributes([
            'el' => ['title' => 'Ελληνικός τίτλος', 'slug' => 'greek-title'],
        ], 'published'));
    }

    public function test_publishing_requires_non_empty_translation_titles_and_slugs(): void
    {
        $this->expectException(ValidationException::class);

        Recipe::create($this->recipeAttributes([
            'en' => ['title' => 'English title', 'slug' => 'english-title'],
            'el' => ['title' => '', 'slug' => 'greek-title'],
        ], 'published'));
    }

    public function test_a_recipe_with_both_translations_can_be_published(): void
    {
        $recipe = Recipe::create($this->recipeAttributes([
            'en' => ['title' => 'English title', 'slug' => 'english-title'],
            'el' => ['title' => 'Ελληνικός τίτλος', 'slug' => 'greek-title'],
        ], 'published'));

        $this->assertDatabaseHas('recipes', [
            'id' => $recipe->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('recipe_translations', [
            'recipe_id' => $recipe->id,
            'locale' => 'en',
            'slug' => 'english-title',
        ]);
        $this->assertDatabaseHas('recipe_translations', [
            'recipe_id' => $recipe->id,
            'locale' => 'el',
            'slug' => 'greek-title',
        ]);
    }

    public function test_locale_keyed_form_attributes_are_saved_to_their_translation_rows(): void
    {
        $attributes = $this->recipeAttributes([]);
        $attributes['title:en'] = 'Form English title';
        $attributes['slug:en'] = 'form-english-title';
        $attributes['title:el'] = 'Τίτλος από τη φόρμα';
        $attributes['slug:el'] = 'form-greek-title';

        $recipe = Recipe::create($attributes);

        $this->assertDatabaseHas('recipe_translations', [
            'recipe_id' => $recipe->id,
            'locale' => 'en',
            'title' => 'Form English title',
            'slug' => 'form-english-title',
        ]);
        $this->assertDatabaseHas('recipe_translations', [
            'recipe_id' => $recipe->id,
            'locale' => 'el',
            'title' => 'Τίτλος από τη φόρμα',
            'slug' => 'form-greek-title',
        ]);
    }

    public function test_permanently_deleting_a_recipe_cascades_to_translations_but_soft_delete_preserves_them(): void
    {
        $recipe = Recipe::create($this->recipeAttributes([
            'en' => ['title' => 'English title', 'slug' => 'english-title'],
            'el' => ['title' => 'Ελληνικός τίτλος', 'slug' => 'greek-title'],
        ]));

        $recipe->delete();

        $this->assertDatabaseHas('recipe_translations', [
            'recipe_id' => $recipe->id,
            'locale' => 'en',
        ]);

        $recipe->forceDelete();

        $this->assertDatabaseMissing('recipe_translations', [
            'recipe_id' => $recipe->id,
        ]);
    }

    public function test_database_rejects_translation_locales_other_than_english_or_greek(): void
    {
        $recipe = Recipe::create($this->recipeAttributes([]));

        $this->expectException(QueryException::class);

        $recipe->translations()->create([
            'locale' => 'fr',
            'title' => 'French title',
            'slug' => 'french-title',
        ]);
    }

    public function test_registration_logs_in_user_and_sends_verification_email(): void
    {
        Notification::fake();

        $response = $this->post(route('register'), [
            'name' => 'Jamie Baker',
            'email' => 'jamie@example.com',
            'password' => 'table-password-42',
            'password_confirmation' => 'table-password-42',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'jamie@example.com', 'email_verified_at' => null]);
        Notification::assertSentTo(User::where('email', 'jamie@example.com')->firstOrFail(), VerifyEmail::class);
    }

    public function test_registration_verification_link_uses_forwarded_https_host_and_verifies_email(): void
    {
        Notification::fake();
        $headers = [
            'X-Forwarded-Host' => 'recipes.example.test',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Proto' => 'https',
        ];

        $response = $this->post('/register', [
            'name' => 'Jamie Baker',
            'email' => 'jamie@example.com',
            'password' => 'table-password-42',
            'password_confirmation' => 'table-password-42',
        ], $headers);

        $response->assertRedirect('https://recipes.example.test/email/verify');

        $user = User::where('email', 'jamie@example.com')->firstOrFail();
        $verificationUrl = null;

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$verificationUrl): bool {
            $verificationUrl = $notification->toMail($user)->actionUrl;

            return true;
        });

        $url = parse_url($verificationUrl);

        $this->assertSame('https', $url['scheme']);
        $this->assertSame('recipes.example.test', $url['host']);

        $this->get($url['path'].'?'.$url['query'], $headers)
            ->assertRedirect('/en?verified=1');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_members_can_log_in_with_their_email_and_password(): void
    {
        $password = 'table-password-42';
        User::factory()->create([
            'email' => 'member@example.com',
            'password' => $password,
        ]);

        $this->post(route('login'), [
            'email' => 'member@example.com',
            'password' => $password,
        ])->assertRedirect(route('home.localized', ['locale' => 'en']));

        $this->assertAuthenticated();
    }

    public function test_signed_verification_link_marks_email_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('home.localized', ['locale' => 'en']).'?verified=1');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verified_members_can_save_and_view_recipes(): void
    {
        $user = User::factory()->create();
        $recipe = Recipe::whereTranslation('slug', 'brown-butter-miso-noodles', 'en')->firstOrFail();

        $this->actingAs($user)
            ->post(route('recipes.save', ['locale' => 'en', 'recipe' => $recipe]))
            ->assertRedirect();

        $this->assertDatabaseHas('recipe_favorites', [
            'user_id' => $user->id,
            'recipe_id' => $recipe->id,
        ]);

        $this->get(route('recipes.saved', ['locale' => 'en']))->assertOk()->assertSee($recipe->title);
    }

    public function test_unverified_members_must_verify_before_saving_recipes(): void
    {
        $user = User::factory()->unverified()->create();
        $recipe = Recipe::whereTranslation('slug', 'brown-butter-miso-noodles', 'en')->firstOrFail();

        $this->actingAs($user)
            ->post(route('recipes.save', ['locale' => 'en', 'recipe' => $recipe]))
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseMissing('recipe_favorites', [
            'user_id' => $user->id,
            'recipe_id' => $recipe->id,
        ]);
    }

    /**
     * @param  array<string, array<string, string>>  $translations
     * @return array<string, mixed>
     */
    private function recipeAttributes(array $translations, string $status = 'draft'): array
    {
        return [
            'author_id' => User::factory()->create()->getKey(),
            ...$translations,
            'status' => $status,
            'prep_minutes' => 10,
            'cook_minutes' => 15,
            'rest_minutes' => 0,
            'base_servings' => 2,
            'difficulty' => 'Easy',
            'published_at' => $status === 'published' ? now() : null,
        ];
    }

    public function test_verified_administrators_can_open_the_filament_recipe_editor(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament page rendering requires the PHP intl extension.');
        }

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/recipes')
            ->assertOk()
            ->assertSee('Recipes');
    }

    public function test_unverified_administrators_cannot_open_filament(): void
    {
        $admin = User::factory()->admin()->unverified()->create();

        $this->actingAs($admin)->get('/admin/recipes')->assertForbidden();
    }
}
