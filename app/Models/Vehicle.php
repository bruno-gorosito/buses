<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property int|null $current_bus_route_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BusRoute|null $currentBusRoute
 * @property-read Collection<int, GpsPosition> $gpsPositions
 */
#[Fillable(['code', 'current_bus_route_id', 'is_active'])]
class Vehicle extends Model
{
    /**
     * Current assignment is an MVP simplification, not a record of an individual trip.
     * A future trips entity may represent each actual journey.
     *
     * @return BelongsTo<BusRoute, $this>
     */
    public function currentBusRoute(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class, 'current_bus_route_id');
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
            'is_active' => 'boolean',
        ];
    }
}
