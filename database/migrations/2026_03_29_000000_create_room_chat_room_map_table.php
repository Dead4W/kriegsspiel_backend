<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_chat_room_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_chat_id')->index('idx_room_chat_room_map_room_chat_id');
            $table->unsignedBigInteger('room_map_id')->index('idx_room_chat_room_map_room_map_id');
            $table->timestamps();

            $table->unique(['room_chat_id', 'room_map_id'], 'uq_room_chat_room_map_room_chat_room_map');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_chat_room_map');
    }
};
