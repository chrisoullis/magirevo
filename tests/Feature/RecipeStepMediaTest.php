<?php

namespace Tests\Feature;

use App\Actions\SyncRecipeStepMedia;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Models\Ingredient;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\RecipeStep;
use App\Models\User;
use App\Support\TrustedVideoUrl;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeStepMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_editor_uploads_orders_and_removes_optimized_step_photos_and_saves_video_metadata(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament page rendering requires the PHP intl extension.');
        }

        Storage::fake('local');
        Filament::setCurrentPanel('admin');
        $editor = User::factory()->admin()->create();
        [$recipe] = $this->recipeWithOneStep($editor);

        $firstPhoto = UploadedFile::fake()->createWithContent('first.png', $this->pngContent(2400, 1600));
        $secondPhoto = UploadedFile::fake()->createWithContent('second.png', $this->pngContent(1200, 900));
        $step = $recipe->steps()->firstOrFail();
        $this->actingAs($editor);
        app(SyncRecipeStepMedia::class)->handle($recipe, $step, [
            [
                'upload' => [$firstPhoto],
                'alt_text_en' => 'First photo',
                'caption_en' => 'First English caption',
                'alt_text_el' => 'Πρώτη φωτογραφία',
                'caption_el' => 'Πρώτη λεζάντα',
            ],
            [
                'upload' => [$secondPhoto],
                'alt_text_en' => 'Second photo',
                'caption_en' => 'Second English caption',
                'alt_text_el' => 'Δεύτερη φωτογραφία',
                'caption_el' => 'Δεύτερη λεζάντα',
            ],
        ], null, [
            'en' => ['alt_text' => null, 'caption' => null],
            'el' => ['alt_text' => null, 'caption' => null],
        ]);

        $component = Livewire::actingAs($editor)->test(EditRecipe::class, ['record' => $recipe->getRouteKey()]);
        $steps = $component->get('data.steps_data');
        $stepKey = array_key_first($steps);
        $steps[$stepKey]['video_url'] = 'https://youtu.be/dQw4w9WgXcQ';
        $steps[$stepKey]['video_alt_text_en'] = 'Mixing technique';
        $steps[$stepKey]['video_caption_en'] = 'Mix until smooth.';
        $steps[$stepKey]['video_alt_text_el'] = 'Τεχνική ανάμειξης';
        $steps[$stepKey]['video_caption_el'] = 'Ανακατέψτε μέχρι να γίνει λείο.';

        $component->set('data.steps_data', $steps)
            ->call('save')
            ->assertHasNoFormErrors();

        $step = $recipe->fresh()->steps()->firstOrFail();
        $images = $step->media()->wherePivot('kind', 'image')->get();
        $this->assertSame(2, $images->count());
        $this->assertSame([0, 1], $images->pluck('pivot.sort_order')->all());
        $this->assertSame('image/webp', $images[0]->mime_type);
        $this->assertSame(1920, $images[0]->width);
        $this->assertSame(1280, $images[0]->height);
        $this->assertSame('Πρώτη φωτογραφία', $images[0]->translate('el')->alt_text);
        $this->assertSame('Second English caption', $images[1]->translate('en')->caption);
        Storage::disk('local')->assertExists($images[0]->file_path);
        $this->assertDatabaseHas('recipe_step_media', [
            'recipe_step_id' => $step->id,
            'media_id' => $images[0]->id,
            'kind' => 'image',
            'sort_order' => 0,
        ]);

        $video = $step->media()->wherePivot('kind', 'video')->firstOrFail();
        $this->assertSame(Media::SOURCE_EMBED, $video->source);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $video->url);
        $this->assertSame('Mixing technique', $video->translate('en')->alt_text);
        $this->assertSame('Ανακατέψτε μέχρι να γίνει λείο.', $video->translate('el')->caption);

        $firstImage = $images[0];
        $secondImage = $images[1];
        $edit = Livewire::actingAs($editor)->test(EditRecipe::class, ['record' => $recipe->getRouteKey()]);
        $steps = $edit->get('data.steps_data');
        $stepKey = array_key_first($steps);
        $steps[$stepKey]['media_items'] = array_reverse($steps[$stepKey]['media_items']);
        $edit->set('data.steps_data', $steps)->call('save')->assertHasNoFormErrors();

        $this->assertSame(
            [$secondImage->id, $firstImage->id],
            $step->fresh()->media()->wherePivot('kind', 'image')->pluck('media.id')->all(),
        );

        $edit = Livewire::actingAs($editor)->test(EditRecipe::class, ['record' => $recipe->getRouteKey()]);
        $steps = $edit->get('data.steps_data');
        $stepKey = array_key_first($steps);
        $mediaItems = $steps[$stepKey]['media_items'];
        $keptKey = array_key_first($mediaItems);
        $this->assertSame($secondImage->id, $mediaItems[$keptKey]['media_id']);
        $steps[$stepKey]['media_items'] = [$keptKey => $mediaItems[$keptKey]];
        $edit->set('data.steps_data', $steps)->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseMissing('recipe_step_media', ['recipe_step_id' => $step->id, 'media_id' => $firstImage->id]);
        $this->assertDatabaseMissing('media', ['id' => $firstImage->id]);
        Storage::disk('local')->assertMissing($firstImage->file_path);
    }

    public function test_step_media_rejects_an_image_owned_by_another_recipe(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament page rendering requires the PHP intl extension.');
        }

        Filament::setCurrentPanel('admin');
        $editor = User::factory()->admin()->create();
        [$recipe, $step] = $this->recipeWithOneStep($editor);
        [, $otherStep] = $this->recipeWithOneStep(User::factory()->contributor()->create());
        $foreignImage = Media::factory()->create();
        $foreignImage->translations()->createMany([
            ['locale' => 'en', 'alt_text' => 'Foreign image'],
            ['locale' => 'el', 'alt_text' => 'Ξένη εικόνα'],
        ]);
        $otherStep->media()->attach($foreignImage, ['kind' => 'image', 'sort_order' => 0]);

        $component = Livewire::actingAs($editor)->test(EditRecipe::class, ['record' => $recipe->getRouteKey()]);
        $steps = $component->get('data.steps_data');
        $stepKey = array_key_first($steps);
        $steps[$stepKey]['media_items'] = [[
            'media_id' => $foreignImage->id,
            'upload' => null,
            'alt_text_en' => 'Foreign image',
            'caption_en' => null,
            'alt_text_el' => 'Ξένη εικόνα',
            'caption_el' => null,
        ]];

        $component->set('data.steps_data', $steps)->call('save')->assertHasFormErrors();

        $this->assertDatabaseHas('recipe_step_media', [
            'recipe_step_id' => $otherStep->id,
            'media_id' => $foreignImage->id,
        ]);
        $this->assertDatabaseMissing('recipe_step_media', [
            'recipe_step_id' => $step->id,
            'media_id' => $foreignImage->id,
        ]);
    }

    public function test_step_media_sync_enforces_recipe_update_permission_and_step_ownership(): void
    {
        $owner = User::factory()->contributor()->create();
        $otherUser = User::factory()->contributor()->create();
        [$recipe, $step] = $this->recipeWithOneStep($owner);
        [, $foreignStep] = $this->recipeWithOneStep($otherUser);
        $translations = [
            'en' => ['alt_text' => null, 'caption' => null],
            'el' => ['alt_text' => null, 'caption' => null],
        ];

        $this->actingAs($otherUser);

        try {
            app(SyncRecipeStepMedia::class)->handle($recipe, $step, [], null, $translations);
            $this->fail("A user must not modify another owner's recipe media.");
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('recipe_step_media', 0);
        }

        $this->actingAs($owner);

        try {
            app(SyncRecipeStepMedia::class)->handle($recipe, $foreignStep, [], null, $translations);
            $this->fail('A recipe owner must not attach media to a step from another recipe.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('steps_data', $exception->errors());
        }
    }

    public function test_step_image_route_obeys_private_and_shared_recipe_access(): void
    {
        Storage::fake('local');
        $owner = User::factory()->contributor()->create();
        $recipient = User::factory()->create();
        $otherUser = User::factory()->create();
        [$recipe, $step] = $this->recipeWithOneStep($owner);
        $image = $this->attachImage($step, 'recipe-step-media/private.webp', 'local');
        Storage::disk('local')->put($image->file_path, 'image bytes');

        $this->actingAs($owner)->get(route('media.show', $image))->assertOk();
        $this->actingAs($recipient)->get(route('media.show', $image))->assertNotFound();

        RecipeShare::query()->create([
            'recipe_id' => $recipe->id,
            'user_id' => $recipient->id,
            'shared_by' => $owner->id,
        ]);
        $recipe->update(['visibility' => 'shared']);

        $this->actingAs($recipient)->get(route('media.show', $image))->assertOk();
        $this->actingAs($otherUser)->get(route('media.show', $image))->assertNotFound();
    }

    public function test_public_recipe_renders_localized_step_media_and_structured_data_without_autoplay(): void
    {
        Storage::fake('public');
        $recipe = Recipe::factory()->published()->create();
        $step = $recipe->steps()->create(['step_number' => 1]);
        $step->fill([
            'en' => ['instruction' => 'Fold the batter.'],
            'el' => ['instruction' => 'Διπλώστε το μείγμα.'],
        ])->save();
        $image = $this->attachImage($step, 'recipe-step-media/fold.webp', 'public');
        $image->fill([
            'en' => ['alt_text' => 'Batter being folded', 'caption' => 'Fold gently.'],
            'el' => ['alt_text' => 'Δίπλωμα του μείγματος', 'caption' => 'Διπλώστε απαλά.'],
        ])->save();
        $video = Media::query()->create([
            'disk' => 'remote',
            'media_type' => Media::TYPE_VIDEO,
            'source' => Media::SOURCE_EMBED,
            'url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'mime_type' => 'video/embed',
        ]);
        $video->translations()->createMany([
            ['locale' => 'en', 'alt_text' => 'Folding technique', 'caption' => 'Watch the folding technique.'],
            ['locale' => 'el', 'alt_text' => 'Τεχνική διπλώματος', 'caption' => 'Δείτε την τεχνική διπλώματος.'],
        ]);
        $step->media()->attach($video, ['kind' => 'video', 'sort_order' => 1]);

        $response = $this->get('/en/recipes/'.$recipe->getTranslation('en', false)->slug);

        $response->assertOk()
            ->assertSee('Batter being folded')
            ->assertSee('Fold gently.')
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
            ->assertSee('Start cooking mode')
            ->assertSee('loading="lazy"', false)
            ->assertDontSee('autoplay', false);

        preg_match('/<script type="application\\/ld\\+json">(.*?)<\\/script>/s', (string) $response->getContent(), $matches);
        $structuredData = json_decode($matches[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([$image->publicUrl()], $structuredData[0]['recipeInstructions'][0]['image']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $structuredData[0]['recipeInstructions'][0]['video']['embedUrl']);
    }

    public function test_video_urls_are_reduced_to_safe_provider_embeds(): void
    {
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            TrustedVideoUrl::embedUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ&autoplay=1'),
        );
        $this->assertSame(
            'https://player.vimeo.com/video/123456',
            TrustedVideoUrl::embedUrl('https://vimeo.com/123456?autoplay=1'),
        );
        $this->assertNull(TrustedVideoUrl::embedUrl('https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(TrustedVideoUrl::embedUrl('http://youtu.be/dQw4w9WgXcQ'));
        $this->assertNull(TrustedVideoUrl::embedUrl('https://player.vimeo.com/video/123456/anything'));
        $this->assertNull(TrustedVideoUrl::embedUrl('javascript:alert(1)'));
    }

    public function test_step_media_rejects_more_than_three_images(): void
    {
        $owner = User::factory()->admin()->create();
        [$recipe, $step] = $this->recipeWithOneStep($owner);
        $images = array_fill(0, 4, [
            'upload' => null,
            'alt_text_en' => 'Image',
            'alt_text_el' => 'Εικόνα',
        ]);
        $this->actingAs($owner);

        try {
            app(SyncRecipeStepMedia::class)->handle($recipe, $step, $images, null, [
                'en' => ['alt_text' => null, 'caption' => null],
                'el' => ['alt_text' => null, 'caption' => null],
            ]);
            $this->fail('A step must not accept more than three images.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('images', $exception->errors());
        }

        $this->assertDatabaseCount('recipe_step_media', 0);
    }

    /**
     * @return array{Recipe, RecipeStep}
     */
    private function recipeWithOneStep(User $owner): array
    {
        $recipe = Recipe::factory()->create(['author_id' => $owner->id]);
        $recipe->recipeIngredients()->create(['ingredient_id' => Ingredient::factory()->create()->id]);
        $recipe->fill([
            'en' => ['title' => 'Step media recipe', 'slug' => fake()->unique()->slug()],
            'el' => ['title' => 'Συνταγή με φωτογραφίες', 'slug' => fake()->unique()->slug()],
        ])->save();
        $step = $recipe->steps()->create(['step_number' => 1]);
        $step->fill([
            'en' => ['instruction' => 'Prepare the ingredients.'],
            'el' => ['instruction' => 'Προετοιμάστε τα υλικά.'],
        ])->save();

        return [$recipe, $step];
    }

    private function attachImage(RecipeStep $step, string $path, string $disk): Media
    {
        $media = Media::factory()->create(['disk' => $disk, 'file_path' => $path]);
        $media->translations()->createMany([
            ['locale' => 'en', 'alt_text' => 'Step photo'],
            ['locale' => 'el', 'alt_text' => 'Φωτογραφία βήματος'],
        ]);
        $step->media()->attach($media, ['kind' => 'image', 'sort_order' => 0]);

        return $media;
    }

    private function pngContent(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            .$type
            .$data
            .pack('N', crc32($type.$data));

        $imageData = str_repeat("\0".str_repeat("\0", $width * 3), $height);
        $header = pack('N2C5', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', $header)
            .$chunk('IDAT', gzcompress($imageData))
            .$chunk('IEND', '');
    }
}
