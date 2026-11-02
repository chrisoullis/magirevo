<?php

namespace App\Filament\Resources\RecipeRevisions\Schemas;

use App\Models\Ingredient;
use App\Models\Unit;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Draft form for the text, quantities and instructions of a revision. Media, categories, tags and ingredient
 * groups stay exactly as the draft holds them; line and step IDs travel as hidden fields so unchanged lines keep
 * their identity when the revision is published.
 */
class RecipeRevisionForm
{
    private const LOCALES = ['en' => 'English', 'el' => 'Ελληνικά'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Revision')->columnSpanFull()->tabs([
                ...collect(self::LOCALES)->map(fn (string $label, string $locale): Tab => Tab::make($label)->schema([
                    TextInput::make("{$locale}.title")->label('Title')->required()->maxLength(255),
                    TextInput::make("{$locale}.slug")->label('Slug')->required()->maxLength(255)->regex('/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u'),
                    Textarea::make("{$locale}.summary")->label('Summary')->rows(2),
                    Textarea::make("{$locale}.introduction")->label('Introduction')->rows(4),
                    TextInput::make("{$locale}.seo_title")->label('SEO title')->maxLength(255),
                    Textarea::make("{$locale}.seo_description")->label('SEO description')->rows(2),
                ]))->values()->all(),
                Tab::make('Details')->schema([
                    TextInput::make('prep_minutes')->numeric()->minValue(0),
                    TextInput::make('cook_minutes')->numeric()->minValue(0),
                    TextInput::make('rest_minutes')->numeric()->minValue(0),
                    TextInput::make('base_servings')->numeric()->minValue(1)->required(),
                    TextInput::make('difficulty'),
                ]),
                Tab::make('Ingredients')->schema([
                    Repeater::make('ingredients_data')->label('Ingredients')->minItems(1)->schema([
                        Hidden::make('id'),
                        Hidden::make('group_index'),
                        Hidden::make('scaling_mode'),
                        Select::make('ingredient_id')->label('Ingredient')->required()->searchable()
                            ->options(fn (): array => Ingredient::query()->with('translations')->get()->mapWithKeys(fn (Ingredient $ingredient): array => [$ingredient->getKey() => (string) ($ingredient->translate('en')?->name ?? $ingredient->code ?? $ingredient->getKey())])->all()),
                        TextInput::make('quantity')->numeric()->minValue(0),
                        TextInput::make('quantity_max')->numeric()->minValue(0),
                        Select::make('unit_id')->label('Unit')->options(fn (): array => Unit::query()->pluck('code', 'id')->all()),
                        Toggle::make('is_optional')->label('Optional'),
                        TextInput::make('preparation_note_en')->label('Preparation note (EN)'),
                        TextInput::make('preparation_note_el')->label('Preparation note (EL)'),
                        TextInput::make('quantity_text_en')->label('Quantity text (EN)'),
                        TextInput::make('quantity_text_el')->label('Quantity text (EL)'),
                    ])->columns(4),
                ]),
                Tab::make('Steps')->schema([
                    Repeater::make('steps_data')->label('Steps')->minItems(1)->schema([
                        Hidden::make('id'),
                        Textarea::make('instruction_en')->label('Instruction (EN)')->required(),
                        Textarea::make('instruction_el')->label('Instruction (EL)')->required(),
                        TextInput::make('duration_minutes')->numeric()->minValue(0),
                    ]),
                ]),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fromPayload(array $payload): array
    {
        $data = [];

        foreach (array_keys(self::LOCALES) as $locale) {
            $data[$locale] = $payload['translations'][$locale] ?? [];
        }

        foreach (['prep_minutes', 'cook_minutes', 'rest_minutes', 'base_servings', 'difficulty'] as $field) {
            $data[$field] = $payload['recipe'][$field] ?? null;
        }

        $data['ingredients_data'] = collect($payload['ingredients'] ?? [])->map(fn (array $line): array => [
            'id' => $line['id'] ?? null,
            'group_index' => $line['group_index'] ?? null,
            'scaling_mode' => $line['scaling_mode'] ?? 'linear',
            'ingredient_id' => $line['ingredient_id'],
            'quantity' => $line['quantity'],
            'quantity_max' => $line['quantity_max'],
            'unit_id' => $line['unit_id'],
            'is_optional' => $line['is_optional'] ?? false,
            'preparation_note_en' => $line['translations']['en']['preparation_note'] ?? null,
            'preparation_note_el' => $line['translations']['el']['preparation_note'] ?? null,
            'quantity_text_en' => $line['translations']['en']['quantity_text'] ?? null,
            'quantity_text_el' => $line['translations']['el']['quantity_text'] ?? null,
        ])->all();

        $data['steps_data'] = collect($payload['steps'] ?? [])->map(fn (array $step): array => [
            'id' => $step['id'] ?? null,
            'instruction_en' => $step['translations']['en']['instruction'] ?? null,
            'instruction_el' => $step['translations']['el']['instruction'] ?? null,
            'duration_minutes' => $step['duration_minutes'] ?? null,
        ])->all();

        return $data;
    }

    /**
     * Applies form data on top of the draft's existing payload, leaving everything the form does not show intact.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toPayload(array $payload, array $data): array
    {
        foreach (array_keys(self::LOCALES) as $locale) {
            $payload['translations'][$locale] = $data[$locale] ?? [];
        }

        foreach (['prep_minutes', 'cook_minutes', 'rest_minutes', 'base_servings', 'difficulty'] as $field) {
            $payload['recipe'][$field] = $data[$field] ?? null;
        }

        $existingSteps = collect($payload['steps'] ?? [])->keyBy(fn (array $step) => $step['id'] ?? null);

        $payload['ingredients'] = collect($data['ingredients_data'] ?? [])->values()->map(fn (array $line): array => [
            'id' => $line['id'] ?? null,
            'ingredient_id' => $line['ingredient_id'],
            'group_index' => $line['group_index'] ?? null,
            'quantity' => $line['quantity'] ?? null,
            'quantity_max' => $line['quantity_max'] ?? null,
            'unit_id' => $line['unit_id'] ?? null,
            'scaling_mode' => $line['scaling_mode'] ?? 'linear',
            'is_optional' => (bool) ($line['is_optional'] ?? false),
            'translations' => [
                'en' => ['preparation_note' => $line['preparation_note_en'] ?? null, 'quantity_text' => $line['quantity_text_en'] ?? null],
                'el' => ['preparation_note' => $line['preparation_note_el'] ?? null, 'quantity_text' => $line['quantity_text_el'] ?? null],
            ],
        ])->all();

        $payload['steps'] = collect($data['steps_data'] ?? [])->values()->map(fn (array $step, int $index): array => [
            'id' => $step['id'] ?? null,
            'step_number' => $index + 1,
            'duration_minutes' => $step['duration_minutes'] ?? null,
            'translations' => [
                'en' => ['instruction' => $step['instruction_en'] ?? null],
                'el' => ['instruction' => $step['instruction_el'] ?? null],
            ],
            'media' => $existingSteps->get($step['id'] ?? null)['media'] ?? [],
        ])->all();

        return $payload;
    }
}
