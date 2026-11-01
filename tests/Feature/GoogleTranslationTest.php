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
}
