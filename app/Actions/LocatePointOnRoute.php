<?php

namespace App\Actions;

use InvalidArgumentException;

class LocatePointOnRoute
{
    public function __construct(
        private CalculateCoordinateDistance $calculateCoordinateDistance,
        private CalculateRouteLength $calculateRouteLength,
    ) {}

    /**
     * Approximate the closest point with a local equirectangular projection per segment.
     * Intended for short local routes, not long segments or routes near the poles.
     * Equal-distance candidates retain the earliest segment; direction is not inferred.
     *
     * @param  array<string, mixed>  $geometry
     * @return array{segment_index: int, segment_fraction: float, latitude: float, longitude: float, distance_to_route_m: float, distance_from_start_m: float}
     */
    public function handle(array $geometry, float $latitude, float $longitude): array
    {
        $this->calculateCoordinateDistance->handle($latitude, $longitude, $latitude, $longitude);
        $cumulativeMeters = $this->calculateRouteLength->cumulativeDistances($geometry);

        /** @var list<array{0: int|float, 1: int|float}> $coordinates Validated by cumulativeDistances. */
        $coordinates = $geometry['coordinates'];

        $closest = [
            'segment_index' => 0,
            'segment_fraction' => 0.0,
            'latitude' => (float) $coordinates[0][1],
            'longitude' => (float) $coordinates[0][0],
            'distance_to_route_m' => INF,
            'distance_from_start_m' => 0.0,
        ];

        for ($index = 0; $index < count($coordinates) - 1; $index++) {
            [$startLongitude, $startLatitude] = $coordinates[$index];
            [$endLongitude, $endLatitude] = $coordinates[$index + 1];

            $longitudeDifference = $this->wrapLongitude($endLongitude - $startLongitude);
            $longitudeScale = cos(deg2rad(($startLatitude + $endLatitude) / 2));
            $segmentX = deg2rad($longitudeDifference) * $longitudeScale;
            $segmentY = deg2rad($endLatitude - $startLatitude);
            $pointX = deg2rad($this->wrapLongitude($longitude - $startLongitude)) * $longitudeScale;
            $pointY = deg2rad($latitude - $startLatitude);
            $segmentSquaredLength = $segmentX ** 2 + $segmentY ** 2;

            $fraction = $segmentSquaredLength === 0.0
                ? 0.0
                : max(0.0, min(1.0, ($pointX * $segmentX + $pointY * $segmentY) / $segmentSquaredLength));

            $projectedLatitude = $startLatitude + $fraction * ($endLatitude - $startLatitude);
            $projectedLongitude = $this->wrapLongitude($startLongitude + $fraction * $longitudeDifference);
            $distanceToRouteMeters = $this->calculateCoordinateDistance->handle(
                $latitude, $longitude, $projectedLatitude, $projectedLongitude,
            );

            if ($distanceToRouteMeters < $closest['distance_to_route_m']) {
                $closest = [
                    'segment_index' => $index,
                    'segment_fraction' => $fraction,
                    'latitude' => $projectedLatitude,
                    'longitude' => $projectedLongitude,
                    'distance_to_route_m' => $distanceToRouteMeters,
                    'distance_from_start_m' => $cumulativeMeters[$index]
                        + $fraction * ($cumulativeMeters[$index + 1] - $cumulativeMeters[$index]),
                ];
            }
        }

        return $closest;
    }

    /**
     * Accept the projected point only when its offset is within an explicit tolerance.
     * Null means too far from the route; malformed input still throws an exception.
     * Tolerance is inclusive and does not establish GPS quality or travel direction.
     *
     * @param  array<string, mixed>  $geometry
     * @return array{segment_index: int, segment_fraction: float, latitude: float, longitude: float, distance_to_route_m: float, distance_from_start_m: float}|null
     */
    public function withinTolerance(array $geometry, float $latitude, float $longitude, float $maxDistanceMeters): ?array
    {
        if (! is_finite($maxDistanceMeters) || $maxDistanceMeters < 0) {
            throw new InvalidArgumentException('Distance tolerance must be finite and non-negative.');
        }

        $closest = $this->handle($geometry, $latitude, $longitude);

        if ($closest['distance_to_route_m'] > $maxDistanceMeters) {
            return null;
        }

        return $closest;
    }

    /** Normalize a longitude or longitude difference across the antimeridian. */
    private function wrapLongitude(float $longitude): float
    {
        if ($longitude >= -180 && $longitude <= 180) {
            return $longitude;
        }

        return fmod($longitude + 540, 360) - 180;
    }
}
