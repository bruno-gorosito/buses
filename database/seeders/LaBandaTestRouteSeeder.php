<?php

namespace Database\Seeders;

use App\Actions\LocatePointOnRoute;
use App\Models\Line;
use App\Models\Stop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LaBandaTestRouteSeeder extends Seeder
{
    /**
     * Seed a real street alignment with illustrative stops, not an official bus service.
     * Source: OpenStreetMap way 265091489 and its nodes, retrieved 2026-10-13.
     * https://www.openstreetmap.org/way/265091489 (OpenStreetMap contributors, ODbL).
     * Start beside Plaza Manuel Belgrano; finish at the approach to Besares.
     */
    public function run(LocatePointOnRoute $locatePointOnRoute): void
    {
        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-64.2431211, -27.7354834],
                [-64.2431128, -27.7354124],
                [-64.2430965, -27.7353767],
                [-64.2430732, -27.7353503],
                [-64.2428776, -27.7352461],
                [-64.2425859, -27.7350701],
                [-64.2415803, -27.7344731],
                [-64.2405578, -27.7338898],
                [-64.2398709, -27.7334891],
                [-64.2398320, -27.7334683],
                [-64.2397438, -27.7334474],
            ],
        ];

        $stops = [
            ['name' => 'Plaza Belgrano — inicio de prueba', 'latitude' => -27.7354834, 'longitude' => -64.2431211],
            ['name' => 'Belgrano y Pueyrredón — prueba', 'latitude' => -27.7350701, 'longitude' => -64.2425859],
            ['name' => 'Belgrano y Urquiza — prueba', 'latitude' => -27.7338898, 'longitude' => -64.2405578],
            ['name' => 'Belgrano y Besares — fin de prueba', 'latitude' => -27.7334474, 'longitude' => -64.2397438],
        ];

        DB::transaction(function () use ($geometry, $stops, $locatePointOnRoute): void {
            $line = Line::firstOrCreate(
                ['code' => 'DEMO-LABANDA-BELGRANO'],
                ['name' => 'Prueba por avenida Belgrano — La Banda'],
            );

            $route = $line->busRoutes()->firstOrCreate(
                ['name' => 'Plaza Belgrano → Belgrano y Besares'],
                ['geometry' => $geometry],
            );

            foreach ($stops as $index => $stopAttributes) {
                $location = $locatePointOnRoute->withinTolerance(
                    $route->geometry,
                    $stopAttributes['latitude'],
                    $stopAttributes['longitude'],
                    maxDistanceMeters: 1.0,
                );

                if ($location === null) {
                    throw new RuntimeException('The test stop is outside the route: '.$stopAttributes['name']);
                }

                $stop = Stop::firstOrCreate($stopAttributes);
                $routeStop = $route->routeStops()->firstOrNew(['sequence' => $index + 1]);
                $routeStop->stop()->associate($stop);
                $routeStop->setAttribute('distance_from_start_m', $location['distance_from_start_m']);
                $routeStop->save();
            }
        });
    }
}
