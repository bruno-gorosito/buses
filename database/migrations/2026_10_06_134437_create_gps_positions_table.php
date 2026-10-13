<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gps_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('bus_route_id')->nullable()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->dateTime('recorded_at', precision: 3);
            $table->dateTime('received_at', precision: 3);
            $table->decimal('accuracy_m', 10, 3)->nullable();
            $table->decimal('speed_mps', 8, 3)->nullable();
            $table->decimal('heading_deg', 6, 3)->nullable();

            $table->index(['vehicle_id', 'recorded_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gps_positions');
    }
};
