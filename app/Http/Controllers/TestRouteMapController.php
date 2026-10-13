<?php

namespace App\Http\Controllers;

use App\Actions\CalculateRouteLength;
use App\Models\BusRouteStop;
use App\Models\Vehicle;
use Inertia\Inertia;
use Inertia\Response;

class TestRouteMapController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(CalculateRouteLength $calculateRouteLength): Response
    {
        $route = Vehicle::query()
            ->with(['currentBusRoute.line', 'currentBusRoute.routeStops.stop'])
            ->where('code', 'PRUEBA-01')
            ->first()?->currentBusRoute;

        return Inertia::render('TestRouteMap', [
            'busRoute' => $route === null ? null : [
                'id' => $route->id,
                'name' => $route->name,
                'line_name' => $route->line->name,
                'geometry' => $route->geometry,
                'length_m' => $calculateRouteLength->handle($route->geometry),
                'stops' => $route->routeStops->map(fn (BusRouteStop $entry) => [
                    'id' => $entry->id,
                    'sequence' => $entry->sequence,
                    'name' => $entry->stop->name,
                    'latitude' => (float) $entry->stop->latitude,
                    'longitude' => (float) $entry->stop->longitude,
                    'distance_from_start_m' => $entry->distance_from_start_m === null
                        ? null
                        : (float) $entry->distance_from_start_m,
                ])->all(),
            ],
        ]);
    }
}
