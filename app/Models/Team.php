<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $table = 'TEAM';

    protected $primaryKey = 'team_id';

    public $timestamps = false;

    protected $fillable = ['name'];

    // Get reports assigned to this team.
    public function reports(): HasMany
    {
        return $this->hasMany(IssueReport::class, 'team_id', 'team_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'recommended_team_id', 'team_id');
    }
}
