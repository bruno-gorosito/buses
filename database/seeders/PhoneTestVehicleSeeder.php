<?php

namespace Database\Seeders;

use App\Models\BusRoute;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class PhoneTestVehicleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $route = BusRoute::query()
            ->whereHas('line', fn ($query) => $query->where('code', 'DEMO-LABANDA-BELGRANO'))
            ->where('name', 'Plaza Belgrano → Belgrano y Besares')
            ->sole();

        Vehicle::firstOrCreate(['code' => 'PRUEBA-01'], [
            'current_bus_route_id' => $route->id,
            'is_active' => true,
        ]);
    }
}
