<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'CATEGORY';

    protected $primaryKey = 'category_id';

    public $timestamps = false;

    protected $fillable = ['name', 'description', 'severity_weight', 'recommended_team_id'];

    public function recommendedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'recommended_team_id', 'team_id');
    }

    // Get reports filed under this category.
    public function reports(): HasMany
    {
        return $this->hasMany(IssueReport::class, 'category_id', 'category_id');
    }
}
