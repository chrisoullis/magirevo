<?php

namespace App\Services\Recipes;

use App\Enums\TagClassification;
use App\Models\Category;
use App\Models\Recipe;
use App\Models\RecipeDerivation;
use App\Models\RecipeRevision;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RecipeQuotaService;
use App\Services\RecipeRevisionSnapshotter;
use App\Support\AppPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecipeForkService
{
    public function __construct(
        private RecipeQuotaService $quota,
        private RecipeVariationRights $rights,
        private RecipeRevisionSnapshotter $snapshots,
        private RecipeVariationContent $content,
        private RecipeVariationText $text,
        private AuditLogger $audit,
    ) {}

    public function fork(User $user, Recipe $source, array $options = []): Recipe
    {
        $validated = Validator::make($options, ['idempotency_key' => ['required', 'uuid']])->validate();
        $key = $validated['idempotency_key'];
        $fingerprint = hash('sha256', 'recipe-fork:v1:'.$source->getKey());

        return DB::transaction(function () use ($user, $source, $key, $fingerprint): Recipe {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(config('recipe-variations.enabled') && $user->hasVerifiedEmail() && $user->can(AppPermissions::RECIPES_FORK), 403);
            $previous = RecipeDerivation::query()->where('created_by', $user->id)->where('idempotency_key', $key)->first();
            if ($previous !== null) {
                abort_unless(hash_equals($previous->request_fingerprint, $fingerprint), 409);
                $destination = $previous->recipe;
                Gate::forUser($user)->authorize('view', $destination);
                abort_if($destination->trashed(), 404);

                return $destination;
            }

            $source = Recipe::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('fork', $source);
            $permission = $this->rights->current($source);
            $permission = $permission->newQuery()->whereKey($permission->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('fork', $source);
            $revision = RecipeRevision::query()->whereKey($source->current_revision_id)->where('recipe_id', $source->id)->where('status', 'published')->firstOrFail();
            if (! hash_equals($revision->content_hash, $this->snapshots->hash($revision->payload))) {
                throw ValidationException::withMessages(['source' => __('variations.source_unavailable')]);
            }

            return $this->quota->withinPersonalQuota($user, function () use ($user, $source, $permission, $revision, $key, $fingerprint): Recipe {
                $payload = $revision->payload;
                unset($payload['media']);
                foreach (['ingredient_groups', 'ingredients', 'steps'] as $collection) {
                    foreach ($payload[$collection] as &$row) {
                        unset($row['id'], $row['media']);
                    }
                    unset($row);
                }
                foreach ($payload['translations'] as &$translation) {
                    unset($translation['slug']);
                    foreach ($translation as &$value) {
                        $value = $this->text->clean($value);
                    }
                    unset($value);
                }
                unset($translation);
                foreach ($payload['steps'] as &$step) {
                    foreach ($step['translations'] as &$translation) {
                        $translation['instruction'] = $this->text->clean($translation['instruction']);
                    }
                    unset($translation);
                }
                unset($step);
                foreach ($payload['ingredients'] as &$line) {
                    foreach ($line['translations'] as &$translation) {
                        foreach ($translation as &$value) {
                            $value = $this->text->clean($value);
                        }
                        unset($value);
                    }
                    unset($translation);
                }
                unset($line);
                foreach ($payload['ingredient_groups'] as &$group) {
                    $group['names'] = array_map(fn ($value) => $this->text->clean($value), $group['names']);
                }
                unset($group);
                $payload['category_ids'] = Category::query()->publiclyVisible()->whereIn('id', $payload['category_ids'])->pluck('id')->all();
                $payload['tag_ids'] = Tag::query()->where('is_active', true)->whereIn('classification', TagClassification::freelyPublicValues())->whereIn('id', $payload['tag_ids'])->pluck('id')->all();

                $recipe = new Recipe(['author_id' => $user->id, 'visibility' => 'private', 'status' => 'draft']);
                $recipe->derived_from_recipe_id = $source->id;
                $recipe->save();
                $this->content->write($recipe, $payload);
                $provenance = new RecipeDerivation;
                $provenance->forceFill([
                    'recipe_id' => $recipe->id,
                    'source_recipe_revision_id' => $revision->id,
                    'source_title_snapshot' => $revision->payload['translations']['en']['title'] ?? null,
                    'source_author_snapshot' => $source->author?->name,
                    'source_url_snapshot' => route('recipes.show', ['locale' => 'en', 'slug' => $revision->payload['translations']['en']['slug']]),
                    'source_license_snapshot' => [
                        'identifier' => $permission->license_identifier, 'url' => $permission->license_url,
                        'conditions' => $permission->license_conditions,
                        'allows_sharing' => $permission->allows_sharing,
                        'allows_public_adaptation' => $permission->allows_public_adaptation,
                        'requires_attribution' => $permission->requires_attribution,
                        'requires_share_alike' => $permission->requires_share_alike,
                        'allows_commercial_use' => $permission->allows_commercial_use,
                    ],
                    'attribution_snapshot' => ['disclosure_allowed' => $permission->allows_public_attribution],
                    'source_rights_basis' => $permission->rights_basis,
                    'source_permission_reference' => $permission->permission_reference,
                    'source_permission_evidence_id' => $permission->id,
                    'created_by' => $user->id, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                ])->save();
                $this->audit->record('recipe_variation_created', ['recipe_id' => $recipe->id, 'source_revision_id' => $revision->id, 'created_by' => $user->id]);

                return $recipe->refresh();
            });
        });
    }
}
