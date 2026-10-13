<?php

use App\Models\BusRoute;
use App\Models\Vehicle;
use Database\Factories\VehicleFactory;
use Database\Seeders\LaBandaTestRouteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['gps.ingest_token' => 'progress-test-token', 'gps.route_tolerance_m' => 30.0]);
});

/** @param array<string, mixed> $coordinates
 * @return array{BusRoute, Vehicle}
 */
function storeRouteProgressReading(TestCase $test, array $coordinates = []): array
{
    $test->seed(LaBandaTestRouteSeeder::class);
    $route = BusRoute::sole();
    $vehicle = VehicleFactory::new()->create(['current_bus_route_id' => $route->id]);
    $test->withToken('progress-test-token')->postJson(route('api.v1.gps-positions.store'), [
        'vehicle_code' => $vehicle->code,
        'latitude' => -27.7354834,
        'longitude' => -64.2431211,
        'recorded_at' => '2026-10-13T19:00:00.123Z',
        'accuracy_m' => 8,
        ...$coordinates,
    ])->assertCreated();

    return [$route, $vehicle];
}

test('estimates progress and distance along the route to the next stop', function (array $coordinates, int $previous, ?int $next, float $progress, ?float $remaining): void {
    [, $vehicle] = storeRouteProgressReading($this, $coordinates);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertOk()->assertJsonPath('route_tracking.status', 'on_route')
        ->assertJsonPath('route_tracking.previous_stop.sequence', $previous)
        ->assertJsonPath('route_tracking.distance_to_route_m', 0);
    if ($next === null) {
        $response->assertJsonPath('route_tracking.next_stop', null)
            ->assertJsonPath('route_tracking.distance_to_next_stop_m', null);
    } else {
        $response->assertJsonPath('route_tracking.next_stop.sequence', $next);
        expect($response->json('route_tracking.distance_to_next_stop_m'))->toBeBetween($remaining - 0.003, $remaining + 0.003);
    }
    expect($response->json('route_tracking.progress_m'))->toBeBetween($progress - 0.002, $progress + 0.002);
    expect($response->json('route_tracking.progress_percent'))->toBeBetween(0, 100);
})->with([
    'start' => [[], 1, 2, 0.0, 73.157],
    'Pueyrredon reached despite rounded stored distance' => [['latitude' => -27.7350701, 'longitude' => -64.2425859], 2, 3, 73.157, 238.898],
    'Urquiza reached' => [['latitude' => -27.7338898, 'longitude' => -64.2405578], 3, 4, 312.055, 94.427],
    'end reached despite rounded stored distance' => [['latitude' => -27.7334474, 'longitude' => -64.2397438], 4, null, 406.482, null],
]);

test('does not estimate a next stop for GPS far from the route', function (): void {
    [, $vehicle] = storeRouteProgressReading($this, ['latitude' => -27.74]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertOk()->assertJsonPath('route_tracking.status', 'off_route')
        ->assertJsonPath('route_tracking.progress_m', null)
        ->assertJsonPath('route_tracking.projected_point', null)
        ->assertJsonPath('route_tracking.distance_to_next_stop_m', null)
        ->assertJsonPath('route_tracking.next_stop', null);
    expect($response->json('route_tracking.distance_to_route_m'))->toBeGreaterThan(30);
});

test('estimates progress between Pueyrredon and Urquiza rather than waiting for a stop', function (): void {
    [, $vehicle] = storeRouteProgressReading($this, ['latitude' => -27.7344731, 'longitude' => -64.2415803]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('route_tracking.status', 'on_route')
        ->assertJsonPath('route_tracking.previous_stop.sequence', 2)
        ->assertJsonPath('route_tracking.next_stop.sequence', 3);
    expect($response->json('route_tracking.progress_m'))->toBeBetween(180, 210);
    expect($response->json('route_tracking.distance_to_next_stop_m'))->toBeBetween(100, 140);
});

test('uses the configured tolerance and does not enlarge it for poor GPS accuracy', function (): void {
    [, $vehicle] = storeRouteProgressReading($this, ['accuracy_m' => 31]);
    config(['gps.route_tolerance_m' => 30.0]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('route_tracking.status', 'poor_accuracy')
        ->assertJsonPath('route_tracking.distance_to_next_stop_m', null)
        ->assertJsonPath('route_tracking.next_stop', null);
    config(['gps.route_tolerance_m' => 40.0]);
    $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]))
        ->assertJsonPath('route_tracking.status', 'on_route')
        ->assertJsonPath('route_tracking.tolerance_m', 40);
});

test('accepts unknown GPS accuracy and accuracy exactly at the threshold', function (?float $accuracy): void {
    [, $vehicle] = storeRouteProgressReading($this, ['accuracy_m' => $accuracy]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('route_tracking.status', 'on_route')
        ->assertJsonPath('route_tracking.next_stop.sequence', 2);
})->with(['unknown' => null, 'threshold' => 30.0]);

test('does not match a previous reading to a new or removed route assignment', function (bool $removed): void {
    [, $vehicle] = storeRouteProgressReading($this);
    if ($removed) {
        $vehicle->current_bus_route_id = null;
    } else {
        $replacement = $vehicle->currentBusRoute->replicate();
        $replacement->name = 'Another route';
        $replacement->save();
        $vehicle->current_bus_route_id = $replacement->id;
    }
    $vehicle->save();

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('route_tracking.status', $removed ? 'no_route' : 'route_changed')
        ->assertJsonPath('route_tracking.distance_to_next_stop_m', null)
        ->assertJsonPath('route_tracking.next_stop', null);
})->with(['changed' => false, 'removed' => true]);

test('does not estimate progress on an inactive route', function (): void {
    [$route, $vehicle] = storeRouteProgressReading($this);
    $route->update(['is_active' => false]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('route_tracking.status', 'inactive_route')
        ->assertJsonPath('route_tracking.distance_to_next_stop_m', null)
        ->assertJsonPath('route_tracking.progress_m', null);
});

test('does not guess the next stop when stop distances have not been calculated', function (): void {
    [$route, $vehicle] = storeRouteProgressReading($this);
    $entry = $route->routeStops()->where('sequence', 2)->firstOrFail();
    $entry->distance_from_start_m = null;
    $entry->save();

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('route_tracking.status', 'stops_not_ready')
        ->assertJsonPath('route_tracking.distance_to_next_stop_m', null)
        ->assertJsonPath('route_tracking.next_stop', null);
});

test('skips inactive stops when estimating the next stop', function (): void {
    [$route, $vehicle] = storeRouteProgressReading($this);
    $route->routeStops()->where('sequence', 2)->firstOrFail()->stop->update(['is_active' => false]);

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertJsonPath('route_tracking.next_stop.sequence', 3);
    expect($response->json('route_tracking.distance_to_next_stop_m'))->toBeBetween(312.054, 312.056);
});
