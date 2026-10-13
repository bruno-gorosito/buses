<?php

use Database\Factories\VehicleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['gps.ingest_token' => 'tracking-test-token']);
});

test('returns the latest measurement rather than the last received position and keeps vehicles isolated', function (): void {
    $vehicle = VehicleFactory::new()->create();
    $otherVehicle = VehicleFactory::new()->create();
    foreach ([
        [$vehicle, '2026-10-13T19:00:00.123Z', -27.733],
        [$vehicle, '2026-10-13T18:00:00.123Z', -27.735],
        [$otherVehicle, '2026-10-13T20:00:00.123Z', -27.736],
        [$vehicle, '2026-10-13T19:00:00.123Z', -27.734],
    ] as [$unit, $recordedAt, $latitude]) {
        $this->withToken('tracking-test-token')->postJson(route('api.v1.gps-positions.store'), [
            'vehicle_code' => $unit->code,
            'latitude' => $latitude,
            'longitude' => -64.24,
            'recorded_at' => $recordedAt,
        ])->assertCreated();
    }

    $response = $this->withToken('tracking-test-token')->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertOk()->assertJsonPath('data.latitude', -27.734)
        ->assertJsonPath('data.vehicle_id', $vehicle->id)
        ->assertJsonPath('data.recorded_at', '2026-10-13T19:00:00.123Z')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('returns no position before the first reading is received', function (): void {
    $vehicle = VehicleFactory::new()->create();

    $response = $this->withToken('tracking-test-token')->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertOk()->assertExactJson(['data' => null]);
});

test('does not expose a position without a valid token', function (): void {
    $vehicle = VehicleFactory::new()->create();

    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertUnauthorized();
});

test('does not disclose whether an unknown unit exists before authentication', function (): void {
    $response = $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => 'UNKNOWN']));

    $response->assertUnauthorized();
});

test('does not show inactive vehicles', function (): void {
    $vehicle = VehicleFactory::new()->create(['is_active' => false]);

    $response = $this->withToken('tracking-test-token')->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]));

    $response->assertNotFound();
});

test('the phone page is accessible and does not include the server token', function (): void {
    $this->withoutVite();

    $response = $this->get(route('gps-sender'));

    $response->assertOk()->assertDontSee('tracking-test-token')->assertInertia(fn (Assert $page) => $page->component('GpsSender'));
});
