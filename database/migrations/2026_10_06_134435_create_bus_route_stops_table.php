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
        Schema::create('bus_route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_route_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('stop_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('sequence');
            $table->decimal('distance_from_start_m', 12, 3)->nullable();
            $table->timestamps();

            $table->unique(['bus_route_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bus_route_stops');
    }
};
