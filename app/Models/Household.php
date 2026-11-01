<?php

namespace App\Models;

use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'household_members')
            ->using(HouseholdMember::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(HouseholdInvitation::class);
    }

    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    public function mealPlanTemplates(): HasMany
    {
        return $this->hasMany(MealPlanTemplate::class);
    }

    public function batchCooks(): HasMany
    {
        return $this->hasMany(BatchCook::class);
    }

    public function owner(): ?User
    {
        return $this->members()->with('user')->where('role', 'owner')->first()?->user;
    }
}
