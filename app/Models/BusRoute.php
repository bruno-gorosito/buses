<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $line_id
 * @property string $name
 * @property array{type: 'LineString', coordinates: list<array{0: float, 1: float}>} $geometry
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Line $line
 * @property-read Collection<int, BusRouteStop> $routeStops
 * @property-read Collection<int, Stop> $stops
 * @property-read Collection<int, Vehicle> $vehicles
 * @property-read Collection<int, GpsPosition> $gpsPositions
 */
#[Fillable(['line_id', 'name', 'geometry', 'is_active'])]
class BusRoute extends Model
{
    /** @return BelongsTo<Line, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    /** @return HasMany<BusRouteStop, $this> */
    public function routeStops(): HasMany
    {
        return $this->hasMany(BusRouteStop::class)->orderBy('sequence');
    }

    /** @return BelongsToMany<Stop, $this, BusRouteStop, 'pivot'> */
    public function stops(): BelongsToMany
    {
        return $this->belongsToMany(Stop::class, 'bus_route_stops')
            ->using(BusRouteStop::class)
            ->withPivot(['id', 'sequence', 'distance_from_start_m'])
            ->withTimestamps()
            ->orderByPivot('sequence');
    }

    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'current_bus_route_id');
    }

    /** @return HasMany<GpsPosition, $this> */
    public function gpsPositions(): HasMany
    {
        return $this->hasMany(GpsPosition::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
