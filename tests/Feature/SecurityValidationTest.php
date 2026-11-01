<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SecurityValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_role_column_is_gone_and_roles_come_from_spatie(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(User::factory()->editor()->create()->hasRole('editor'));
    }

    public function test_registration_cannot_assign_roles_or_permissions(): void
    {
        $this->post('/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
            'roles' => ['admin'],
            'permissions' => ['recipes.publish'],
        ]);

        $user = User::where('email', 'mallory@example.com')->firstOrFail();

        $this->assertSame(['member'], $user->getRoleNames()->all());
        $this->assertCount(0, $user->getDirectPermissions());
        $this->assertFalse($user->isStaff());
    }

    public function test_every_admin_page_is_closed_to_guests_members_and_contributors(): void
    {
        $paths = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'admin') && in_array('GET', $route->methods(), true))
            ->reject(fn ($route): bool => str_contains($route->uri(), '{') || str_contains($route->uri(), 'login'))
            ->map(fn ($route): string => '/'.$route->uri());

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->get($path)->assertRedirect();
        }

        foreach ([User::factory()->create(), User::factory()->contributor()->create()] as $user) {
            foreach ($paths as $path) {
                $this->actingAs($user)->get($path)->assertForbidden();
            }
        }
    }

    public function test_only_public_published_recipes_appear_in_the_sitemap_and_listing(): void
    {
        $public = Recipe::factory()->published()->create();
        $private = Recipe::factory()->complete()->create();
        $pending = Recipe::factory()->complete()->pending()->create();
        $shared = Recipe::factory()->complete()->shared()->create();

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString($public->translate('en')->slug, $sitemap);

        foreach ([$private, $pending, $shared] as $hidden) {
            $this->assertStringNotContainsString($hidden->translate('en')->slug, $sitemap);
            $this->get('/en/recipes/'.$hidden->translate('en')->slug)->assertNotFound();
        }
    }

    public function test_private_recipes_are_hidden_from_public_search(): void
    {
        $private = Recipe::factory()->complete()->create();

        $this->get('/en?search='.urlencode($private->translate('en')->title))
            ->assertOk()
            ->assertDontSee($private->translate('en')->title);
    }
}
