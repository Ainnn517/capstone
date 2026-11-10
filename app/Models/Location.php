<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $table = 'LOCATION';

    protected $primaryKey = 'location_id';

    public $timestamps = false;

    protected $fillable = ['name', 'area_name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    // Get reports filed for this location.
    public function reports(): HasMany
    {
        return $this->hasMany(IssueReport::class, 'location_id', 'location_id');
    }
}
