<?php

namespace App\Services;

use App\Enums\RecipeStatus;
use App\Models\Category;
use App\Models\ContributorRequest;
use App\Models\Recipe;
use App\Models\User;
use App\Support\AppPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures. Every recipe figure is scoped through Recipe::manageableBy(), the same boundary the
 * recipe resource uses, so a user never gets counts or rows for private recipes they may not moderate.
 */
class DashboardStatisticsService
{
    public const CACHE_SECONDS = 60;

    public const ACTIVITY_RANGES = [7, 30, 90];

    public const QUALITY_CHECKS = ['translations', 'cover', 'ingredients', 'steps', 'seo'];

    public function recipesFor(User $user): Builder
    {
        return Recipe::query()->manageableBy($user);
    }

    /**
     * @return array{published: int, pending: int, draft: int, users: int|null}
     */
    public function kpis(User $user): array
    {
        return $this->remember($user, 'kpis', function () use ($user): array {
            $byStatus = $this->recipesFor($user)->toBase()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            return [
                'published' => $this->recipesFor($user)->published()->count(),
                'pending' => (int) ($byStatus[RecipeStatus::Pending->value] ?? 0),
                'draft' => (int) ($byStatus[RecipeStatus::Draft->value] ?? 0),
                'users' => $user->can(AppPermissions::USERS_MANAGE) ? User::query()->count() : null,
            ];
        });
    }

    public function pendingRecipes(User $user): Builder
    {
        return $this->recipesFor($user)->where('status', RecipeStatus::Pending->value);
    }

    public function pendingContributorRequests(): Builder
    {
        return ContributorRequest::query()->pending();
    }

    public function pendingContributorRequestCount(): int
    {
        return Cache::remember('dashboard:contributor-requests', self::CACHE_SECONDS, fn (): int => $this->pendingContributorRequests()->count());
    }

    /**
     * Live-or-soon-live recipes (published and pending) that still miss something the publication gate requires.
     *
     * @return array<string, int>
     */
    public function qualityIssues(User $user): array
    {
        return $this->remember($user, 'quality', function () use ($user): array {
            $counts = [];

            foreach (self::QUALITY_CHECKS as $check) {
                $counts[$check] = $this->qualityScope($user)->{'missing'.ucfirst($check)}()->count();
            }

            return $counts;
        });
    }

    public function qualityScope(User $user): RecipeQualityQuery
    {
        return new RecipeQualityQuery(
            $this->recipesFor($user)->whereIn('status', [RecipeStatus::Published->value, RecipeStatus::Pending->value]),
        );
    }

    /**
     * Daily created and published counts for the last $days days, zero-filled.
     *
     * @return array{labels: list<string>, created: list<int>, published: list<int>}
     */
    public function activity(User $user, int $days): array
    {
        $days = in_array($days, self::ACTIVITY_RANGES, true) ? $days : 30;

        return $this->remember($user, "activity:{$days}", function () use ($user, $days): array {
            $from = now()->subDays($days - 1)->startOfDay();

            $created = $this->dailyCounts($this->recipesFor($user), 'created_at', $from);
            $published = $this->dailyCounts(
                $this->recipesFor($user)->where('status', RecipeStatus::Published->value),
                'published_at',
                $from,
            );

            $labels = $createdSeries = $publishedSeries = [];

            for ($day = $from->copy(); $day->lte(now()); $day->addDay()) {
                $key = $day->toDateString();
                $labels[] = $day->format('M j');
                $createdSeries[] = (int) ($created[$key] ?? 0);
                $publishedSeries[] = (int) ($published[$key] ?? 0);
            }

            return ['labels' => $labels, 'created' => $createdSeries, 'published' => $publishedSeries];
        });
    }

    /**
     * @return array<string, int>
     */
    public function statusDistribution(User $user): array
    {
        return $this->remember($user, 'status', function () use ($user): array {
            $counts = $this->recipesFor($user)->toBase()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            return collect(RecipeStatus::values())
                ->mapWithKeys(fn (string $status): array => [$status => (int) ($counts[$status] ?? 0)])
                ->all();
        });
    }

    /**
     * Role name => user count. Only for users who manage accounts.
     *
     * @return array<string, int>|null
     */
    public function usersByRole(User $user): ?array
    {
        if (! $user->can(AppPermissions::USERS_MANAGE)) {
            return null;
        }

        return $this->remember($user, 'roles', function (): array {
            $counts = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass())
                ->groupBy('roles.name')
                ->selectRaw('roles.name as name, count(distinct model_has_roles.model_id) as total')
                ->pluck('total', 'name');

            return collect(AppPermissions::ROLES)
                ->mapWithKeys(fn (string $role): array => [$role => (int) ($counts[$role] ?? 0)])
                ->all();
        });
    }

    /**
     * Categories with the distinct manageable recipes directly in them and, for parents, in them or any subcategory.
     */
    public function categoryCounts(User $user): Builder
    {
        $visible = $this->recipesFor($user)->select('recipes.id')->toBase();

        $direct = DB::table('recipe_category as rc')
            ->whereColumn('rc.category_id', 'categories.id')
            ->whereIn('rc.recipe_id', $visible)
            ->selectRaw('count(distinct rc.recipe_id)');

        $total = DB::table('recipe_category as rc')
            ->join('categories as member', 'member.id', '=', 'rc.category_id')
            ->where(fn ($match) => $match->whereColumn('member.id', 'categories.id')->orWhereColumn('member.parent_id', 'categories.id'))
            ->whereIn('rc.recipe_id', $visible)
            ->selectRaw('count(distinct rc.recipe_id)');

        return Category::query()
            ->select('categories.*')
            ->addSelect(['dashboard_direct_count' => $direct, 'dashboard_total_count' => $total])
            ->hierarchyOrdered();
    }

    /**
     * Public, live recipes only: favorites say nothing a private-recipe reader should not already know.
     */
    public function mostFavorited(User $user): Builder
    {
        return $this->recipesFor($user)->published()
            ->withCount('favoritedBy')
            ->whereHas('favoritedBy')
            ->orderByDesc('favorited_by_count')
            ->orderBy('recipes.id');
    }

    /**
     * Users per plan, from the real entitlements table. Premium shows up here as soon as such a plan exists.
     *
     * @return array<string, int>
     */
    public function usersByPlan(User $user): ?array
    {
        if (! $user->can(AppPermissions::QUOTAS_MANAGE)) {
            return null;
        }

        return $this->remember($user, 'plans', fn (): array => DB::table('plans')
            ->leftJoin('user_entitlements as e', 'e.plan_id', '=', 'plans.id')
            ->groupBy('plans.code', 'plans.id')
            ->orderBy('plans.id')
            ->selectRaw('plans.code as code, count(e.id) as total')
            ->pluck('total', 'code')
            ->map(fn ($total): int => (int) $total)
            ->all());
    }

    /**
     * Personal recipe quota use per plan. Editorial staff are exempt from quotas (as in RecipeQuotaService) and
     * unlimited plans are skipped, so only capped accounts count.
     *
     * @return array<string, array{accounts: int, at_limit: int, near_limit: int, used: int, capacity: int}>|null
     */
    public function quotaUtilization(User $user): ?array
    {
        if (! $user->can(AppPermissions::QUOTAS_MANAGE)) {
            return null;
        }

        return $this->remember($user, 'quota', function (): array {
            $exempt = User::permission([AppPermissions::RECIPES_PUBLISH, AppPermissions::RECIPES_REVIEW])->pluck('users.id');
            $usage = Recipe::query()->countedAgainstQuota()->toBase()->selectRaw('author_id, count(*) as used')->groupBy('author_id');

            return DB::table('user_entitlements as e')
                ->join('plans as p', 'p.id', '=', 'e.plan_id')
                ->leftJoinSub($usage, 'u', 'u.author_id', '=', 'e.user_id')
                ->whereNotIn('e.user_id', $exempt)
                ->whereRaw('coalesce(e.personal_recipe_limit_override, p.max_personal_recipes) is not null')
                ->groupBy('p.code', 'p.id')
                ->orderBy('p.id')
                ->selectRaw('p.code as code, count(*) as accounts')
                ->selectRaw('sum(case when coalesce(u.used, 0) >= coalesce(e.personal_recipe_limit_override, p.max_personal_recipes) then 1 else 0 end) as at_limit')
                ->selectRaw('sum(case when coalesce(u.used, 0) < coalesce(e.personal_recipe_limit_override, p.max_personal_recipes) and coalesce(u.used, 0) * 100 >= 80 * coalesce(e.personal_recipe_limit_override, p.max_personal_recipes) then 1 else 0 end) as near_limit')
                ->selectRaw('sum(coalesce(u.used, 0)) as used')
                ->selectRaw('sum(coalesce(e.personal_recipe_limit_override, p.max_personal_recipes)) as capacity')
                ->get()
                ->mapWithKeys(fn ($row): array => [$row->code => [
                    'accounts' => (int) $row->accounts,
                    'at_limit' => (int) $row->at_limit,
                    'near_limit' => (int) $row->near_limit,
                    'used' => (int) $row->used,
                    'capacity' => (int) $row->capacity,
                ]])
                ->all();
        });
    }

    private function dailyCounts(Builder $recipes, string $column, Carbon $from): array
    {
        return $recipes->toBase()
            ->where($column, '>=', $from)
            ->selectRaw("date_trunc('day', {$column})::date as day, count(*) as total")
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day): array => [Carbon::parse($day)->toDateString() => $total])
            ->all();
    }

    private function remember(User $user, string $key, callable $callback): array
    {
        return Cache::remember("dashboard:{$user->getKey()}:{$key}", self::CACHE_SECONDS, $callback);
    }
}
