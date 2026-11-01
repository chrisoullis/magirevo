<?php

namespace Tests\Feature;

use App\Filament\Resources\Households\HouseholdResource;
use App\Filament\Resources\Households\Pages\ListHouseholds;
use App\Models\Household;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use Tests\TestCase;

class PlannerAdminAndLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admins_see_households_read_only(): void
    {
        $owner = User::factory()->create(['name' => 'Olive Owner']);
        $household = Household::factory()->ownedBy($owner)->create(['name' => 'Casa Test']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListHouseholds::class)->assertOk()->assertSee('Casa Test')->assertSee('Olive Owner')->assertCanSeeTableRecords([$household]);
        $this->assertFalse(HouseholdResource::canCreate());
        $this->assertFalse(HouseholdResource::canEdit($household));
        $this->assertFalse(HouseholdResource::canDelete($household));
    }

    public function test_non_admins_cannot_open_the_households_admin_page(): void
    {
        Household::factory()->ownedBy(User::factory()->create())->create();

        foreach ([User::factory()->create(), User::factory()->editor()->create(), User::factory()->contributor()->create()] as $user) {
            $this->actingAs($user)->get('/admin/households')->assertForbidden();
        }
    }

    public function test_every_planner_and_household_string_exists_in_both_languages(): void
    {
        foreach (['planner', 'households'] as $file) {
            $en = Arr::dot(trans($file, [], 'en'));
            $el = Arr::dot(trans($file, [], 'el'));

            $this->assertSame([], array_keys(array_diff_key($en, $el)), "Greek is missing keys in {$file}.");
            $this->assertSame([], array_keys(array_diff_key($el, $en)), "English is missing keys in {$file}.");

            foreach ($el as $key => $value) {
                $this->assertNotSame('', trim((string) $value), "Empty Greek string {$file}.{$key}");
                preg_match_all('/:[a-z_]+/i', (string) ($en[$key] ?? ''), $enPlaceholders);
                preg_match_all('/:[a-z_]+/i', (string) $value, $elPlaceholders);
                $this->assertEqualsCanonicalizing($enPlaceholders[0], $elPlaceholders[0], "Placeholder mismatch in {$file}.{$key}");
            }
        }
    }
}
