<?php

use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Database\Seeders\EspanaTestRouteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['gps.ingest_token' => 'eta-test-token', 'gps.eta_speed_kmh' => 4.0, 'gps.eta_max_position_age_seconds' => 60]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13T19:00:10Z'));
});

/** @param array<string, mixed> $attributes */
function storeArrivalReading(TestCase $test, array $attributes = []): Vehicle
{
    $test->seed(EspanaTestRouteSeeder::class);
    $vehicle = Vehicle::sole();
    $test->withToken('eta-test-token')->postJson(route('api.v1.gps-positions.store'), [
        'vehicle_code' => $vehicle->code,
        'latitude' => -27.7224411,
        'longitude' => -64.2282132,
        'recorded_at' => '2026-10-13T19:00:00Z',
        'accuracy_m' => 8,
        ...$attributes,
    ])->assertCreated();

    return $vehicle;
}

test('reports an explicitly assumed speed and ETA using the remaining Espana route distance', function (): void {
    $vehicle = storeArrivalReading($this, ['speed_mps' => 0]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertOk()->assertJsonPath('arrival_estimate.status', 'estimated')
        ->assertJsonPath('arrival_estimate.assumed_speed_kmh', 4)
        ->assertJsonPath('route_tracking.distance_to_next_stop_m', 204.925);
    expect($response->json('arrival_estimate.seconds'))->toBeBetween(184.432, 184.433);
});

test('uses a configured speed instead of silently using a walking assumption', function (): void {
    $vehicle = storeArrivalReading($this);
    config(['gps.eta_speed_kmh' => 20.0]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('arrival_estimate.assumed_speed_kmh', 20);
    expect($response->json('arrival_estimate.seconds'))->toBeBetween(36.886, 36.887);
});

test('does not show an ETA for stale or future measurement times', function (string $recordedAt): void {
    $vehicle = storeArrivalReading($this, ['recorded_at' => $recordedAt]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('arrival_estimate.status', 'unreliable_time')
        ->assertJsonPath('arrival_estimate.seconds', null);
})->with([
    'older than sixty seconds' => '2026-10-13T18:59:09Z',
    'future clock' => '2026-10-13T19:00:11Z',
]);

test('accepts a measurement exactly sixty seconds old', function (): void {
    $vehicle = storeArrivalReading($this, ['recorded_at' => '2026-10-13T18:59:10Z']);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('arrival_estimate.status', 'estimated');
});

test('does not show an ETA when there is no reliable upcoming stop', function (array $reading): void {
    $vehicle = storeArrivalReading($this, $reading);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('arrival_estimate.status', 'unavailable')
        ->assertJsonPath('arrival_estimate.seconds', null);
})->with([
    'outside route' => [['latitude' => -27.74]],
    'poor accuracy' => [['accuracy_m' => 100]],
    'final stop' => [['latitude' => -27.7279652, 'longitude' => -64.2373250]],
]);

test('an invalid speed configuration disables ETA without breaking the location response', function (): void {
    $vehicle = storeArrivalReading($this);
    config(['gps.eta_speed_kmh' => 0.0]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertOk()->assertJsonPath('arrival_estimate.seconds', null)
        ->assertJsonPath('arrival_estimate.status', 'unavailable');
});
