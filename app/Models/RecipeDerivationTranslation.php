<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeDerivationTranslation extends Model
{
    protected $fillable = ['locale', 'change_summary'];
}
