<?php

return [
    'ingest_token' => env('GPS_INGEST_TOKEN'),
    'route_tolerance_m' => (float) env('GPS_ROUTE_TOLERANCE_M', 30),
    'eta_speed_kmh' => (float) env('GPS_ETA_SPEED_KMH', 4),
    'eta_max_position_age_seconds' => 60,
];
