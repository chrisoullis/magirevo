<?php

namespace App\Services;

use App\Models\RecipeRevision;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The persisted contract for the existing cooking overlay; clocks advance without database writes. */
class CookingSessionProgress
{
    /** Revision-scoped positional keys remain stable even when frozen payload row IDs are null. */
    public function steps(array $payload): array
    {
        $steps = [];
        foreach (array_values($payload['steps'] ?? []) as $index => $step) {
            $steps['s'.$index] = [
                'index' => $index,
                'group' => isset($step['step_group_index']) ? 'g'.$step['step_group_index'] : 'ungrouped',
                'group_index' => $step['step_group_index'] ?? -1,
                'minutes' => $step['duration_minutes'] ?? null,
            ];
        }

        return $steps;
    }

    public function initial(array $payload): array
    {
        $steps = $this->steps($payload);
        if ($steps === []) {
            throw ValidationException::withMessages(['recipe' => __('cooking.no_steps')]);
        }
        $ordered = collect($steps)->sortBy([['group_index', 'asc'], ['index', 'asc']]);
        $active = $ordered->keys()->first();

        return [
            'schema_version' => 1, 'active_step' => $active,
            'last_steps' => [$steps[$active]['group'] => $active],
            'steps' => array_map(fn (): array => ['status' => 'not_started', 'completed_at' => null], $steps),
            'timers' => [], 'checked_ingredients' => [], 'size' => 1,
            'active_context' => null, 'contexts' => [],
        ];
    }

    /** Apply one validated lifecycle event, never a client-supplied deadline or entire replacement state. */
    public function apply(array $state, array $payload, array $event): array
    {
        if (($event['action'] ?? null) === 'context_close') {
            $parts = explode('/', (string) ($state['active_context'] ?? ''));
            array_pop($parts);
            $state['active_context'] = $parts === [] ? null : implode('/', $parts);
            if ($state['active_context'] !== null) {
                $state['contexts'][$state['active_context']] ??= $this->initial($this->contextPayload($payload, $state['active_context']));
            }

            return $state;
        }
        if (($event['action'] ?? null) === 'context_open' || filled($event['context'] ?? null)) {
            $path = Validator::make($event, ['context' => ['present', 'nullable', 'string', 'max:300']])->validate()['context'];
            if (blank($path)) {
                $state['active_context'] = null;

                return $state;
            }
            $child = $this->contextPayload($payload, $path);
            $state['contexts'][$path] ??= $this->initial($child);
            if ($event['action'] === 'context_open') {
                $state['active_context'] = $path;
            } else {
                unset($event['context']);
                $state['contexts'][$path] = $this->apply($state['contexts'][$path], $child, $event);
            }

            return $state;
        }
        $steps = $this->steps($payload);
        $event = Validator::make($event, [
            'action' => ['required', Rule::in(['visit', 'step_status', 'ingredient', 'ingredients_reset', 'size', 'timer_start', 'timer_pause', 'timer_resume', 'timer_cancel', 'timer_restart'])],
            'step' => ['required_if:action,visit,step_status,timer_start,timer_pause,timer_resume,timer_cancel,timer_restart', Rule::in(array_keys($steps))],
            'status' => ['required_if:action,step_status', Rule::in(['not_started', 'in_progress', 'waiting', 'completed'])],
            'ingredient' => ['required_if:action,ingredient', 'string', Rule::in(array_map(fn (int $index): string => 'i'.$index, array_keys(array_values($payload['ingredients'] ?? []))))],
            'checked' => ['required_if:action,ingredient', 'boolean'],
            'size' => ['required_if:action,size', 'integer', 'between:0,4'],
            'duration_seconds' => ['sometimes', 'integer', 'between:1,2592000'],
        ])->validate();
        $key = $event['step'] ?? null;
        switch ($event['action']) {
            case 'visit':
                $state['active_step'] = $key;
                $state['last_steps'][$steps[$key]['group']] = $key;
                if ($state['steps'][$key]['status'] === 'not_started') {
                    $state['steps'][$key]['status'] = 'in_progress';
                }
                break;
            case 'step_status':
                $state['steps'][$key] = [
                    'status' => $event['status'],
                    'completed_at' => $event['status'] === 'completed'
                        ? ($state['steps'][$key]['completed_at'] ?? now()->utc()->toISOString()) : null,
                ];
                break;
            case 'ingredient':
                if ($event['checked']) {
                    $state['checked_ingredients'][$event['ingredient']] = true;
                } else {
                    unset($state['checked_ingredients'][$event['ingredient']]);
                }
                break;
            case 'ingredients_reset':
                $state['checked_ingredients'] = [];
                break;
            case 'size':
                $state['size'] = (int) $event['size'];
                break;
            default:
                $state = $this->timerEvent($state, $steps, $key, $event);
        }

        return $state;
    }

    /** Resolve only immutable references authored inside the root session's exact snapshot. */
    public function contextPayload(array $payload, string $path): array
    {
        $parts = explode('/', $path);
        if (count($parts) > RecipeComponentResolver::MAX_DEPTH) {
            throw ValidationException::withMessages(['context' => __('nested.unavailable')]);
        }
        foreach ($parts as $uuid) {
            $line = collect($payload['ingredients'] ?? [])->first(fn (array $row): bool => ($row['line_type'] ?? 'ingredient') === 'recipe' && ($row['line_uuid'] ?? null) === $uuid);
            $revision = $line ? RecipeRevision::where('recipe_id', $line['component_recipe_id'])->find($line['component_recipe_revision_id']) : null;
            if ($revision === null || ! in_array($revision->getRawOriginal('status'), ['published', 'superseded', 'retained'], true)) {
                throw ValidationException::withMessages(['context' => __('nested.unavailable')]);
            }
            $payload = $revision->payload;
        }

        return $payload;
    }

    public function completed(array $state): bool
    {
        return $state['steps'] !== [] && collect($state['steps'])->every(fn (array $step): bool => $step['status'] === 'completed')
            && collect($state['contexts'] ?? [])->every(fn (array $context): bool => $this->completed($context));
    }

    /** Expiry is a read projection: it never completes a step or increments a session version. */
    public function present(array $state): array
    {
        foreach ($state['contexts'] ?? [] as $path => $context) {
            $state['contexts'][$path] = $this->present($context);
        }
        $now = now()->getTimestampMs();
        foreach ($state['timers'] as &$timer) {
            $left = $timer['status'] === 'running' ? max(0, $timer['deadline_ms'] - $now) : $timer['remaining_ms'];
            $timer['remaining_ms'] = $left;
            if ($left === 0) {
                $timer['status'] = 'finished';
            }
        }
        unset($timer);

        return $state;
    }

    private function timerEvent(array $state, array $steps, string $key, array $event): array
    {
        $now = now()->getTimestampMs();
        $timer = $state['timers'][$key] ?? null;
        switch ($event['action']) {
            case 'timer_start':
            case 'timer_restart':
                if ($timer !== null && $event['action'] === 'timer_start') {
                    return $state;
                }
                $seconds = $event['duration_seconds'] ?? $timer['duration_seconds'] ?? (($steps[$key]['minutes'] ?? 0) * 60);
                if ($seconds < 1 || $seconds > 2592000) {
                    throw ValidationException::withMessages(['duration_seconds' => __('cooking.timer_duration_required')]);
                }
                $state['timers'][$key] = [
                    'duration_seconds' => (int) $seconds, 'started_at_ms' => $now,
                    'deadline_ms' => $now + $seconds * 1000, 'remaining_ms' => null, 'status' => 'running',
                ];
                $state['steps'][$key] = ['status' => 'waiting', 'completed_at' => null];
                break;
            case 'timer_pause':
                if ($timer !== null && $timer['status'] === 'running') {
                    $state['timers'][$key]['remaining_ms'] = max(0, $timer['deadline_ms'] - $now);
                    $state['timers'][$key]['deadline_ms'] = null;
                    $state['timers'][$key]['status'] = 'paused';
                }
                break;
            case 'timer_resume':
                if ($timer !== null && $timer['status'] === 'paused' && $timer['remaining_ms'] > 0) {
                    $state['timers'][$key]['deadline_ms'] = $now + $timer['remaining_ms'];
                    $state['timers'][$key]['remaining_ms'] = null;
                    $state['timers'][$key]['status'] = 'running';
                }
                break;
            case 'timer_cancel':
                unset($state['timers'][$key]);
                if ($state['steps'][$key]['status'] === 'waiting') {
                    $state['steps'][$key] = ['status' => 'in_progress', 'completed_at' => null];
                }
                break;
        }

        return $state;
    }
}
