<?php

namespace Tests\Feature;

use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Models\User;
use App\Services\GoogleTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_returns_translation_and_sends_expected_languages(): void
    {
        config(['services.google_translate.key' => 'test-key']);
        Http::fake(['translation.googleapis.com/*' => Http::response(['data' => ['translations' => [['translatedText' => 'Καλημέρα &amp; γεια']]]])]);

        $result = app(GoogleTranslationService::class)->translate('Good morning', 'en', 'el');

        $this->assertSame('Καλημέρα & γεια', $result);
        Http::assertSent(fn ($request) => $request['source'] === 'en' && $request['target'] === 'el' && $request['format'] === 'text');
    }

    public function test_service_returns_null_without_key_or_on_failure(): void
    {
        config(['services.google_translate.key' => null]);
        Http::fake();
        $this->assertNull(app(GoogleTranslationService::class)->translate('Hi', 'en', 'el'));
        Http::assertNothingSent();

        config(['services.google_translate.key' => 'k']);
        Http::fake(['*' => Http::response([], 500)]);
        $this->assertNull(app(GoogleTranslationService::class)->translate('Hi', 'en', 'el'));
    }

    public function test_form_actions_translate_in_both_directions(): void
    {
        config(['services.google_translate.key' => 'k']);
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['translations' => [['translatedText' => 'Περίληψη']]]])
            ->push(['data' => ['translations' => [['translatedText' => 'Cook it.']]]])]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateRecipe::class)
            ->fillForm(['en' => ['summary' => 'Summary'], 'steps_data' => [['instruction_el' => 'Μαγειρέψτε.']]])
            ->callFormComponentAction('en.summary', 'translate_en_summary')
            ->assertFormSet(['el.summary' => 'Περίληψη'])
            ->callFormComponentAction('steps_data.0.instruction_el', 'translate_instruction_el')
            ->assertFormSet(['steps_data.0.instruction_en' => 'Cook it.']);
    }

    public function test_step_media_text_actions_translate_captions_and_video_titles_in_both_directions(): void
    {
        config(['services.google_translate.key' => 'k']);
        Http::fake(['*' => Http::sequence()
            ->push(['data' => ['translations' => [['translatedText' => 'Greek alt']]]])
            ->push(['data' => ['translations' => [['translatedText' => 'Greek caption']]]])
            ->push(['data' => ['translations' => [['translatedText' => 'Greek video title']]]])
            ->push(['data' => ['translations' => [['translatedText' => 'Greek video caption']]]])
            ->push(['data' => ['translations' => [['translatedText' => 'English alt']]]])
            ->push(['data' => ['translations' => [['translatedText' => 'English caption']]]])
            ->push(['data' => ['translations' => [['translatedText' => 'English video title']]]])
            ->push(['data' => ['translations' => [['translatedText' => 'English video caption']]]])]);

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateRecipe::class)
            ->fillForm([
                'steps_data' => [[
                    'media_items' => [[
                        'alt_text_en' => 'English alt',
                        'caption_en' => 'English caption',
                        'alt_text_el' => 'Greek alt',
                        'caption_el' => 'Greek caption',
                    ]],
                    'video_alt_text_en' => 'English video title',
                    'video_caption_en' => 'English video caption',
                    'video_alt_text_el' => 'Greek video title',
                    'video_caption_el' => 'Greek video caption',
                ]],
            ]);

        $component
            ->callFormComponentAction('steps_data.0.media_items.0.alt_text_en', 'translate_alt_text_en')
            ->assertFormSet(['steps_data.0.media_items.0.alt_text_el' => 'Greek alt'])
            ->callFormComponentAction('steps_data.0.media_items.0.caption_en', 'translate_caption_en')
            ->assertFormSet(['steps_data.0.media_items.0.caption_el' => 'Greek caption'])
            ->callFormComponentAction('steps_data.0.video_alt_text_en', 'translate_video_alt_text_en')
            ->assertFormSet(['steps_data.0.video_alt_text_el' => 'Greek video title'])
            ->callFormComponentAction('steps_data.0.video_caption_en', 'translate_video_caption_en')
            ->assertFormSet(['steps_data.0.video_caption_el' => 'Greek video caption'])
            ->callFormComponentAction('steps_data.0.media_items.0.alt_text_el', 'translate_alt_text_el')
            ->assertFormSet(['steps_data.0.media_items.0.alt_text_en' => 'English alt'])
            ->callFormComponentAction('steps_data.0.media_items.0.caption_el', 'translate_caption_el')
            ->assertFormSet(['steps_data.0.media_items.0.caption_en' => 'English caption'])
            ->callFormComponentAction('steps_data.0.video_alt_text_el', 'translate_video_alt_text_el')
            ->assertFormSet(['steps_data.0.video_alt_text_en' => 'English video title'])
            ->callFormComponentAction('steps_data.0.video_caption_el', 'translate_video_caption_el')
            ->assertFormSet(['steps_data.0.video_caption_en' => 'English video caption']);

        Http::assertSentCount(8);
    }
}
