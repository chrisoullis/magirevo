<?php

namespace Tests\Support;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeReusePermission;
use App\Models\User;
use App\Services\RecipePublishingService;

trait CreatesVariationSource
{
    private function source(?callable $customize = null): Recipe
    {
        $source = Recipe::factory()->complete()->create();
        foreach ($source->categories as $category) {
            $category->fill([
                'en' => ['name' => 'Soups', 'slug' => 'soups-'.$category->id],
                'el' => ['name' => 'Σούπες', 'slug' => 'soupes-'.$category->id],
            ])->save();
        }
        $video = Media::query()->create([
            'disk' => 'remote', 'source' => 'embed', 'media_type' => 'video',
            'url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'mime_type' => 'video/embed',
        ]);
        $source->steps()->first()->media()->attach($video, ['kind' => 'video', 'sort_order' => 0]);
        if ($customize !== null) {
            $customize($source);
        }
        app(RecipePublishingService::class)->publish($source);
        $reviewer = User::factory()->admin()->create();
        $permission = new RecipeReusePermission;
        $permission->forceFill([
            'recipe_id' => $source->id, 'recipe_revision_id' => $source->fresh()->current_revision_id,
            'mode' => 'personal', 'rights_basis' => 'written_consent',
            'permission_reference' => 'Private signed consent reference',
            'license_conditions' => ['attribution' => 'required'],
            'allows_text_reproduction' => true, 'allows_private_adaptation' => true,
            'allows_commercial_use' => true, 'allows_public_attribution' => true,
            'verification_status' => 'verified', 'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(), 'review_notes' => 'Verified authority and intended use.', 'created_by' => $reviewer->id,
        ])->save();

        return $source->fresh();
    }
}
