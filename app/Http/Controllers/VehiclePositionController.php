<?php

namespace App\Http\Controllers;

use App\Actions\EstimateRouteProgress;
use App\Actions\EstimateArrivalTime;
use App\Http\Resources\GpsPositionResource;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

class VehiclePositionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Vehicle $vehicle, EstimateRouteProgress $estimateRouteProgress, EstimateArrivalTime $estimateArrivalTime): JsonResponse
    {
        abort_unless($vehicle->is_active, 404);

        $position = $vehicle->gpsPositions()
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        if ($position === null) {
            return response()->json(['data' => null])->header('Cache-Control', 'no-store');
        }
        $tracking = $estimateRouteProgress->handle(
            $position, $vehicle->currentBusRoute, (float) config('gps.route_tolerance_m'),
        );
        $speedKmh = (float) config('gps.eta_speed_kmh');
        $ageSeconds = now()->getTimestamp() - $position->recorded_at->getTimestamp();
        $fresh = $ageSeconds >= 0 && $ageSeconds <= (int) config('gps.eta_max_position_age_seconds');
        $etaSeconds = $fresh ? $estimateArrivalTime->handle($tracking['distance_to_next_stop_m'], $speedKmh) : null;
        $response = (new GpsPositionResource($position))->additional([
            'route_tracking' => $tracking,
            'arrival_estimate' => [
                'seconds' => $etaSeconds,
                'assumed_speed_kmh' => is_finite($speedKmh) ? $speedKmh : null,
                'status' => ! $fresh ? 'unreliable_time' : ($etaSeconds === null ? 'unavailable' : 'estimated'),
            ],
        ])->response();

        return $response->header('Cache-Control', 'no-store');
    }
}
