<?php

use App\Actions\EstimateArrivalTime;

test('converts route meters and assumed kilometers per hour to seconds', function (float $distance, float $speed, float $seconds): void {
    $result = (new EstimateArrivalTime)->handle($distance, $speed);

    expect($result)->toBe($seconds);
})->with([
    'bus at 20 kmh' => [3000.0, 20.0, 540.0],
    'walking at 4 kmh' => [200.0, 4.0, 180.0],
    'already at destination' => [0.0, 4.0, 0.0],
]);

test('does not produce an ETA for missing distance or unsafe numeric inputs', function (?float $distance, float $speed): void {
    $result = (new EstimateArrivalTime)->handle($distance, $speed);

    expect($result)->toBeNull();
})->with([
    'no next stop' => [null, 4.0],
    'zero speed' => [200.0, 0.0],
    'negative speed' => [200.0, -4.0],
    'negative distance' => [-200.0, 4.0],
    'infinite distance' => [INF, 4.0],
    'invalid speed' => [200.0, NAN],
    'infinite speed' => [200.0, INF],
    'division underflow' => [200.0, 5.0e-324],
    'result overflow' => [PHP_FLOAT_MAX, 0.01],
]);
