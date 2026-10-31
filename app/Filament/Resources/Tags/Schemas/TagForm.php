<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Filament\Support\TranslationTabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tag')->columns(3)->columnSpanFull()->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(100)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->helperText('Lowercase letters, numbers and hyphens.')
                        ->unique(ignoreRecord: true),
                    TextInput::make('sort_order')->numeric()->integer()->default(0)->required(),
                    Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                ]),
                TranslationTabs::make(fn (string $locale): array => [
                    TextInput::make("{$locale}.name")
                        ->label('Name')
                        ->required()
                        ->maxLength(150)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state) use ($locale): void {
                            if (blank($get("{$locale}.slug")) && filled($state)) {
                                $set("{$locale}.slug", Str::slug($state));
                            }
                        }),
                    TextInput::make("{$locale}.slug")
                        ->label('Slug')
                        ->required()
                        ->maxLength(180)
                        ->regex('/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u')
                        ->unique(
                            table: 'tag_translations',
                            column: 'slug',
                            ignorable: fn (?Model $record) => $record?->getTranslation($locale, false),
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('locale', $locale),
                        ),
                    Textarea::make("{$locale}.description")->label('Description')->rows(3),
                ]),
            ]);
    }
}
