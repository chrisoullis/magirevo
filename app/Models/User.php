<?php

namespace App\Models;

use App\Enums\RecipeVisibility;
use App\Support\AppPermissions;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected static function booted(): void
    {
        static::created(function (self $user): void {
            UserEntitlement::query()->firstOrCreate(['user_id' => $user->getKey()], ['plan_id' => Plan::free()->getKey()]);
        });
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(UserEntitlement::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(AppPermissions::ADMIN) && $this->hasVerifiedEmail();
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole([AppPermissions::ADMIN, AppPermissions::EDITOR]) && $this->hasVerifiedEmail();
    }

    public function sharedRecipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_shares')
            ->wherePivotNull('revoked_at')
            ->where('recipes.visibility', RecipeVisibility::Shared->value)
            ->withPivot(['permission', 'shared_by'])
            ->withTimestamps();
    }

    public function recipesSharedByMe(): HasMany
    {
        return $this->hasMany(RecipeShare::class, 'shared_by');
    }

    public function favoriteRecipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_favorites')->withTimestamps();
    }

    public function recipeNotes(): HasMany
    {
        return $this->hasMany(UserRecipeNote::class);
    }

    public function recipeRatings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    public function recipeRatingReports(): HasMany
    {
        return $this->hasMany(RecipeRatingReport::class, 'reporter_id');
    }

    public function recipeIngredientChanges(): HasMany
    {
        return $this->hasMany(UserRecipeIngredientChange::class);
    }

    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_members')
            ->using(HouseholdMember::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isStaff();
    }
}
