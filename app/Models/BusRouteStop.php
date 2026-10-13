<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $bus_route_id
 * @property int $stop_id
 * @property int $sequence
 * @property string|null $distance_from_start_m Calculated from route geometry; never entered manually.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BusRoute $busRoute
 * @property-read Stop $stop
 */
#[Fillable(['bus_route_id', 'stop_id', 'sequence'])]
class BusRouteStop extends Pivot
{
    /** @var string */
    protected $table = 'bus_route_stops';

    /** @var bool */
    public $incrementing = true;

    /** @return BelongsTo<BusRoute, $this> */
    public function busRoute(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class);
    }

    /** @return BelongsTo<Stop, $this> */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'distance_from_start_m' => 'decimal:3',
        ];
    }
}
