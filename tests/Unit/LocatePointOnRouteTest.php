<?php

use App\Actions\CalculateCoordinateDistance;
use App\Actions\CalculateRouteLength;
use App\Actions\LocatePointOnRoute;

test('keeps projected coordinates and progress when the point is within tolerance', function (): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0.002, 0]]];

    $result = $locator->withinTolerance($geometry, latitude: 0.0005, longitude: 0.001, maxDistanceMeters: 60.0);

    expect($result)->not->toBeNull();
    expect($result['segment_index'])->toBe(0);
    expect($result['segment_fraction'])->toEqualWithDelta(0.5, 0.000001);
    expect($result['latitude'])->toEqualWithDelta(0.0, 0.000001);
    expect($result['longitude'])->toEqualWithDelta(0.001, 0.000001);
    expect($result['distance_to_route_m'])->toEqualWithDelta(55.597463, 0.001);
    expect($result['distance_from_start_m'])->toEqualWithDelta(111.194927, 0.001);
});

test('returns null when the point is beyond the supplied tolerance', function (): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0.002, 0]]];

    $result = $locator->withinTolerance($geometry, latitude: 0.0005, longitude: 0.001, maxDistanceMeters: 50.0);

    expect($result)->toBeNull();
});

test('accepts a point exactly on the route with zero tolerance', function (): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0.002, 0]]];

    $result = $locator->withinTolerance($geometry, latitude: 0.0, longitude: 0.0, maxDistanceMeters: 0.0);

    expect($result)->not->toBeNull();
    expect($result['distance_to_route_m'])->toBe(0.0);
});

test('rejects invalid tolerances instead of silently accepting a point', function (float $maxDistanceMeters): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0.002, 0]]];

    expect(fn () => $locator->withinTolerance($geometry, 0.0, 0.001, $maxDistanceMeters))
        ->toThrow(InvalidArgumentException::class, 'Distance tolerance must be finite and non-negative.');
})->with([
    'negative tolerance' => [-1.0],
    'not-a-number tolerance' => [NAN],
    'positive infinity' => [INF],
    'negative infinity' => [-INF],
]);

test('does not treat malformed geometry or coordinates as an out of tolerance point', function (array $geometry, float $latitude, float $longitude): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));

    expect(fn () => $locator->withinTolerance($geometry, $latitude, $longitude, 60.0))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'invalid geometry' => [['type' => 'Point', 'coordinates' => [0, 0]], 0.0, 0.0],
    'invalid latitude' => [['type' => 'LineString', 'coordinates' => [[0, 0], [0.002, 0]]], 91.0, 0.0],
]);

test('locates a point on or beside a segment including its endpoints', function (float $latitude, float $longitude, float $expectedFraction, float $expectedLongitude, float $expectedOffsetMeters, float $expectedProgressMeters): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0.002, 0]]];

    $result = $locator->handle($geometry, $latitude, $longitude);

    expect($result['segment_index'])->toBe(0);
    expect($result['segment_fraction'])->toEqualWithDelta($expectedFraction, 0.000001);
    expect($result['latitude'])->toEqualWithDelta(0.0, 0.000001);
    expect($result['longitude'])->toEqualWithDelta($expectedLongitude, 0.000001);
    expect($result['distance_to_route_m'])->toEqualWithDelta($expectedOffsetMeters, 0.001);
    expect($result['distance_from_start_m'])->toEqualWithDelta($expectedProgressMeters, 0.001);
})->with([
    'on midpoint' => [0.0, 0.001, 0.5, 0.001, 0.0, 111.194927],
    'beside midpoint' => [0.0005, 0.001, 0.5, 0.001, 55.597463, 111.194927],
    'before segment' => [0.0, -0.001, 0.0, 0.0, 111.194927, 0.0],
    'after segment' => [0.0, 0.003, 1.0, 0.002, 111.194927, 222.389853],
    'at start' => [0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
    'at end' => [0.0, 0.002, 1.0, 0.002, 0.0, 222.389853],
]);

test('chooses a later segment and includes preceding distance in progress', function (): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0.001, 0], [0.001, 0.002]]];

    $result = $locator->handle($geometry, latitude: 0.001, longitude: 0.0015);

    expect($result['segment_index'])->toBe(1);
    expect($result['segment_fraction'])->toEqualWithDelta(0.5, 0.000001);
    expect($result['latitude'])->toEqualWithDelta(0.001, 0.000001);
    expect($result['longitude'])->toEqualWithDelta(0.001, 0.000001);
    expect($result['distance_to_route_m'])->toEqualWithDelta(55.597463, 0.001);
    expect($result['distance_from_start_m'])->toEqualWithDelta(222.389853, 0.001);
});

test('scales longitude when projecting a diagonal away from the equator', function (): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 60], [0.002, 60.002]]];

    $result = $locator->handle($geometry, latitude: 60.0, longitude: 0.002);

    expect($result['segment_fraction'])->toEqualWithDelta(0.2, 0.00002);
    expect($result['latitude'])->toEqualWithDelta(60.0004, 0.0000001);
    expect($result['longitude'])->toEqualWithDelta(0.0004, 0.0000001);
});

test('handles repeated vertices without division by zero', function (): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0, 0], [0.002, 0]]];

    $result = $locator->handle($geometry, latitude: 0.0, longitude: 0.001);

    expect($result['segment_index'])->toBe(1);
    expect($result['distance_to_route_m'])->toEqualWithDelta(0.0, 0.001);
    expect($result['distance_from_start_m'])->toEqualWithDelta(111.194927, 0.001);
});

test('keeps the first occurrence when overlapping segments are equally close', function (): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0.002, 0], [0, 0]]];

    $result = $locator->handle($geometry, latitude: 0.0, longitude: 0.001);

    expect($result['segment_index'])->toBe(0);
    expect($result['distance_from_start_m'])->toEqualWithDelta(111.194927, 0.001);
});

test('returns a finite result when all vertices are identical', function (): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0, 0]]];

    $result = $locator->handle($geometry, latitude: 0.001, longitude: 0.0);

    expect($result['segment_fraction'])->toBe(0.0);
    expect($result['distance_to_route_m'])->toEqualWithDelta(111.194927, 0.001);
    expect($result['distance_from_start_m'])->toBe(0.0);
});

test('projects across the antimeridian along the short segment', function (array $coordinates): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => $coordinates];

    $result = $locator->handle($geometry, latitude: 0.001, longitude: 180.0);

    expect($result['segment_fraction'])->toEqualWithDelta(0.5, 0.000001);
    expect(abs($result['longitude']))->toEqualWithDelta(180.0, 0.000001);
    expect($result['distance_to_route_m'])->toEqualWithDelta(111.194927, 0.001);
    expect($result['distance_from_start_m'])->toEqualWithDelta(111.194927, 0.001);
})->with([
    'eastbound' => [[[179.999, 0], [-179.999, 0]]],
    'westbound' => [[[-179.999, 0], [179.999, 0]]],
]);

test('rejects invalid point coordinates', function (float $latitude, float $longitude): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));
    $geometry = ['type' => 'LineString', 'coordinates' => [[0, 0], [0.001, 0]]];

    expect(fn () => $locator->handle($geometry, $latitude, $longitude))->toThrow(InvalidArgumentException::class);
})->with([
    'latitude outside range' => [91.0, 0.0],
    'longitude outside range' => [0.0, -181.0],
    'not-a-number' => [NAN, 0.0],
    'infinite longitude' => [0.0, INF],
]);

test('rejects invalid geometry before projection', function (array $geometry): void {
    $distance = new CalculateCoordinateDistance;
    $locator = new LocatePointOnRoute($distance, new CalculateRouteLength($distance));

    expect(fn () => $locator->handle($geometry, 0.0, 0.0))->toThrow(InvalidArgumentException::class);
})->with([
    'wrong type' => [['type' => 'Point', 'coordinates' => [0, 0]]],
    'one vertex' => [['type' => 'LineString', 'coordinates' => [[0, 0]]]],
    'invalid vertex' => [['type' => 'LineString', 'coordinates' => [[0, 0], [0, 91]]]],
]);
