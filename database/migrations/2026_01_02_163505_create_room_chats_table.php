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
        Schema::create('room_chats', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->unsignedInteger('room_id')->index('idx_room_chats_room_id');
            $table->string('author', 256);
            $table->string('author_team', 32);
            $table->json('unitIds');
            $table->string('status', 16);
            $table->string('team', 32);
            $table->text('data');
            $table->datetime('ingame_time');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_chats');
    }
};
