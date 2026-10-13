<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGpsPositionRequest;
use App\Http\Resources\GpsPositionResource;
use App\Models\GpsPosition;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class GpsPositionController extends Controller
{
    public function store(StoreGpsPositionRequest $request): JsonResponse
    {
        $vehicle = Vehicle::query()
            ->where('code', $request->string('vehicle_code')->toString())
            ->where('is_active', true)
            ->firstOrFail();

        $position = new GpsPosition($request->safe()->only([
            'latitude', 'longitude', 'accuracy_m', 'speed_mps', 'heading_deg',
        ]));
        $position->vehicle()->associate($vehicle);
        $position->bus_route_id = $vehicle->current_bus_route_id;
        $position->setAttribute('recorded_at', CarbonImmutable::parse($request->string('recorded_at')->toString())->utc());
        $position->setAttribute('received_at', $request->attributes->get('gps_received_at'));
        $position->save();

        return (new GpsPositionResource($position->refresh()))->response()->setStatusCode(201);
    }
}
