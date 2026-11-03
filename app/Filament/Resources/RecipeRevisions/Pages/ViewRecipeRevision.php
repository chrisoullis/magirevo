<?php

namespace App\Filament\Resources\RecipeRevisions\Pages;

use App\Enums\RecipeRevisionStatus;
use App\Filament\Resources\RecipeRevisions\RecipeRevisionResource;
use App\Models\DietaryClaim;
use App\Models\RecipeReusePermission;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionAudit;
use App\Services\RecipeRevisionComparer;
use App\Services\RecipeRevisionService;
use App\Services\Recipes\RecipeVariationPublishingService;
use App\Support\AppPermissions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewRecipeRevision extends ViewRecord
{
    protected static string $resource = RecipeRevisionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->rightsAction(true),
            $this->rightsAction(false),
            EditAction::make()->visible(fn (RecipeRevision $record): bool => auth()->user()?->can('update', $record) ?? false),
            $this->serviceAction('submit', 'Submit for review', 'warning', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->submit(auth()->user(), $record, $data)),
            $this->serviceAction('approve', 'Approve and publish', 'success', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->approve(auth()->user(), $record, $data['note'] ?? null, $data['meaningful_changes_confirmed'] ?? false), 'note', false),
            $this->serviceAction('reject', 'Reject', 'danger', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->reject(auth()->user(), $record, $data['note']), 'note', true),
            $this->serviceAction('rollback', 'Restore as new revision', 'gray', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->rollback(auth()->user(), $record, $data['note']), 'note', true),
            $this->serviceAction('discard', 'Discard draft', 'gray', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->discard(auth()->user(), $record)),
        ];
    }

    private function rightsAction(bool $approved): Action
    {
        return Action::make($approved ? 'clearVariationRights' : 'declineVariationRights')
            ->label($approved ? 'Clear variation rights' : 'Decline variation rights')
            ->color($approved ? 'success' : 'danger')
            ->visible(fn (RecipeRevision $record): bool => auth()->user()?->can('reviewVariationRights', $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription('Review the retained evidence and license conditions for this exact submission. This decision does not publish the recipe.')
            ->schema([
                Textarea::make('reason')->label('Private rights review reason')->required()->minLength(20)->maxLength(2000),
                Toggle::make('conditions_confirmed')->label('I inspected the actual evidence and confirmed the applicable license conditions')->accepted()->visible($approved),
            ])
            ->action(function (RecipeRevision $record, array $data) use ($approved): void {
                try {
                    app(RecipeVariationPublishingService::class)->reviewRights(auth()->user(), $record, $approved, $data['reason'], $data['conditions_confirmed'] ?? false);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Rights review failed')->body(collect($exception->errors())->flatten()->implode("\n"))->send();

                    return;
                }
                $this->record->refresh();
                Notification::make()->success()->title('Rights decision recorded')->send();
            });
    }

    /**
     * Every button asks the policy, so a user only sees what they can do; the service re-checks on execution.
     *
     * @param  callable(RecipeRevisionService, RecipeRevision, array<string, mixed>): mixed  $run
     */
    private function serviceAction(string $ability, string $label, string $color, callable $run, ?string $noteField = null, bool $noteRequired = false): Action
    {
        return Action::make($ability)
            ->label($label)
            ->color($color)
            ->visible(fn (RecipeRevision $record): bool => auth()->user()?->can($ability, $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription($ability === 'approve'
                ? 'Approving publishes this exact content. Dietary claims are reviewed separately and are not carried over. This is an editorial decision, not professional certification.'
                : null)
            ->schema([
                ...($noteField === null ? [] : [Textarea::make($noteField)->label($ability === 'approve' ? 'Review note' : 'Reason')->required(fn (RecipeRevision $record): bool => $noteRequired || ($ability === 'approve' && $record->recipe->derived_from_recipe_id !== null))->minLength(fn (RecipeRevision $record): int => $ability === 'approve' && $record->recipe->derived_from_recipe_id !== null ? 20 : 1)->maxLength(1000)]),
                Toggle::make('meaningful_changes_confirmed')->label('I independently reviewed and confirmed meaningful changes')->visible(fn (RecipeRevision $record): bool => in_array($ability, ['approve', 'submit'], true) && $record->recipe->derived_from_recipe_id !== null),
                Toggle::make('rights_confirmed')->label('I confirm the accuracy of my rights and media declarations')->visible(fn (RecipeRevision $record): bool => $ability === 'submit' && $record->recipe->derived_from_recipe_id !== null),
                Textarea::make('summaries.en')->label('Changes made (English)')->minLength(30)->maxLength(5000)->visible(fn (RecipeRevision $record): bool => $ability === 'submit' && $record->recipe->derived_from_recipe_id !== null),
                Textarea::make('summaries.el')->label('Changes made (Greek)')->minLength(30)->maxLength(5000)->visible(fn (RecipeRevision $record): bool => $ability === 'submit' && $record->recipe->derived_from_recipe_id !== null),
            ])
            ->action(function (RecipeRevision $record, array $data) use ($run, $label, $ability): void {
                try {
                    $run(app(RecipeRevisionService::class), $record, $data);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title("{$label} failed")->body(collect($exception->errors())->flatten()->implode("\n"))->send();

                    return;
                }

                if ($ability === 'discard') {
                    $this->redirect(RecipeRevisionResource::getUrl('index'));

                    return;
                }

                $this->record->refresh();
                Notification::make()->success()->title("{$label}: done")->send();
            });
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Revision')->columns(3)->schema([
                TextEntry::make('recipe')->state(fn (RecipeRevision $record): string => (string) ($record->recipe?->displayTranslation(app()->getLocale())?->title ?? 'Unavailable')),
                TextEntry::make('revision_number')->label('Revision'),
                TextEntry::make('status')->badge(),
                TextEntry::make('creator.name')->label('Created by')->placeholder('—'),
                TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                TextEntry::make('content_hash')->label('Content hash')->limit(16)->tooltip(fn (RecipeRevision $record): ?string => $record->content_hash)->placeholder('—'),
                TextEntry::make('live')->label('Live status')->state(fn (RecipeRevision $record): string => (int) $record->recipe?->current_revision_id === (int) $record->getKey() ? 'This is the revision currently published' : 'Not the published revision'),
            ]),
            Section::make('Variation review')->visible(fn (RecipeRevision $record): bool => $record->publication_context !== null && ((auth()->user()?->can(AppPermissions::VARIATIONS_REVIEW) ?? false) || (auth()->user()?->can(AppPermissions::VARIATIONS_MANAGE_RIGHTS) ?? false)))->schema([
                TextEntry::make('variation_summaries')->label('Frozen change summaries')->state(fn (RecipeRevision $record): string => collect($record->publication_context['summaries'] ?? [])->map(fn ($summary, $locale): string => strtoupper($locale).': '.$summary)->implode("\n")),
                TextEntry::make('variation_license')->label('Submitted license conditions')->listWithLineBreaks()->state(function (RecipeRevision $record): array {
                    $context = $record->publication_context;

                    return [
                        'License: '.($context['license_identifier'] ?: 'Written permission'),
                        'License reference: '.($context['license_url'] ?: 'See retained permission evidence'),
                        'ShareAlike required: '.($context['share_alike'] ? 'Yes' : 'No'),
                        'Public attribution permitted: '.($context['disclosure_allowed'] ? 'Yes' : 'No'),
                        'Rights declared at: '.$context['rights_confirmed_at'],
                        ...collect(Arr::dot($context['license_conditions'] ?? []))->map(fn ($value, $key): string => Str::headline($key).': '.(is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value))->values()->all(),
                    ];
                }),
                TextEntry::make('variation_media')->label('Frozen media declarations')->listWithLineBreaks()->state(fn (RecipeRevision $record): array => collect($record->media_snapshot ?? [])->map(fn (array $asset): string => implode(' | ', array_filter([
                    'Media #'.$asset['media_id'],
                    Str::headline($asset['media_type'] ?? 'Unknown type'),
                    $asset['credit'] ?? null,
                    ...collect($asset['translations'] ?? [])->map(fn (array $text, string $locale): string => strtoupper($locale).': '.implode(' — ', array_filter([$text['alt_text'] ?? null, $text['caption'] ?? null])))->values()->all(),
                ])))->all() ?: ['No submitted media.']),
                TextEntry::make('variation_rights_status')->label('Rights review status')->state(fn (RecipeRevision $record): string => $record->audits()->whereIn('action', ['variation_rights_cleared', 'variation_rights_rejected'])->reorder()->latest('id')->value('action') === 'variation_rights_cleared' ? 'Cleared for this submission' : ($record->audits()->whereIn('action', ['variation_rights_cleared', 'variation_rights_rejected'])->exists() ? 'Declined; a new rights decision is required' : 'Awaiting independent rights review')),
                TextEntry::make('source_revision_notice')->label('Source version')->state(function (RecipeRevision $record): string {
                    $source = $record->recipe->derivation?->sourceRevision;

                    return $source === null ? 'Retained source revision unavailable. Do not approve.' : 'Compared against retained source revision #'.$source->revision_number.'. Later source edits are not synchronized. Differences are an editorial aid; review reuse rights separately.';
                }),
                ViewEntry::make('source_comparison')->label('Changes from the exact original source revision')->view('filament.recipe-revisions.comparison')->state(function (RecipeRevision $record): array {
                    $source = RecipeRevision::query()->find($record->publication_context['source_revision_id'] ?? null);

                    return app(RecipeRevisionComparer::class)->compare($source?->payload ?? [], $record->payload ?? []);
                }),
                TextEntry::make('variation_evidence')->label('Private rights evidence')->visible(function (RecipeRevision $record): bool {
                    $permission = RecipeReusePermission::query()->find($record->publication_context['permission_id'] ?? null);

                    return $permission !== null && (auth()->user()?->can('view', $permission) ?? false);
                })->state(function (RecipeRevision $record): string {
                    $permission = RecipeReusePermission::query()->find($record->publication_context['permission_id'] ?? null);

                    return $permission === null ? 'Unavailable' : implode("\n", array_filter([$permission->permission_reference, $permission->review_notes, 'Verification: '.$permission->verification_status, $permission->suspended_at ? 'SUSPENDED' : null]));
                }),
                TextEntry::make('evidence_download')->label('Evidence file')->state('Download retained evidence')->url(fn (RecipeRevision $record): string => route('recipes.rights-evidence', ['permission' => $record->publication_context['permission_id'] ?? 0]))->visible(function (RecipeRevision $record): bool {
                    $permission = RecipeReusePermission::query()->find($record->publication_context['permission_id'] ?? null);

                    return $permission?->evidence_path !== null && $permission !== null && (auth()->user()?->can('view', $permission) ?? false);
                }),
            ]),
            Section::make('Private rights decision history')->visible(fn (RecipeRevision $record): bool => $record->publication_context !== null && (auth()->user()?->can('viewAny', RecipeReusePermission::class) ?? false))->schema([
                TextEntry::make('private_rights_history')->hiddenLabel()->state(fn (RecipeRevision $record): string => $record->audits()->whereIn('action', ['variation_rights_cleared', 'variation_rights_rejected'])->with('actor')->orderBy('id')->get()->map(fn (RecipeRevisionAudit $audit): string => implode(' | ', [$audit->created_at?->toDateTimeString(), $audit->action, $audit->actor?->name, $audit->metadata['private_reason'] ?? '']))->implode("\n") ?: 'No rights decisions.'),
            ]),
            Section::make('Changes from the previous published revision')->schema([
                ViewEntry::make('comparison')->view('filament.recipe-revisions.comparison')->state(fn (RecipeRevision $record): array => app(RecipeRevisionComparer::class)->compare($record->baseRevision?->payload ?? [], $record->payload ?? [])),
            ]),
            Section::make('Dietary claims for this exact revision')->collapsible()->schema([
                TextEntry::make('claims')->hiddenLabel()->state(fn (RecipeRevision $record): string => DietaryClaim::query()->where('recipe_revision_id', $record->getKey())->with('tag')->get()
                    ->map(fn (DietaryClaim $claim): string => "{$claim->tag->code}: {$claim->status->value}".($claim->status->value === 'approved' && $record->status !== RecipeRevisionStatus::Published ? ' (hidden until this revision is published)' : ''))
                    ->implode("\n") ?: 'No claims. Approvals from other revisions are never carried over.'),
            ]),
            Section::make('Review history')->schema([
                TextEntry::make('history')->hiddenLabel()->state(fn (RecipeRevision $record): string => $record->audits()->with('actor')->orderBy('id')->get()
                    ->map(fn (RecipeRevisionAudit $audit): string => implode(' | ', array_filter([$audit->created_at?->toDateTimeString(), $audit->action, $audit->actor?->name, $audit->from_status && $audit->to_status ? "{$audit->from_status} → {$audit->to_status}" : null, $audit->note])))
                    ->implode("\n") ?: 'No history.'),
            ]),
        ]);
    }
}
