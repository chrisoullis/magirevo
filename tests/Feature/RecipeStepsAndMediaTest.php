<?php

namespace Tests\Feature;

use App\Actions\StoreRecipeMedia;
use App\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Filament\Resources\Recipes\RelationManagers\MediaRelationManager;
use App\Models\Media;
use App\Models\MediaTranslation;
use App\Models\Recipe;
use App\Models\RecipeStepTranslation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RecipeStepsAndMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_steps_save_bilingual_instructions_and_return_in_step_order(): void
    {
        $recipe = Recipe::factory()->create();
        $secondStep = $recipe->steps()->create(['step_number' => 2]);
        $secondStep->translations()->createMany([
            ['locale' => 'en', 'instruction' => 'Roast the vegetables.'],
            ['locale' => 'el', 'instruction' => 'Ψήστε τα λαχανικά.'],
        ]);
        $firstStep = $recipe->steps()->create(['step_number' => 1]);
        $firstStep->translations()->createMany([
            ['locale' => 'en', 'instruction' => 'Preheat the oven.'],
            ['locale' => 'el', 'instruction' => 'Προθερμάνετε τον φούρνο.'],
        ]);
        $previousLocale = app()->getLocale();

        try {
            app()->setLocale('el');

            $orderedSteps = $recipe->fresh()->steps;

            $this->assertSame(1, $orderedSteps[0]->step_number);
            $this->assertSame('Προθερμάνετε τον φούρνο.', $orderedSteps[0]->instruction);
            $this->assertSame(2, $orderedSteps[1]->step_number);
            $this->assertSame('Ψήστε τα λαχανικά.', $orderedSteps[1]->instruction);
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_recipe_steps_reject_duplicate_step_numbers(): void
    {
        $recipe = Recipe::factory()->create();
        $recipe->steps()->create(['step_number' => 1]);

        $this->expectException(QueryException::class);

        $recipe->steps()->create(['step_number' => 1]);
    }

    public function test_recipe_step_translation_factories_create_translated_records(): void
    {
        $translation = RecipeStepTranslation::factory()->create();

        $this->assertModelExists($translation);
        $this->assertTrue($translation->recipeStep->exists);
    }

    public function test_media_translation_factories_create_translated_records(): void
    {
        $translation = MediaTranslation::factory()->create();

        $this->assertModelExists($translation);
        $this->assertTrue($translation->media->exists);
    }

    public function test_recipe_and_step_media_relationships_persist_pivot_metadata(): void
    {
        $recipe = Recipe::factory()->create();
        $step = $recipe->steps()->create(['step_number' => 1]);
        $media = Media::factory()->create();

        $recipe->media()->attach($media, ['role' => 'cover', 'sort_order' => 0]);
        $step->media()->attach($media, ['sort_order' => 1]);

        $this->assertTrue($recipe->fresh()->media->contains($media));
        $this->assertSame('cover', $recipe->fresh()->media->first()->pivot->role);
        $this->assertTrue($step->fresh()->media->contains($media));
        $this->assertTrue($media->fresh()->recipes->contains($recipe));
        $this->assertTrue($media->fresh()->recipeSteps->contains($step));
        $this->assertDatabaseHas('recipe_step_media', [
            'recipe_step_id' => $step->id,
            'media_id' => $media->id,
            'sort_order' => 1,
        ]);
    }

    public function test_uploaded_recipe_image_is_validated_stored_translated_and_attached(): void
    {
        Storage::fake('public');
        $recipe = Recipe::factory()->create();
        $file = UploadedFile::fake()->createWithContent('summer-dish.png', $this->pngContent(1200, 900));

        $media = app(StoreRecipeMedia::class)->handle($recipe, $file, [
            'en' => ['alt_text' => 'A bowl of roasted vegetables', 'caption' => 'Ready to serve'],
            'el' => ['alt_text' => 'Ένα μπολ με ψητά λαχανικά', 'caption' => 'Έτοιμο για σερβίρισμα'],
        ], 'cover');

        Storage::disk('public')->assertExists($media->file_path);
        $this->assertSame('image/png', $media->mime_type);
        $this->assertSame(1200, $media->width);
        $this->assertSame(900, $media->height);
        $this->assertDatabaseHas('recipe_media', [
            'recipe_id' => $recipe->id,
            'media_id' => $media->id,
            'role' => 'cover',
        ]);
        $this->assertSame('Ένα μπολ με ψητά λαχανικά', $media->translate('el')->alt_text);
        $this->assertSame('Ready to serve', $media->translate('en')->caption);
    }

    public function test_upload_rejects_non_image_files_without_persisting_them(): void
    {
        Storage::fake('public');
        $recipe = Recipe::factory()->create();

        try {
            app(StoreRecipeMedia::class)->handle(
                $recipe,
                UploadedFile::fake()->createWithContent('payload.php', '<?php echo "unsafe";'),
                [
                    'en' => ['alt_text' => 'Unsafe image'],
                    'el' => ['alt_text' => 'Μη ασφαλής εικόνα'],
                ],
            );
            $this->fail('The upload should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_upload_rejects_images_over_the_configured_size_limit(): void
    {
        Storage::fake('public');
        $recipe = Recipe::factory()->create();

        try {
            app(StoreRecipeMedia::class)->handle(
                $recipe,
                UploadedFile::fake()->createWithContent('oversized.png', $this->pngContent(1, 1))->size(10241),
                [
                    'en' => ['alt_text' => 'Oversized image'],
                    'el' => ['alt_text' => 'Υπερμεγέθης εικόνα'],
                ],
            );
            $this->fail('The upload should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_upload_rejects_images_exceeding_dimension_limits(): void
    {
        Storage::fake('public');
        $recipe = Recipe::factory()->create();

        try {
            app(StoreRecipeMedia::class)->handle(
                $recipe,
                UploadedFile::fake()->createWithContent('oversized-dimensions.png', $this->pngContent(6001, 1)),
                [
                    'en' => ['alt_text' => 'Oversized dimensions'],
                    'el' => ['alt_text' => 'Υπερβολικές διαστάσεις'],
                ],
            );
            $this->fail('The upload should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_upload_requires_alt_text_in_both_supported_locales(): void
    {
        Storage::fake('public');
        $recipe = Recipe::factory()->create();

        try {
            app(StoreRecipeMedia::class)->handle(
                $recipe,
                UploadedFile::fake()->createWithContent('missing-greek-alt.png', $this->pngContent(1, 1)),
                [
                    'en' => ['alt_text' => 'A plated meal'],
                    'el' => ['alt_text' => ''],
                ],
            );
            $this->fail('The upload should be rejected without Greek alt text.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('translations.el.alt_text', $exception->errors());
        }

        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_media_attachment_rolls_back_database_changes_and_removes_stored_file(): void
    {
        Storage::fake('public');
        $recipe = Recipe::factory()->create();
        $existingMedia = Media::factory()->create();
        $existingMedia->translations()->createMany([
            ['locale' => 'en', 'alt_text' => 'Existing cover'],
            ['locale' => 'el', 'alt_text' => 'Υπάρχουσα εικόνα εξωφύλλου'],
        ]);
        $recipe->media()->attach($existingMedia, ['role' => 'cover']);
        $file = UploadedFile::fake()->createWithContent('another-cover.png', $this->pngContent(1200, 900));

        try {
            app(StoreRecipeMedia::class)->handle($recipe, $file, [
                'en' => ['alt_text' => 'Second cover'],
                'el' => ['alt_text' => 'Δεύτερη εικόνα εξωφύλλου'],
            ], 'cover');
            $this->fail('The database constraint should reject a second cover.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0] ?? null);
        }

        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseCount('media_translations', 2);
        $this->assertDatabaseCount('recipe_media', 1);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_filament_recipe_action_uploads_validated_images(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament table rendering requires the PHP intl extension.');
        }

        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $recipe = Recipe::factory()->create();
        Filament::setCurrentPanel('admin');

        Livewire::actingAs($admin)
            ->test(MediaRelationManager::class, ['ownerRecord' => $recipe, 'pageClass' => EditRecipe::class])
            ->callAction(TestAction::make('upload')->table(), [
                'file' => UploadedFile::fake()->createWithContent('recipe.png', $this->pngContent(1200, 900)),
                'role' => 'gallery',
                'alt_text_en' => 'A plated recipe',
                'caption_en' => 'Ready to eat',
                'alt_text_el' => 'Ένα σερβιρισμένο φαγητό',
                'caption_el' => 'Έτοιμο για φαγητό',
            ])
            ->assertHasNoFormErrors();

        $media = $recipe->fresh()->media()->firstOrFail();
        Storage::disk('public')->assertExists($media->file_path);
        $this->assertSame('A plated recipe', $media->translate('en')->alt_text);
        $this->assertSame('Ένα σερβιρισμένο φαγητό', $media->translate('el')->alt_text);
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
