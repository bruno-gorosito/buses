<?php

use App\Actions\CalculateCoordinateDistance;
use App\Actions\CalculateRouteLength;

test('sums the distance of every consecutive segment in meters', function (array $coordinates, float $expectedMeters): void {
    $calculator = new CalculateRouteLength(new CalculateCoordinateDistance);
    $geometry = ['type' => 'LineString', 'coordinates' => $coordinates];

    $lengthMeters = $calculator->handle($geometry);

    expect($lengthMeters)->toEqualWithDelta($expectedMeters, 0.001);
})->with([
    'two points with integer numbers' => [[[0, 0], [1, 0]], 111_194.926645],
    'several unequal segments' => [[[0.0, 0.0], [0.001, 0.0], [0.003, 0.0]], 333.584780],
    'detour counts both legs instead of endpoint distance' => [[[0.0, 0.0], [0.001, 0.0], [0.0, 0.0]], 222.389853],
    'repeated intermediate point contributes zero' => [[[0.0, 0.0], [0.001, 0.0], [0.001, 0.0], [0.003, 0.0]], 333.584780],
    'all points identical' => [[[-58.3816, -34.6037], [-58.3816, -34.6037]], 0.0],
    'GeoJSON uses longitude before latitude' => [[[0.0, 45.0], [180.0, 45.0]], 10_007_543.398010],
]);

test('returns the accumulated distance at every vertex in coordinate order', function (array $coordinates, array $expectedMeters): void {
    $calculator = new CalculateRouteLength(new CalculateCoordinateDistance);
    $geometry = ['type' => 'LineString', 'coordinates' => $coordinates];

    $distancesMeters = $calculator->cumulativeDistances($geometry);

    expect($distancesMeters)->toHaveCount(count($expectedMeters));

    foreach ($expectedMeters as $index => $expectedDistanceMeters) {
        expect($distancesMeters[$index])->toEqualWithDelta($expectedDistanceMeters, 0.001);
    }
})->with([
    'two points' => [[[0, 0], [1, 0]], [0.0, 111_194.926645]],
    'unequal segments' => [[[0.0, 0.0], [0.001, 0.0], [0.003, 0.0]], [0.0, 111.194927, 333.584780]],
    'return to the origin keeps accumulated distance' => [[[0.0, 0.0], [0.001, 0.0], [0.0, 0.0]], [0.0, 111.194927, 222.389853]],
    'repeated point keeps its place in the list' => [[[0.0, 0.0], [0.001, 0.0], [0.001, 0.0], [0.003, 0.0]], [0.0, 111.194927, 111.194927, 333.584780]],
    'all points identical' => [[[-58.3816, -34.6037], [-58.3816, -34.6037]], [0.0, 0.0]],
    'longitude before latitude' => [[[0.0, 45.0], [180.0, 45.0]], [0.0, 10_007_543.398010]],
]);

test('measures a forward section along the route between vertex indexes', function (array $coordinates, int $fromIndex, int $toIndex, float $expectedMeters): void {
    $calculator = new CalculateRouteLength(new CalculateCoordinateDistance);
    $geometry = ['type' => 'LineString', 'coordinates' => $coordinates];

    $distanceMeters = $calculator->distanceBetweenVertices($geometry, $fromIndex, $toIndex);

    expect($distanceMeters)->toEqualWithDelta($expectedMeters, 0.001);
})->with([
    'whole route' => [[[0, 0], [0.001, 0], [0.003, 0]], 0, 2, 333.584780],
    'section after the first vertex' => [[[0, 0], [0.001, 0], [0.003, 0]], 1, 2, 222.389853],
    'same vertex' => [[[0, 0], [0.001, 0], [0.003, 0]], 1, 1, 0.0],
    'detour between identical endpoint coordinates' => [[[0, 0], [0.001, 0], [0, 0]], 0, 2, 222.389853],
    'repeated consecutive vertices' => [[[0, 0], [0.001, 0], [0.001, 0], [0.003, 0]], 1, 2, 0.0],
    'section ending before the final vertex' => [[[0, 0], [0.001, 0], [0.003, 0], [0.004, 0]], 1, 2, 222.389853],
]);

test('rejects nonexistent vertex indexes and travel against the route order', function (int $fromIndex, int $toIndex, string $message): void {
    $calculator = new CalculateRouteLength(new CalculateCoordinateDistance);
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0.001, 0], [0.003, 0]]];

    expect(fn () => $calculator->distanceBetweenVertices($geometry, $fromIndex, $toIndex))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'negative origin' => [-1, 2, 'Both vertex indexes must exist in the route.'],
    'negative destination' => [0, -1, 'Both vertex indexes must exist in the route.'],
    'origin beyond final vertex' => [3, 3, 'Both vertex indexes must exist in the route.'],
    'destination beyond final vertex' => [0, 3, 'Both vertex indexes must exist in the route.'],
    'destination before origin' => [2, 1, 'The destination vertex must not precede the origin vertex.'],
]);

test('rejects malformed geometry even when measuring the same vertex', function (): void {
    $calculator = new CalculateRouteLength(new CalculateCoordinateDistance);

    expect(fn () => $calculator->distanceBetweenVertices(['type' => 'LineString', 'coordinates' => [[0, 0]]], 0, 0))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects invalid LineString structures', function (array $geometry, string $method): void {
    $calculator = new CalculateRouteLength(new CalculateCoordinateDistance);

    expect(fn () => $calculator->{$method}($geometry))->toThrow(InvalidArgumentException::class);
})->with([
    'missing type' => [['coordinates' => [[0, 0], [1, 0]]]],
    'wrong geometry type' => [['type' => 'Point', 'coordinates' => [0, 0]]],
    'missing coordinates' => [['type' => 'LineString']],
    'coordinates are not an array' => [['type' => 'LineString', 'coordinates' => 'invalid']],
    'empty coordinates' => [['type' => 'LineString', 'coordinates' => []]],
    'one point' => [['type' => 'LineString', 'coordinates' => [[0, 0]]]],
    'unordered keys' => [['type' => 'LineString', 'coordinates' => [1 => [0, 0], 2 => [1, 0]]]],
    'point is not an array' => [['type' => 'LineString', 'coordinates' => [[0, 0], null]]],
    'incomplete point' => [['type' => 'LineString', 'coordinates' => [[0, 0], [1]]]],
    'altitude is outside the two dimensional contract' => [['type' => 'LineString', 'coordinates' => [[0, 0], [1, 0, 10]]]],
    'point uses named coordinates' => [['type' => 'LineString', 'coordinates' => [[0, 0], ['longitude' => 1, 'latitude' => 0]]]],
    'numeric strings are not JSON numbers' => [['type' => 'LineString', 'coordinates' => [[0, 0], ['1', 0]]]],
    'boolean latitude is not a number' => [['type' => 'LineString', 'coordinates' => [[0, 0], [1, true]]]],
])->with(['total length' => 'handle', 'accumulated distances' => 'cumulativeDistances']);

test('rejects out of range and non-finite coordinates anywhere in the route', function (array $coordinates, string $method): void {
    $calculator = new CalculateRouteLength(new CalculateCoordinateDistance);

    expect(fn () => $calculator->{$method}(['type' => 'LineString', 'coordinates' => $coordinates]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'invalid first latitude' => [[[0, 91], [1, 0]]],
    'invalid last longitude' => [[[0, 0], [181, 0]]],
    'invalid intermediate latitude' => [[[0, 0], [1, -91], [2, 0]]],
    'not-a-number first longitude' => [[[NAN, 0], [1, 0]]],
    'infinite last latitude' => [[[0, 0], [1, INF]]],
])->with(['total length' => 'handle', 'accumulated distances' => 'cumulativeDistances']);
