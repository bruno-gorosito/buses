<?php

namespace App\Actions;

class EstimateArrivalTime
{
    /**
     * Estimate seconds from remaining route meters at a constant assumed speed.
     * This does not account for stops, traffic, acceleration, or actual travel direction.
     * Null also covers invalid or disabled speed configuration; never divide by zero.
     */
    public function handle(?float $remainingMeters, float $assumedSpeedKmh): ?float
    {
        if ($remainingMeters === null || ! is_finite($remainingMeters) || $remainingMeters < 0
            || ! is_finite($assumedSpeedKmh) || $assumedSpeedKmh <= 0) {
            return null;
        }

        $metersPerSecond = $assumedSpeedKmh / 3.6;
        if ($metersPerSecond === 0.0) {
            return null;
        }
        $seconds = $remainingMeters / $metersPerSecond;

        return is_finite($seconds) ? $seconds : null;
    }
}
