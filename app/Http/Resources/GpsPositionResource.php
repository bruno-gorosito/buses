<?php

namespace App\Http\Resources;

use App\Models\GpsPosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GpsPosition */
class GpsPositionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'bus_route_id' => $this->bus_route_id,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'recorded_at' => $this->recorded_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'received_at' => $this->received_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'accuracy_m' => $this->accuracy_m === null ? null : (float) $this->accuracy_m,
            'speed_mps' => $this->speed_mps === null ? null : (float) $this->speed_mps,
            'heading_deg' => $this->heading_deg === null ? null : (float) $this->heading_deg,
        ];
    }
}
