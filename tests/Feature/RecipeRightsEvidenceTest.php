<?php

namespace Tests\Feature;

use App\Models\RecipeReusePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesVariationSource;
use Tests\TestCase;

class RecipeRightsEvidenceTest extends TestCase
{
    use CreatesVariationSource;
    use RefreshDatabase;

    private function evidence(): RecipeReusePermission
    {
        Storage::fake('recipe_rights');
        Storage::disk('recipe_rights')->put('consent.txt', 'Retained private consent');
        $original = $this->source()->reusePermissions()->first();
        $permission = $original->replicate();
        $permission->forceFill(['supersedes_id' => $original->id, 'evidence_path' => 'consent.txt', 'evidence_sha256' => hash('sha256', 'Retained private consent')])->save();

        return $permission;
    }

    public function test_verified_administrator_can_download_retained_evidence_without_caching(): void
    {
        $permission = $this->evidence();
        $response = $this->actingAs(User::factory()->admin()->create())->get(route('recipes.rights-evidence', $permission));
        $response->assertOk()->assertDownload('rights-evidence-'.$permission->id.'.bin');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('Retained private consent', $response->streamedContent());
    }

    public function test_guest_and_contributor_cannot_download_private_evidence(): void
    {
        $permission = $this->evidence();
        $url = route('recipes.rights-evidence', $permission);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->contributor()->create())->get($url)->assertForbidden();
    }

    public function test_editor_member_and_unverified_administrator_cannot_download_evidence(): void
    {
        $permission = $this->evidence();
        $url = route('recipes.rights-evidence', $permission);
        $this->actingAs(User::factory()->editor()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create())->get($url)->assertRedirect(route('verification.notice'));
    }

    public function test_changed_evidence_is_refused_instead_of_served(): void
    {
        $permission = $this->evidence();
        Storage::disk('recipe_rights')->put('consent.txt', 'Changed consent');
        $this->actingAs(User::factory()->admin()->create())->get(route('recipes.rights-evidence', $permission))->assertStatus(409);
    }

    public function test_missing_evidence_returns_not_found(): void
    {
        $permission = $this->evidence();
        Storage::disk('recipe_rights')->delete('consent.txt');
        $this->actingAs(User::factory()->admin()->create())->get(route('recipes.rights-evidence', $permission))->assertNotFound();
    }
}
