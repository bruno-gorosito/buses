<?php

namespace Database\Seeders;

use App\Actions\LocatePointOnRoute;
use App\Models\Line;
use App\Models\Stop;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EspanaTestRouteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(LocatePointOnRoute $locatePointOnRoute): void
    {
        /**
         * OpenStreetMap ways 1568059873, 1431904454, 1568059871, 1431904453,
         * and 287650130, retrieved 2026-10-13 (OpenStreetMap contributors, ODbL).
         * https://www.openstreetmap.org/way/1568059873
         * Illustrative test stops at intersections, not official bus stops.
         */
        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-64.2282132, -27.7224411],
                [-64.2292085, -27.7230531],
                [-64.2299311, -27.7234821],
                [-64.2302206, -27.7236522],
                [-64.2311977, -27.7242391],
                [-64.2314270, -27.7243689],
                [-64.2315001, -27.7244166],
                [-64.2322279, -27.7248715],
                [-64.2325314, -27.7250538],
                [-64.2332441, -27.7254989],
                [-64.2342220, -27.7260992],
                [-64.2342542, -27.7261185],
                [-64.2342821, -27.7261351],
                [-64.2352430, -27.7267078],
                [-64.2362499, -27.7273118],
                [-64.2363476, -27.7273695],
                [-64.2373250, -27.7279652],
            ],
        ];
        $stops = [
            ['name' => 'España y Alfonsina Storni Norte — inicio de prueba', 'latitude' => -27.7224411, 'longitude' => -64.2282132],
            ['name' => 'España y Ruta Provincial 11 — prueba', 'latitude' => -27.7234821, 'longitude' => -64.2299311],
            ['name' => 'España y San Isidro Norte — prueba', 'latitude' => -27.7254989, 'longitude' => -64.2332441],
            ['name' => 'España y Cassaffousth — fin de prueba', 'latitude' => -27.7279652, 'longitude' => -64.2373250],
        ];

        DB::transaction(function () use ($geometry, $stops, $locatePointOnRoute): void {
            $line = Line::firstOrCreate(['code' => 'DEMO-LABANDA-ESPANA'], ['name' => 'Prueba por España — La Banda']);
            $route = $line->busRoutes()->firstOrCreate(
                ['name' => 'España: Alfonsina Storni Norte → Cassaffousth'],
                ['geometry' => $geometry],
            );
            foreach ($stops as $index => $attributes) {
                $location = $locatePointOnRoute->withinTolerance($route->geometry, $attributes['latitude'], $attributes['longitude'], 1.0);
                if ($location === null) {
                    throw new RuntimeException('The test stop is outside the España route: '.$attributes['name']);
                }
                $stop = Stop::firstOrCreate($attributes);
                $entry = $route->routeStops()->firstOrNew(['sequence' => $index + 1]);
                $entry->stop()->associate($stop);
                $entry->setAttribute('distance_from_start_m', $location['distance_from_start_m']);
                $entry->save();
            }
            $vehicle = Vehicle::firstOrCreate(['code' => 'PRUEBA-01'], ['is_active' => true]);
            $vehicle->currentBusRoute()->associate($route);
            $vehicle->save();
        });
    }
}
