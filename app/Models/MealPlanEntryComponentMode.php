<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealPlanEntryComponentMode extends Model
{
    protected $fillable = ['meal_plan_entry_id', 'path', 'mode'];
}
