<?php

use App\Models\BusRoute;
use App\Models\GpsPosition;
use App\Models\Vehicle;
use Database\Seeders\EspanaTestRouteSeeder;
use Database\Seeders\LaBandaTestRouteSeeder;
use Database\Seeders\PhoneTestVehicleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('loads the Espana intersections in order and assigns the phone without duplicating the route', function (): void {
    $this->withoutVite();

    $this->seed(EspanaTestRouteSeeder::class);
    $this->seed(EspanaTestRouteSeeder::class);

    $this->assertDatabaseCount('lines', 1);
    $this->assertDatabaseCount('bus_routes', 1);
    $this->assertDatabaseCount('stops', 4);
    $this->assertDatabaseCount('bus_route_stops', 4);
    $this->assertDatabaseCount('vehicles', 1);
    $route = BusRoute::with('routeStops.stop')->sole();
    expect(Vehicle::sole()->current_bus_route_id)->toBe($route->id);
    expect($route->geometry['coordinates'])->toHaveCount(17);
    expect($route->routeStops->pluck('sequence')->all())->toBe([1, 2, 3, 4]);
    expect($route->routeStops->map(fn ($entry) => $entry->stop->name)->all())->toBe([
        'España y Alfonsina Storni Norte — inicio de prueba',
        'España y Ruta Provincial 11 — prueba',
        'España y San Isidro Norte — prueba',
        'España y Cassaffousth — fin de prueba',
    ]);
    expect((float) $route->routeStops[0]->distance_from_start_m)->toBe(0.0);
    expect((float) $route->routeStops[1]->distance_from_start_m)->toBeBetween(190, 220);
    expect((float) $route->routeStops[2]->distance_from_start_m)->toBeBetween(590, 630);
    expect((float) $route->routeStops[3]->distance_from_start_m)->toBeBetween(1070, 1110);
    $this->get(route('test-route-map'))->assertInertia(fn (Assert $page) => $page
        ->component('TestRouteMap')
        ->where('busRoute.id', $route->id)
        ->where('busRoute.name', 'España: Alfonsina Storni Norte → Cassaffousth')
        ->where('busRoute.geometry.coordinates.0', [-64.2282132, -27.7224411])
        ->has('busRoute.stops', 4),
    );
});

test('preserves the old route and GPS history and matches only new readings to Espana', function (): void {
    config(['gps.ingest_token' => 'espana-test-token']);
    $this->seed(LaBandaTestRouteSeeder::class);
    $this->seed(PhoneTestVehicleSeeder::class);
    $vehicle = Vehicle::sole();
    $oldRoute = BusRoute::sole();
    $oldGeometry = $oldRoute->geometry;
    $oldPayload = [
        'vehicle_code' => $vehicle->code,
        'latitude' => -27.7354834,
        'longitude' => -64.2431211,
        'recorded_at' => '2026-10-13T19:00:00Z',
    ];
    $this->withToken('espana-test-token')->postJson(route('api.v1.gps-positions.store'), $oldPayload)->assertCreated();
    $oldPosition = GpsPosition::sole();

    $this->seed(EspanaTestRouteSeeder::class);

    $this->assertDatabaseCount('bus_routes', 2);
    $this->assertDatabaseCount('vehicles', 1);
    expect($oldRoute->fresh()->geometry)->toBe($oldGeometry);
    expect($oldPosition->fresh()->bus_route_id)->toBe($oldRoute->id);
    expect($vehicle->fresh()->current_bus_route_id)->not->toBe($oldRoute->id);
    $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]))
        ->assertJsonPath('route_tracking.status', 'route_changed')
        ->assertJsonPath('route_tracking.next_stop', null);
    $this->postJson(route('api.v1.gps-positions.store'), [
        ...$oldPayload,
        'latitude' => -27.7224411,
        'longitude' => -64.2282132,
        'recorded_at' => '2026-10-13T19:01:00Z',
    ])->assertCreated();
    $this->getJson(route('api.v1.vehicles.latest-position', ['vehicle' => $vehicle->code]))
        ->assertJsonPath('route_tracking.status', 'on_route')
        ->assertJsonPath('route_tracking.distance_to_next_stop_m', 204.925)
        ->assertJsonPath('route_tracking.next_stop.name', 'España y Ruta Provincial 11 — prueba');
    $this->assertDatabaseCount('gps_positions', 2);
});

test('does not change the phone assignment when the saved Espana geometry is incompatible', function (): void {
    $this->seed(EspanaTestRouteSeeder::class);
    $route = BusRoute::sole();
    $route->update(['geometry' => ['type' => 'LineString', 'coordinates' => [[0, 0], [0.001, 0]]]]);
    $vehicle = Vehicle::sole();
    $vehicle->current_bus_route_id = null;
    $vehicle->save();

    expect(fn () => $this->seed(EspanaTestRouteSeeder::class))->toThrow(RuntimeException::class);

    expect($vehicle->fresh()->current_bus_route_id)->toBeNull();
    $this->assertDatabaseCount('bus_route_stops', 4);
});
