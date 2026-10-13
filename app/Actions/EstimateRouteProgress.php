<?php

namespace App\Actions;

use App\Models\BusRoute;
use App\Models\BusRouteStop;
use App\Models\GpsPosition;
use InvalidArgumentException;

class EstimateRouteProgress
{
    /**
     * Construct the geographic operations used to estimate progress.
     */
    public function __construct(
        private LocatePointOnRoute $locatePointOnRoute,
        private CalculateRouteLength $calculateRouteLength,
    ) {}

    /**
     * Estimate forward progress from one reading; direction and passage are not observed.
     * Use the GPS route snapshot to avoid interpreting an old reading on a new assignment.
     * The millimeter allowance accounts for the stored stop distances' precision.
     *
     * @return array{status: string, tolerance_m: float, distance_to_route_m: float|null, projected_point: array{latitude: float, longitude: float}|null, progress_m: float|null, progress_percent: float|null, previous_stop: array{id: int, sequence: int, name: string}|null, next_stop: array{id: int, sequence: int, name: string}|null, distance_to_next_stop_m: float|null}
     */
    public function handle(GpsPosition $position, ?BusRoute $route, float $toleranceMeters): array
    {
        if (! is_finite($toleranceMeters) || $toleranceMeters < 0) {
            throw new InvalidArgumentException('Route tolerance must be finite and non-negative.');
        }

        $result = [
            'status' => 'no_route',
            'tolerance_m' => $toleranceMeters,
            'distance_to_route_m' => null,
            'projected_point' => null,
            'progress_m' => null,
            'progress_percent' => null,
            'previous_stop' => null,
            'next_stop' => null,
            'distance_to_next_stop_m' => null,
        ];
        if ($route === null) {
            return $result;
        }
        if ($position->bus_route_id !== $route->id) {
            return array_replace($result, ['status' => 'route_changed']);
        }
        if (! $route->is_active) {
            return array_replace($result, ['status' => 'inactive_route']);
        }

        $location = $this->locatePointOnRoute->handle($route->geometry, (float) $position->latitude, (float) $position->longitude);
        $result['distance_to_route_m'] = $location['distance_to_route_m'];
        if ($position->accuracy_m !== null && (float) $position->accuracy_m > $toleranceMeters) {
            return array_replace($result, ['status' => 'poor_accuracy']);
        }
        if ($location['distance_to_route_m'] > $toleranceMeters) {
            return array_replace($result, ['status' => 'off_route']);
        }

        $length = $this->calculateRouteLength->handle($route->geometry);
        $result['projected_point'] = ['latitude' => $location['latitude'], 'longitude' => $location['longitude']];
        $result['progress_m'] = $location['distance_from_start_m'];
        $result['progress_percent'] = $length > 0 ? 100 * $location['distance_from_start_m'] / $length : 0.0;
        $route->loadMissing('routeStops.stop');
        $stops = $route->routeStops->filter(fn (BusRouteStop $entry): bool => $entry->stop->is_active);
        if ($stops->contains(fn (BusRouteStop $entry): bool => $entry->distance_from_start_m === null)) {
            return array_replace($result, ['status' => 'stops_not_ready']);
        }

        $result['status'] = 'on_route';
        foreach ($stops as $entry) {
            $stop = ['id' => $entry->stop_id, 'sequence' => $entry->sequence, 'name' => $entry->stop->name];
            if ((float) $entry->distance_from_start_m <= $location['distance_from_start_m'] + 0.001) {
                $result['previous_stop'] = $stop;
            } else {
                $result['next_stop'] = $stop;
                $result['distance_to_next_stop_m'] = (float) $entry->distance_from_start_m - $location['distance_from_start_m'];
                break;
            }
        }

        return $result;
    }
}
