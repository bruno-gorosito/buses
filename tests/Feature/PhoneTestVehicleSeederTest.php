<?php

use App\Models\BusRoute;
use App\Models\Vehicle;
use Database\Seeders\LaBandaTestRouteSeeder;
use Database\Seeders\PhoneTestVehicleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('creates the active phone test unit on the route without duplicating it', function (): void {
    $this->seed(LaBandaTestRouteSeeder::class);

    $this->seed(PhoneTestVehicleSeeder::class);
    $this->seed(PhoneTestVehicleSeeder::class);

    $this->assertDatabaseCount('vehicles', 1);
    $vehicle = Vehicle::sole();
    expect($vehicle->code)->toBe('PRUEBA-01');
    expect($vehicle->is_active)->toBeTrue();
    expect($vehicle->current_bus_route_id)->toBe(BusRoute::sole()->id);
});

test('does not override changes made to an existing test unit', function (): void {
    $this->seed(LaBandaTestRouteSeeder::class);
    $vehicle = Vehicle::create(['code' => 'PRUEBA-01', 'is_active' => false]);

    $this->seed(PhoneTestVehicleSeeder::class);

    expect($vehicle->fresh()->is_active)->toBeFalse();
    expect($vehicle->fresh()->current_bus_route_id)->toBeNull();
});
