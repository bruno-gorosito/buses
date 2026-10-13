<?php

use App\Models\BusRoute;
use App\Models\Line;
use Database\Seeders\LaBandaTestRouteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeds the La Banda test route with ordered stops and calculated distances', function (): void {
    $this->seed(LaBandaTestRouteSeeder::class);

    $route = BusRoute::with('routeStops.stop')->sole();
    $stops = $route->routeStops;

    $this->assertDatabaseCount('lines', 1);
    $this->assertDatabaseCount('bus_routes', 1);
    $this->assertDatabaseCount('stops', 4);
    $this->assertDatabaseCount('bus_route_stops', 4);
    $this->assertDatabaseCount('vehicles', 0);
    $this->assertDatabaseCount('gps_positions', 0);

    expect($route->geometry['type'])->toBe('LineString');
    expect($route->geometry['coordinates'])->toHaveCount(11);
    expect($stops->pluck('sequence')->all())->toBe([1, 2, 3, 4]);
    expect($stops->map(fn ($entry) => $entry->stop->name)->all())->toBe([
        'Plaza Belgrano — inicio de prueba',
        'Belgrano y Pueyrredón — prueba',
        'Belgrano y Urquiza — prueba',
        'Belgrano y Besares — fin de prueba',
    ]);
    expect((float) $stops[0]->distance_from_start_m)->toBe(0.0);
    expect((float) $stops[1]->distance_from_start_m)->toBeBetween(60.0, 90.0);
    expect((float) $stops[2]->distance_from_start_m)->toBeBetween(290.0, 340.0);
    expect((float) $stops[3]->distance_from_start_m)->toBeBetween(380.0, 440.0);
});

test('rerunning the test seeder preserves identities and recalculates stop distances', function (): void {
    $this->seed(LaBandaTestRouteSeeder::class);
    $route = BusRoute::with('routeStops')->sole();
    $routeId = $route->id;
    $stopIds = $route->routeStops->pluck('stop_id')->all();
    $expectedDistances = $route->routeStops->pluck('distance_from_start_m')->all();
    $entry = $route->routeStops[1];
    $entry->distance_from_start_m = '9999.000';
    $entry->save();

    $this->seed(LaBandaTestRouteSeeder::class);

    $route = BusRoute::with('routeStops')->sole();
    $this->assertDatabaseCount('lines', 1);
    $this->assertDatabaseCount('bus_routes', 1);
    $this->assertDatabaseCount('stops', 4);
    $this->assertDatabaseCount('bus_route_stops', 4);
    expect($route->id)->toBe($routeId);
    expect($route->routeStops->pluck('stop_id')->all())->toBe($stopIds);
    expect($route->routeStops->pluck('distance_from_start_m')->all())->toBe($expectedDistances);
});

test('rolls back new test stops when an existing route has incompatible geometry', function (): void {
    $line = Line::create(['code' => 'DEMO-LABANDA-BELGRANO', 'name' => 'Existing test line']);
    $route = $line->busRoutes()->create([
        'name' => 'Plaza Belgrano → Belgrano y Besares',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[0.0, 0.0], [0.001, 0.0]]],
    ]);

    expect(fn () => $this->seed(LaBandaTestRouteSeeder::class))->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('stops', 0);
    $this->assertDatabaseCount('bus_route_stops', 0);
    expect($route->fresh()->geometry)->toBe($route->geometry);
});
