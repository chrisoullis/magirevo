<?php

namespace Tests\Feature;

use App\Actions\SetCategoryImage;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Category;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryImageTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATIONS = [
        'en' => ['alt_text' => 'A bowl of soup', 'caption' => 'Warm'],
        'el' => ['alt_text' => 'Ένα μπολ σούπα', 'caption' => null],
    ];

    private function category(string $code = 'soups', ?Category $parent = null): Category
    {
        $category = Category::factory()->create(['code' => $code, 'parent_id' => $parent?->getKey()]);
        $category->translations()->createMany([
            ['locale' => 'en', 'name' => ucfirst($code), 'slug' => "{$code}-en"],
            ['locale' => 'el', 'name' => "Κατηγορία {$code}", 'slug' => "{$code}-el"],
        ]);

        return $category;
    }

    private function png(string $name = 'soup.png'): UploadedFile
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $content = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('N2C5', 40, 30, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0", 120), 30)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_an_uploaded_image_is_associated_with_bilingual_text(): void
    {
        Storage::fake('public');
        $category = $this->category();

        $media = app(SetCategoryImage::class)->upload($category, $this->png(), self::TRANSLATIONS);

        $this->assertSame($media->getKey(), $category->fresh()->media_id);
        $this->assertStringStartsWith('category-media/', $media->file_path);
        Storage::disk('public')->assertExists($media->file_path);
        $this->assertSame('A bowl of soup', $category->fresh()->imageAlt('en'));
        $this->assertSame('Ένα μπολ σούπα', $category->fresh()->imageAlt('el'));
        $this->assertSame($media->publicUrl(), $category->fresh()->imageUrl());
    }

    public function test_a_remote_image_can_be_assigned(): void
    {
        $category = $this->category();

        $media = app(SetCategoryImage::class)->remote($category, 'https://images.example.com/soup.jpg', self::TRANSLATIONS, 'Unsplash');

        $this->assertTrue($media->isRemote());
        $this->assertSame('https://images.example.com/soup.jpg', $category->fresh()->imageUrl());
    }

    public function test_remote_images_must_use_https(): void
    {
        $this->expectException(ValidationException::class);

        app(SetCategoryImage::class)->remote($this->category(), 'http://images.example.com/soup.jpg', self::TRANSLATIONS);
    }

    public function test_replacing_an_image_deletes_the_old_unshared_file(): void
    {
        Storage::fake('public');
        $category = $this->category();
        $action = app(SetCategoryImage::class);
        $old = $action->upload($category, $this->png('old.png'), self::TRANSLATIONS);

        $new = $action->upload($category, $this->png('new.png'), self::TRANSLATIONS);

        $this->assertSame($new->getKey(), $category->fresh()->media_id);
        $this->assertModelMissing($old);
        Storage::disk('public')->assertMissing($old->file_path);
        Storage::disk('public')->assertExists($new->file_path);
    }

    public function test_replacing_or_removing_an_image_keeps_media_shared_with_other_records(): void
    {
        Storage::fake('public');
        $action = app(SetCategoryImage::class);
        $first = $this->category('soups');
        $second = $this->category('salads');
        $shared = $action->upload($first, $this->png(), self::TRANSLATIONS);
        $second->update(['media_id' => $shared->getKey()]);
        $recipe = Recipe::factory()->create();
        $recipe->media()->attach($shared, ['role' => 'gallery', 'sort_order' => 0]);

        $action->upload($first, $this->png('other.png'), self::TRANSLATIONS);
        $action->remove($second->fresh());

        $this->assertModelExists($shared);
        Storage::disk('public')->assertExists($shared->file_path);
        $this->assertNull($second->fresh()->media_id);
    }

    public function test_removing_an_image_clears_the_association_and_deletes_the_unshared_file(): void
    {
        Storage::fake('public');
        $category = $this->category();
        $media = app(SetCategoryImage::class)->upload($category, $this->png(), self::TRANSLATIONS);

        app(SetCategoryImage::class)->remove($category);

        $this->assertNull($category->fresh()->media_id);
        $this->assertModelMissing($media);
        Storage::disk('public')->assertMissing($media->file_path);
    }

    public function test_deleting_media_directly_nulls_the_category_reference(): void
    {
        $media = Media::factory()->create();
        $category = $this->category();
        $category->update(['media_id' => $media->getKey()]);

        $media->delete();

        $this->assertNull($category->fresh()->media_id);
    }

    public function test_alt_text_can_be_updated_without_changing_the_image(): void
    {
        Storage::fake('public');
        $category = $this->category();
        $media = app(SetCategoryImage::class)->upload($category, $this->png(), self::TRANSLATIONS);

        app(SetCategoryImage::class)->updateText($category->fresh(), [
            'en' => ['alt_text' => 'New alt', 'caption' => null],
            'el' => ['alt_text' => 'Νέο alt', 'caption' => null],
        ]);

        $this->assertSame('New alt', $media->fresh()->getTranslation('en', false)->alt_text);
        $this->assertSame($media->getKey(), $category->fresh()->media_id);
    }

    public function test_non_image_uploads_are_rejected(): void
    {
        Storage::fake('public');

        $this->expectException(ValidationException::class);

        app(SetCategoryImage::class)->upload($this->category(), UploadedFile::fake()->createWithContent('x.php', '<?php'), self::TRANSLATIONS);
    }

    public function test_public_category_pages_show_the_banner_and_subcategory_card_images_with_a_placeholder_fallback(): void
    {
        $parent = $this->category('main_dishes');
        $withImage = $this->category('with_image', $parent);
        $withoutImage = $this->category('without_image', $parent);
        $parent->update(['media_id' => Media::factory()->remote('https://images.example.com/banner.jpg')->create()->getKey()]);
        $parent->media->translations()->createMany([
            ['locale' => 'en', 'alt_text' => 'Banner alt'],
            ['locale' => 'el', 'alt_text' => 'Αλτ banner'],
        ]);
        $withImage->update(['media_id' => Media::factory()->remote('https://images.example.com/card.jpg')->create()->getKey()]);
        foreach ([$withImage, $withoutImage] as $child) {
            $recipe = Recipe::factory()->published()->create();
            $recipe->categories()->attach($child);
        }

        $response = $this->get('/en/categories/main_dishes-en')->assertOk();

        $response->assertSee('https://images.example.com/banner.jpg', false)
            ->assertSee('alt="Banner alt"', false)
            ->assertSee('https://images.example.com/card.jpg', false)
            ->assertSee('images/category-placeholder.svg', false)
            ->assertSee('loading="lazy"', false);

        $this->get('/el/categories/main_dishes-el')->assertOk()->assertSee('alt="Αλτ banner"', false);
    }

    public function test_pages_without_any_image_render_without_a_banner(): void
    {
        $parent = $this->category('plain');

        $this->get('/en/categories/plain-en')->assertOk()->assertDontSee('category-banner');
        $this->assertStringEndsWith('images/category-placeholder.svg', $parent->imageUrl());
        $this->assertSame('', $parent->imageAlt('en'));
    }

    public function test_category_image_admin_form_saves_and_removes_an_image(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament page rendering requires the PHP intl extension.');
        }

        Filament::setCurrentPanel('admin');
        Storage::fake('public');
        $category = $this->category();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(EditCategory::class, ['record' => $category->getKey()])
            ->fillForm([
                'image' => [
                    'url' => 'https://images.example.com/soup.jpg',
                    'alt_text_en' => 'Soup',
                    'alt_text_el' => 'Σούπα',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('https://images.example.com/soup.jpg', $category->fresh()->imageUrl());
        $this->assertSame('Σούπα', $category->fresh()->imageAlt('el'));

        Livewire::actingAs($admin)
            ->test(EditCategory::class, ['record' => $category->getKey()])
            ->fillForm(['image' => ['remove' => true, 'url' => null]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($category->fresh()->media_id);
    }
}
