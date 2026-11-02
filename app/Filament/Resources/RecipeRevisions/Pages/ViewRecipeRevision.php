<?php

namespace App\Filament\Resources\RecipeRevisions\Pages;

use App\Enums\RecipeRevisionStatus;
use App\Filament\Resources\RecipeRevisions\RecipeRevisionResource;
use App\Models\DietaryClaim;
use App\Models\RecipeRevision;
use App\Models\RecipeRevisionAudit;
use App\Services\RecipeRevisionComparer;
use App\Services\RecipeRevisionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class ViewRecipeRevision extends ViewRecord
{
    protected static string $resource = RecipeRevisionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (RecipeRevision $record): bool => auth()->user()?->can('update', $record) ?? false),
            $this->serviceAction('submit', 'Submit for review', 'warning', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->submit(auth()->user(), $record)),
            $this->serviceAction('approve', 'Approve and publish', 'success', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->approve(auth()->user(), $record, $data['note'] ?? null), 'note', false),
            $this->serviceAction('reject', 'Reject', 'danger', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->reject(auth()->user(), $record, $data['note']), 'note', true),
            $this->serviceAction('rollback', 'Restore as new revision', 'gray', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->rollback(auth()->user(), $record, $data['note']), 'note', true),
            $this->serviceAction('discard', 'Discard draft', 'gray', fn (RecipeRevisionService $service, RecipeRevision $record, array $data) => $service->discard(auth()->user(), $record)),
        ];
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
            ->schema($noteField === null ? [] : [Textarea::make($noteField)->label($ability === 'approve' ? 'Review note (optional)' : 'Reason')->required($noteRequired)->maxLength(1000)])
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
