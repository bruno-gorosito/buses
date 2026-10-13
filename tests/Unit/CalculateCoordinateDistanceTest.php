<?php

use App\Actions\CalculateCoordinateDistance;

test('returns zero for the same coordinates', function () {
    $calculator = new CalculateCoordinateDistance;

    $distanceMeters = $calculator->handle(-34.6037, -58.3816, -34.6037, -58.3816);

    expect($distanceMeters)->toBe(0.0);
});

test('returns the expected spherical distance in meters', function (float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude, float $expectedMeters) {
    $calculator = new CalculateCoordinateDistance;

    $distanceMeters = $calculator->handle($fromLatitude, $fromLongitude, $toLatitude, $toLongitude);

    expect($distanceMeters)->toEqualWithDelta($expectedMeters, 0.001);
})->with([
    'one degree on the equator' => [0.0, 0.0, 0.0, 1.0, 111_194.926645],
    'one degree north in the southern hemisphere' => [-34.0, -58.0, -33.0, -58.0, 111_194.926645],
    'short distance' => [0.0, 0.0, 0.0, 0.001, 111.194927],
    'quarter circumference through the north pole' => [45.0, 0.0, 45.0, 180.0, 10_007_543.398010],
    'crossing the antimeridian takes the short arc' => [0.0, 179.999, 0.0, -179.999, 222.389853],
    'opposite points on the equator' => [0.0, 0.0, 0.0, 180.0, 20_015_086.796021],
]);

test('returns the same distance when the points are reversed', function () {
    $calculator = new CalculateCoordinateDistance;

    $forwardMeters = $calculator->handle(-34.6037, -58.3816, -34.6158, -58.4333);
    $reverseMeters = $calculator->handle(-34.6158, -58.4333, -34.6037, -58.3816);

    expect($forwardMeters)->toEqualWithDelta($reverseMeters, 0.000001);
});

test('rejects coordinates outside geographic ranges or with non-finite values', function (float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude, string $message) {
    $calculator = new CalculateCoordinateDistance;

    expect(fn () => $calculator->handle($fromLatitude, $fromLongitude, $toLatitude, $toLongitude))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'origin latitude below range' => [-90.001, 0.0, 0.0, 0.0, 'Latitude must be finite and between -90 and 90 degrees.'],
    'destination latitude above range' => [0.0, 0.0, 90.001, 0.0, 'Latitude must be finite and between -90 and 90 degrees.'],
    'origin longitude below range' => [0.0, -180.001, 0.0, 0.0, 'Longitude must be finite and between -180 and 180 degrees.'],
    'destination longitude above range' => [0.0, 0.0, 0.0, 180.001, 'Longitude must be finite and between -180 and 180 degrees.'],
    'not-a-number latitude' => [NAN, 0.0, 0.0, 0.0, 'Latitude must be finite and between -90 and 90 degrees.'],
    'infinite latitude' => [0.0, 0.0, INF, 0.0, 'Latitude must be finite and between -90 and 90 degrees.'],
    'not-a-number longitude' => [0.0, 0.0, 0.0, NAN, 'Longitude must be finite and between -180 and 180 degrees.'],
    'infinite longitude' => [0.0, -INF, 0.0, 0.0, 'Longitude must be finite and between -180 and 180 degrees.'],
]);
