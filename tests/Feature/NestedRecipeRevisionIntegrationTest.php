<?php

namespace Tests\Feature;

use App\Enums\RecipeRevisionStatus;
use App\Enums\RecipeVisibility;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Models\RecipeYield;
use App\Models\Unit;
use App\Models\User;
use App\Services\RecipeAccessService;
use App\Services\RecipeComponentResolver;
use App\Services\RecipeContentGuard;
use App\Services\RecipePublishingService;
use App\Services\RecipeRevisionApplier;
use App\Services\RecipeRevisionComparer;
use App\Services\RecipeRevisionService;
use App\Services\RecipeRevisionSnapshotter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NestedRecipeRevisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_is_private_immutable_history_and_does_not_publish(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $revision = app(RecipeRevisionService::class)->captureComponentSnapshot($recipe->author, $recipe);

        $this->assertSame(RecipeRevisionStatus::Retained, $revision->status);
        $this->assertSame(RecipeRevisionSnapshotter::SCHEMA_VERSION, $revision->schema_version);
        $this->assertSame('draft', $recipe->fresh()->status);
        $this->assertSame(RecipeVisibility::Private, $recipe->fresh()->visibility);
        $this->assertNull($recipe->fresh()->current_revision_id);
        $this->assertNull($revision->published_at);
        $this->assertNotEmpty($revision->media()->get());
        $this->expectException(QueryException::class);
        $revision->update(['payload' => []]);
    }

    #[TestWith(['draft'])]
    #[TestWith(['in_review'])]
    public function test_capture_with_an_open_revision_preserves_the_open_revision_and_indexes_live_components(string $status): void
    {
        $actor = User::factory()->create();
        $service = app(RecipeRevisionService::class);
        $childRecipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $child = $service->captureComponentSnapshot($actor, $childRecipe);
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $line = RecipeIngredient::factory()->for($recipe)->component($child)->create();
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshotter->serialize($recipe->id);
        $payload['translations']['en']['title'] = 'Unsubmitted title';
        $open = RecipeRevision::query()->create([
            'recipe_id' => $recipe->id,
            'revision_number' => 1,
            'status' => $status,
            'payload' => $payload,
            'schema_version' => 2,
            'content_hash' => $snapshotter->hash($payload),
            'created_by' => $actor->id,
        ]);
        $before = $open->refresh()->getRawOriginal();
        $retained = $service->captureComponentSnapshot($actor, $recipe);

        $this->assertSame(RecipeRevisionStatus::Retained, $retained->status);
        $this->assertNotSame('Unsubmitted title', $retained->payload['translations']['en']['title']);
        $this->assertSame($before, $open->fresh()->getRawOriginal());
        $this->assertDatabaseHas('recipe_revision_dependencies', [
            'recipe_revision_id' => $retained->id,
            'line_uuid' => $line->line_uuid,
            'component_recipe_revision_id' => $child->id,
        ]);
    }

    public function test_capture_reuses_identical_retained_content_but_preserves_changes_as_new_history(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $service = app(RecipeRevisionService::class);
        $first = $service->captureComponentSnapshot($recipe->author, $recipe);
        $same = $service->captureComponentSnapshot($recipe->author, $recipe);
        $this->assertSame($first->id, $same->id);
        $this->assertSame(1, RecipeRevision::query()->where('recipe_id', $recipe->id)->count());

        $recipe->increment('prep_minutes');
        $changed = $service->captureComponentSnapshot($recipe->author, $recipe);
        $this->assertNotSame($first->id, $changed->id);
        $this->assertSame(
            app(RecipeRevisionSnapshotter::class)->canonicalJson($first->payload),
            app(RecipeRevisionSnapshotter::class)->canonicalJson($first->fresh()->payload),
        );
        $this->assertSame($first->content_hash, $first->fresh()->content_hash);
    }

    public function test_capture_does_not_reuse_a_snapshot_when_its_media_metadata_changes(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $service = app(RecipeRevisionService::class);
        $first = $service->captureComponentSnapshot($recipe->author, $recipe);
        DB::table('media')->where('id', $recipe->media()->first()->id)->update(['credit' => 'Updated credit']);
        $changed = $service->captureComponentSnapshot($recipe->author, $recipe);

        $this->assertSame($first->content_hash, $changed->content_hash);
        $this->assertNotSame($first->id, $changed->id);
        $this->assertNotSame($first->media_snapshot_hash, $changed->media_snapshot_hash);
    }

    public function test_retained_dependencies_cannot_be_appended_after_capture(): void
    {
        $actor = User::factory()->create();
        $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $service = app(RecipeRevisionService::class);
        $retained = $service->captureComponentSnapshot($actor, $recipe);
        $child = $service->captureComponentSnapshot($actor, $source);
        $this->expectException(QueryException::class);
        DB::table('recipe_revision_dependencies')->insert([
            'recipe_revision_id' => $retained->id,
            'line_uuid' => (string) Str::uuid(),
            'component_recipe_id' => $source->id,
            'component_recipe_revision_id' => $child->id,
        ]);
    }

    public function test_capture_requires_view_permission(): void
    {
        $recipe = Recipe::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(RecipeRevisionService::class)->captureComponentSnapshot(User::factory()->create(), $recipe);
    }

    public function test_retained_component_graph_is_indexed_before_freeze(): void
    {
        $actor = User::factory()->create();
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $child = app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $source);
        $parent = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $line = RecipeIngredient::factory()->for($parent)->component($child)->create();
        $revision = app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $parent);

        $this->assertDatabaseHas('recipe_revision_dependencies', [
            'recipe_revision_id' => $revision->id,
            'line_uuid' => $line->line_uuid,
            'component_recipe_revision_id' => $child->id,
            'component_recipe_id' => $source->id,
        ]);
        $this->expectException(QueryException::class);
        DB::table('recipe_revision_dependencies')->where('recipe_revision_id', $revision->id)->delete();
    }

    public function test_publication_indexes_published_components_and_keeps_history_pinned(): void
    {
        $service = app(RecipePublishingService::class);
        $source = $service->publish(Recipe::factory()->complete()->create());
        $sourceRevision = $source->currentRevision;
        $parent = Recipe::factory()->complete()->create();
        $line = RecipeIngredient::factory()->for($parent)->component($sourceRevision)->create();
        $parent = $service->publish($parent);
        $revision = $parent->currentRevision;

        $this->assertSame(RecipeRevisionStatus::Published, $revision->status);
        $this->assertDatabaseHas('recipe_revision_dependencies', [
            'recipe_revision_id' => $revision->id,
            'line_uuid' => $line->line_uuid,
            'component_recipe_revision_id' => $sourceRevision->id,
        ]);
        $this->assertSame($sourceRevision->id, collect($revision->payload['ingredients'])->firstWhere('line_uuid', $line->line_uuid)['component_recipe_revision_id']);
    }

    public function test_newly_published_lines_resolve_stable_live_ids_without_rewriting_frozen_payloads(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $source = app(RecipePublishingService::class)->publish(Recipe::factory()->complete()->create(['author_id' => $actor->id]));
        $service = app(RecipeRevisionService::class);
        $draft = $service->createDraft($actor, $source);
        $payload = $draft->payload;
        $line = $payload['ingredients'][0];
        $line['id'] = null;
        $line['line_uuid'] = (string) Str::uuid();
        $line['sort_order'] = 1;
        $payload['ingredients'][] = $line;
        $published = $service->approve(User::factory()->admin()->create(), $service->submit($actor, $service->saveDraft($actor, $draft, $payload)));
        $before = $published->getRawOriginal();

        $result = app(RecipeComponentResolver::class)->resolve($published, $actor);

        $this->assertNull($published->payload['ingredients'][1]['id']);
        $this->assertSame($published->resolved_ids['ingredients'][1], $result['lines'][1]->source['recipe_ingredient_id']);
        $this->assertSame($before, $published->fresh()->getRawOriginal());
    }

    public function test_publication_rejects_private_retained_component_sources(): void
    {
        $actor = User::factory()->create();
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $child = app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $source);
        $parent = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        RecipeIngredient::factory()->for($parent)->component($child)->create();
        $this->expectException(ValidationException::class);
        app(RecipePublishingService::class)->assertPublishable($parent, $actor);
    }

    public function test_snapshot_pins_every_referenced_unit_and_preserves_definitions_on_normalize(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $yield = RecipeYield::factory()->for($recipe)->create();
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshotter->serialize($recipe->id);
        $unit = $yield->unit;

        $this->assertArrayHasKey($unit->id, $payload['units']);
        $this->assertSame($unit->code, $payload['units'][$unit->id]['code']);
        $this->assertSame(['basis', 'quantity', 'unit_id', 'evidence', 'verified_by', 'verified_at'], array_keys($payload['yields'][0]));
        $payload['units'][$unit->id]['base_conversion'] = '123';
        $normalized = $snapshotter->normalize($payload);
        $this->assertSame('123', $normalized['units'][$unit->id]['base_conversion']);
        $this->assertSame($snapshotter->hash($normalized), $snapshotter->hash($snapshotter->normalize($normalized)));
    }

    public function test_component_prepared_units_and_fields_affect_hash_and_diff(): void
    {
        $actor = User::factory()->create();
        $source = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $child = app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $source);
        $parent = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $preparedUnit = Unit::factory()->create();
        $line = RecipeIngredient::factory()->for($parent)->component($child)->create([
            'shopping_mode' => 'prepared',
            'prepared_ingredient_id' => Ingredient::factory()->create()->id,
            'prepared_quantity_per_batch' => '200',
            'prepared_unit_id' => $preparedUnit->id,
        ]);
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $before = $snapshotter->serialize($parent->id);
        $this->assertArrayHasKey($preparedUnit->id, $before['units']);
        $after = $before;
        $index = array_search($line->id, array_column($after['ingredients'], 'id'));
        $after['ingredients'][$index]['component_recipe_revision_id']++;
        $this->assertNotSame($snapshotter->hash($before), $snapshotter->hash($after));
        $this->assertNotEmpty(app(RecipeRevisionComparer::class)->compare($before, $after));
    }

    public function test_apply_restores_yields_and_stable_line_uuid_after_deletion(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        RecipeYield::factory()->for($recipe)->create();
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshotter->normalize($snapshotter->serialize($recipe->id));
        $revision = app(RecipeRevisionService::class)->captureComponentSnapshot($recipe->author, $recipe);
        DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->delete();
        DB::table('recipe_yields')->where('recipe_id', $recipe->id)->delete();
        app(RecipeContentGuard::class)->asPublisher($recipe->id, fn () => app(RecipeRevisionApplier::class)->apply($recipe->id, $payload, $revision->id));
        $this->assertSame($payload['ingredients'][0]['line_uuid'], $recipe->recipeIngredients()->first()->line_uuid);
        $this->assertSame($snapshotter->hash($payload), $snapshotter->hash($snapshotter->serialize($recipe->id)));
    }

    public function test_legacy_projection_keeps_the_original_payload_hash(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $legacy = $snapshotter->forSchema($snapshotter->normalize($snapshotter->serialize($recipe->id)), 1);
        $hash = $snapshotter->hash($legacy);
        $upgraded = $snapshotter->normalize($snapshotter->rebaseIds($legacy, $recipe->id));
        $this->assertArrayNotHasKey('yields', $legacy);
        $this->assertArrayNotHasKey('units', $legacy);
        $this->assertArrayNotHasKey('line_uuid', $legacy['ingredients'][0]);
        $this->assertSame($hash, $snapshotter->hash($snapshotter->forSchema($upgraded, 1)));
        $this->assertSame($recipe->recipeIngredients()->first()->line_uuid, $upgraded['ingredients'][0]['line_uuid']);
    }

    public function test_capture_rejects_an_indirect_cycle_through_immutable_history(): void
    {
        $actor = User::factory()->create();
        $first = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        $firstSnapshot = app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $first);
        $second = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        RecipeIngredient::factory()->for($second)->component($firstSnapshot)->create();
        $secondSnapshot = app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $second);
        RecipeIngredient::factory()->for($first)->component($secondSnapshot)->create();

        $access = app(RecipeAccessService::class);
        $this->assertTrue($access->canViewDirect($actor, $first));
        $this->assertFalse($access->canView($actor, $first));
        $before = RecipeRevision::query()->where('recipe_id', $first->id)->count();
        try {
            app(RecipeRevisionService::class)->captureComponentSnapshot(User::factory()->create(), $first);
            $this->fail('A stranger must not receive graph validation details.');
        } catch (AuthorizationException) {
            $this->assertSame($before, RecipeRevision::query()->where('recipe_id', $first->id)->count());
        }

        $this->expectException(ValidationException::class);
        app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $first);
    }

    public function test_capture_rejects_a_graph_beyond_the_resolver_depth_limit(): void
    {
        $actor = User::factory()->create();
        $child = null;
        for ($depth = 0; $depth <= RecipeComponentResolver::MAX_DEPTH; $depth++) {
            $recipe = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
            if ($child !== null) {
                RecipeIngredient::factory()->for($recipe)->component($child)->create();
            }
            $child = app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $recipe);
        }
        $parent = Recipe::factory()->complete()->create(['author_id' => $actor->id]);
        RecipeIngredient::factory()->for($parent)->component($child)->create();
        $this->expectException(ValidationException::class);
        app(RecipeRevisionService::class)->captureComponentSnapshot($actor, $parent);
    }

    public function test_copy_rebases_line_identity_but_restoration_preserves_it(): void
    {
        $recipe = Recipe::factory()->complete()->create();
        $copy = Recipe::factory()->create();
        $snapshotter = app(RecipeRevisionSnapshotter::class);
        $payload = $snapshotter->serialize($recipe->id);
        $restored = $snapshotter->rebaseIds($payload, $recipe->id);
        $copied = $snapshotter->rebaseIds($payload, $copy->id);
        $this->assertSame($payload['ingredients'][0]['line_uuid'], $restored['ingredients'][0]['line_uuid']);
        $this->assertNotSame($payload['ingredients'][0]['line_uuid'], $copied['ingredients'][0]['line_uuid']);
        $this->assertTrue(Str::isUuid($copied['ingredients'][0]['line_uuid']));
        $this->assertNull($copied['ingredients'][0]['id']);
    }
}
