<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vehicle_id
 * @property int|null $bus_route_id
 * @property string $latitude
 * @property string $longitude
 * @property Carbon $recorded_at Measurement time reported by the device.
 * @property Carbon $received_at Server reception time, assigned exclusively by Laravel.
 * @property string|null $accuracy_m
 * @property string|null $speed_mps
 * @property string|null $heading_deg
 * @property-read Vehicle $vehicle
 * @property-read BusRoute|null $busRoute
 */
#[Fillable(['vehicle_id', 'bus_route_id', 'latitude', 'longitude', 'recorded_at', 'accuracy_m', 'speed_mps', 'heading_deg'])]
class GpsPosition extends Model
{
    /** @var bool */
    public $timestamps = false;

    /**
     * Preserve milliseconds when Eloquent writes the DATETIME(3) columns.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<BusRoute, $this> */
    public function busRoute(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
            'accuracy_m' => 'decimal:3',
            'speed_mps' => 'decimal:3',
            'heading_deg' => 'decimal:3',
        ];
    }
}
