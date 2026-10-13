<?php

namespace App\Actions;

use InvalidArgumentException;

class CalculateCoordinateDistance
{
    private const float EARTH_RADIUS_METERS = 6_371_000.0;

    /**
     * Calculate the shortest surface distance in meters using a spherical Earth.
     * Coordinates are degrees in latitude, longitude order; altitude is ignored.
     */
    public function handle(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $this->validateCoordinates($fromLatitude, $fromLongitude);
        $this->validateCoordinates($toLatitude, $toLongitude);

        $fromLatitudeRadians = deg2rad($fromLatitude);
        $toLatitudeRadians = deg2rad($toLatitude);
        $latitudeDifferenceRadians = deg2rad($toLatitude - $fromLatitude);
        $longitudeDifferenceRadians = deg2rad($toLongitude - $fromLongitude);

        $halfAngleSquaredSine = sin($latitudeDifferenceRadians / 2) ** 2
            + cos($fromLatitudeRadians) * cos($toLatitudeRadians)
            * sin($longitudeDifferenceRadians / 2) ** 2;

        /** Clamp rounding errors so the square roots remain defined near antipodal points. */
        $halfAngleSquaredSine = max(0.0, min(1.0, $halfAngleSquaredSine));

        $centralAngleRadians = 2 * atan2(sqrt($halfAngleSquaredSine), sqrt(1 - $halfAngleSquaredSine));

        return self::EARTH_RADIUS_METERS * $centralAngleRadians;
    }

    private function validateCoordinates(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || $latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('Latitude must be finite and between -90 and 90 degrees.');
        }

        if (! is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Longitude must be finite and between -180 and 180 degrees.');
        }
    }
}
