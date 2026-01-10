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
        Schema::create('room_maps', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('room_id')->index('idx_room_maps_room_id');
            $table->string('team', 32);
            $table->json('units')->default('[]');
            $table->json('paint')->default('[]');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_maps');
    }
};
