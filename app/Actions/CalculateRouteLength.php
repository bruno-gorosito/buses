<?php

namespace App\Actions;

use InvalidArgumentException;

class CalculateRouteLength
{
    public function __construct(private CalculateCoordinateDistance $calculateCoordinateDistance) {}

    /**
     * Sum consecutive segment lengths in meters for a two-dimensional GeoJSON LineString.
     * GeoJSON positions use longitude, latitude order. The route is not implicitly closed.
     *
     * @param  array<string, mixed>  $geometry
     */
    public function handle(array $geometry): float
    {
        $distancesMeters = $this->cumulativeDistances($geometry);

        return $distancesMeters[array_key_last($distancesMeters)];
    }

    /**
     * Measure a forward section of the route between zero-based vertex indexes.
     * Reverse travel and wrapping past the final vertex are not inferred.
     *
     * @param  array<string, mixed>  $geometry
     */
    public function distanceBetweenVertices(array $geometry, int $fromIndex, int $toIndex): float
    {
        $distancesMeters = $this->cumulativeDistances($geometry);

        if (! array_key_exists($fromIndex, $distancesMeters) || ! array_key_exists($toIndex, $distancesMeters)) {
            throw new InvalidArgumentException('Both vertex indexes must exist in the route.');
        }

        if ($toIndex < $fromIndex) {
            throw new InvalidArgumentException('The destination vertex must not precede the origin vertex.');
        }

        return $distancesMeters[$toIndex] - $distancesMeters[$fromIndex];
    }

    /**
     * Return the distance from the start at each vertex, preserving coordinate order.
     * The first vertex has distance zero; repeated vertices retain their accumulated distance.
     *
     * @param  array<string, mixed>  $geometry
     * @return non-empty-list<float>
     */
    public function cumulativeDistances(array $geometry): array
    {
        if (($geometry['type'] ?? null) !== 'LineString') {
            throw new InvalidArgumentException('Geometry must be a GeoJSON LineString.');
        }

        $coordinates = $geometry['coordinates'] ?? null;

        if (! is_array($coordinates) || ! array_is_list($coordinates) || count($coordinates) < 2) {
            throw new InvalidArgumentException('A LineString must contain an ordered list of at least two positions.');
        }

        $totalMeters = 0.0;
        $distancesMeters = [];
        $previousCoordinate = null;

        foreach ($coordinates as $coordinate) {
            if (! is_array($coordinate) || ! array_is_list($coordinate) || count($coordinate) !== 2
                || (! is_int($coordinate[0]) && ! is_float($coordinate[0]))
                || (! is_int($coordinate[1]) && ! is_float($coordinate[1]))) {
                throw new InvalidArgumentException('Each position must contain exactly two numbers: longitude and latitude.');
            }

            if ($previousCoordinate !== null) {
                $totalMeters += $this->calculateCoordinateDistance->handle(
                    fromLatitude: $previousCoordinate[1],
                    fromLongitude: $previousCoordinate[0],
                    toLatitude: $coordinate[1],
                    toLongitude: $coordinate[0],
                );
            }

            $distancesMeters[] = $totalMeters;
            $previousCoordinate = $coordinate;
        }

        return $distancesMeters;
    }
}
