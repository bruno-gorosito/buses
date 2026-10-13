<?php

use Database\Seeders\LaBandaTestRouteSeeder;
use Database\Seeders\PhoneTestVehicleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests can view the saved test route with geographic coordinates and ordered stops', function (): void {
    $this->withoutVite();
    $this->seed(LaBandaTestRouteSeeder::class);
    $this->seed(PhoneTestVehicleSeeder::class);

    $response = $this->get(route('test-route-map'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('TestRouteMap')
        ->where('busRoute.name', 'Plaza Belgrano → Belgrano y Besares')
        ->where('busRoute.geometry.type', 'LineString')
        ->where('busRoute.geometry.coordinates.0', [-64.2431211, -27.7354834])
        ->where('busRoute.length_m', fn ($length) => abs($length - 406.482) < 0.001)
        ->has('busRoute.stops', 4)
        ->where('busRoute.stops.0.sequence', 1)
        ->where('busRoute.stops.0.latitude', -27.7354834)
        ->where('busRoute.stops.0.longitude', -64.2431211)
        ->where('busRoute.stops.1.sequence', 2)
        ->where('busRoute.stops.1.distance_from_start_m', 73.157)
        ->where('busRoute.stops.2.sequence', 3)
        ->where('busRoute.stops.3.sequence', 4)
        ->missing('busRoute.created_at')
        ->missing('busRoute.gps_positions'),
    );
});

test('the map page renders an empty state when the test route has not been seeded', function (): void {
    $this->withoutVite();

    $response = $this->get(route('test-route-map'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('TestRouteMap')
        ->where('busRoute', null),
    );
});
