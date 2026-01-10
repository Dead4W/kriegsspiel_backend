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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid()->index('idx-uuid');
            $table->string('stage', 32);
            $table->string('password');
            $table->string('admin_key', 64);
            $table->string('red_key', 64);
            $table->string('blue_key', 64);
            $table->json('options');
            $table->string('name', 256);
            $table->datetime('ingame_time')->default('1882-06-12 09:00:00');
            $table->unsignedInteger('admin_id')->index('idx-admin_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
