<?php

use App\Models\BusRoute;
use App\Models\GpsPosition;
use Carbon\CarbonImmutable;
use Database\Factories\VehicleFactory;
use Database\Seeders\LaBandaTestRouteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['gps.ingest_token' => 'gps-test-token']);
});

/** @return array<string, mixed> */
function gpsPositionPayload(string $vehicleCode): array
{
    return [
        'vehicle_code' => $vehicleCode,
        'latitude' => -27.7354834,
        'longitude' => -64.2431211,
        'recorded_at' => '2026-10-13T15:30:00.123-03:00',
    ];
}

test('stores the assigned route and UTC measurement and server reception with milliseconds', function (): void {
    $this->seed(LaBandaTestRouteSeeder::class);
    $route = BusRoute::sole();
    $vehicle = VehicleFactory::new()->create(['current_bus_route_id' => $route->id]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13T19:00:00.987Z'));
    GpsPosition::creating(function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-13T19:00:05.987Z'));
    });

    $response = $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), [
        ...gpsPositionPayload($vehicle->code),
        'accuracy_m' => 8.5,
        'speed_mps' => 4.25,
        'heading_deg' => 90.125,
    ]);

    $response->assertCreated()->assertJsonPath('data.vehicle_id', $vehicle->id)
        ->assertJsonPath('data.bus_route_id', $route->id)
        ->assertJsonPath('data.recorded_at', '2026-10-13T18:30:00.123Z')
        ->assertJsonPath('data.received_at', '2026-10-13T19:00:00.987Z')
        ->assertJsonPath('data.accuracy_m', 8.5)
        ->assertJsonPath('data.speed_mps', 4.25)
        ->assertJsonPath('data.heading_deg', 90.125);
    $this->assertDatabaseCount('gps_positions', 1);
    $position = GpsPosition::sole();
    expect($position->getRawOriginal('recorded_at'))->toBe('2026-10-13 18:30:00.123');
    expect($position->getRawOriginal('received_at'))->toBe('2026-10-13 19:00:00.987');
    expect($position->vehicle_id)->toBe($vehicle->id);
    expect($position->bus_route_id)->toBe($route->id);
    expect($position->latitude)->toBe('-27.7354834');
    expect($position->longitude)->toBe('-64.2431211');
});

test('stores an unassigned vehicle with omitted or null telemetry', function (array $telemetry): void {
    $vehicle = VehicleFactory::new()->create();

    $response = $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), [
        ...gpsPositionPayload($vehicle->code), ...$telemetry,
    ]);

    $response->assertCreated()->assertJsonPath('data.bus_route_id', null)
        ->assertJsonPath('data.accuracy_m', null)
        ->assertJsonPath('data.speed_mps', null)
        ->assertJsonPath('data.heading_deg', null);
    $this->assertDatabaseCount('gps_positions', 1);
})->with([
    'omitted' => [[]],
    'explicit nulls' => [['accuracy_m' => null, 'speed_mps' => null, 'heading_deg' => null]],
]);

test('rejects missing or invalid device tokens without writing positions', function (?string $token): void {
    if ($token !== null) {
        $this->withToken($token);
    }

    $response = $this->postJson(route('api.v1.gps-positions.store'), ['token' => 'gps-test-token']);

    $response->assertUnauthorized()->assertJsonPath('message', 'Invalid GPS device token.');
    $this->assertDatabaseCount('gps_positions', 0);
})->with(['missing token' => null, 'incorrect token' => 'wrong-token']);

test('disables reception when the server token is not configured', function (): void {
    config(['gps.ingest_token' => '']);

    $response = $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), []);

    $response->assertServiceUnavailable()->assertJsonPath('message', 'GPS reception is not configured.');
    $this->assertDatabaseCount('gps_positions', 0);
});

test('rejects an unknown or inactive vehicle', function (bool $inactive): void {
    $vehicleCode = 'UNKNOWN';
    if ($inactive) {
        $vehicleCode = VehicleFactory::new()->create(['is_active' => false])->code;
    }

    $response = $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), gpsPositionPayload($vehicleCode));

    $response->assertUnprocessable()->assertJsonValidationErrors(['vehicle_code']);
    $this->assertDatabaseCount('gps_positions', 0);
})->with(['unknown' => false, 'inactive' => true]);

test('rejects invalid readings and client supplied server fields', function (string $field, mixed $value): void {
    $vehicle = VehicleFactory::new()->create();

    $response = $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), [
        ...gpsPositionPayload($vehicle->code), $field => $value,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    $this->assertDatabaseCount('gps_positions', 0);
})->with([
    'latitude missing' => ['latitude', null],
    'latitude too high' => ['latitude', 90.1],
    'latitude too low' => ['latitude', -90.1],
    'longitude too high' => ['longitude', 180.1],
    'longitude too low' => ['longitude', -180.1],
    'non numeric coordinate' => ['longitude', 'invalid'],
    'missing measurement' => ['recorded_at', null],
    'missing timezone' => ['recorded_at', '2026-10-13T15:30:00.123'],
    'invalid calendar date' => ['recorded_at', '2026-02-30T15:30:00Z'],
    'relative date' => ['recorded_at', 'tomorrow'],
    'unsupported precision' => ['recorded_at', '2026-10-13T15:30:00.123456Z'],
    'negative accuracy' => ['accuracy_m', -1],
    'accuracy overflow' => ['accuracy_m', 10000000],
    'negative speed' => ['speed_mps', -1],
    'speed overflow' => ['speed_mps', 100000],
    'negative heading' => ['heading_deg', -1],
    'full turn heading' => ['heading_deg', 360],
    'supplied reception time' => ['received_at', '2026-10-13T15:30:00.123Z'],
    'null reception time' => ['received_at', null],
    'supplied route' => ['bus_route_id', 123],
    'supplied vehicle id' => ['vehicle_id', 123],
]);

test('reports all missing required fields', function (): void {
    $response = $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), []);

    $response->assertUnprocessable()->assertJsonValidationErrors(['vehicle_code', 'latitude', 'longitude', 'recorded_at']);
    $this->assertDatabaseCount('gps_positions', 0);
});

test('keeps delayed readings as separate history entries', function (): void {
    $vehicle = VehicleFactory::new()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-13T19:00:00.987Z'));
    $payload = gpsPositionPayload($vehicle->code);
    $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), $payload)->assertCreated();

    $response = $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), [
        ...$payload, 'recorded_at' => '2026-10-13T17:00:00Z',
    ]);

    $response->assertCreated()->assertJsonPath('data.recorded_at', '2026-10-13T17:00:00.000Z');
    $this->assertDatabaseCount('gps_positions', 2);
    expect($vehicle->gpsPositions()->orderBy('recorded_at')->pluck('recorded_at')->map->format('H:i:s.v')->all())
        ->toBe(['17:00:00.000', '18:30:00.123']);
});

test('accepts coordinate boundaries and zero telemetry without converting zero to null', function (): void {
    $vehicle = VehicleFactory::new()->create();

    $response = $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), [
        ...gpsPositionPayload($vehicle->code),
        'latitude' => -90,
        'longitude' => 180,
        'accuracy_m' => 0,
        'speed_mps' => 0,
        'heading_deg' => 359.999,
    ]);

    $response->assertCreated()->assertJsonPath('data.accuracy_m', 0)
        ->assertJsonPath('data.speed_mps', 0)
        ->assertJsonPath('data.heading_deg', 359.999);
    $this->assertDatabaseCount('gps_positions', 1);
    expect(GpsPosition::sole()->latitude)->toBe('-90.0000000');
    expect(GpsPosition::sole()->longitude)->toBe('180.0000000');
});

test('limits reception to sixty requests per minute from the same IP', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13T19:00:00Z'));
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), [])->assertUnprocessable();
    }

    $response = $this->withToken('gps-test-token')->postJson(route('api.v1.gps-positions.store'), []);

    $response->assertTooManyRequests();
    $this->assertDatabaseCount('gps_positions', 0);
});
