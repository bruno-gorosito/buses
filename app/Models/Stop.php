<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $latitude
 * @property string $longitude
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, BusRouteStop> $routeStops
 * @property-read Collection<int, BusRoute> $busRoutes
 */
#[Fillable(['name', 'latitude', 'longitude', 'is_active'])]
class Stop extends Model
{
    /** @return HasMany<BusRouteStop, $this> */
    public function routeStops(): HasMany
    {
        return $this->hasMany(BusRouteStop::class);
    }

    /** @return BelongsToMany<BusRoute, $this, BusRouteStop, 'pivot'> */
    public function busRoutes(): BelongsToMany
    {
        return $this->belongsToMany(BusRoute::class, 'bus_route_stops')
            ->using(BusRouteStop::class)
            ->withPivot(['id', 'sequence', 'distance_from_start_m'])
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }
}
