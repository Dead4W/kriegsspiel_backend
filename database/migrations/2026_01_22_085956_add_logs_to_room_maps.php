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
        Schema::table('room_maps', function (Blueprint $table) {
            $table->json('logs')->default('{}');
        });

        Schema::table('snapshots', function (Blueprint $table) {
            $table->json('logs')->default('{}');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_maps', function (Blueprint $table) {
            $table->dropColumn('logs');
        });

        Schema::table('snapshots', function (Blueprint $table) {
            $table->json('logs')->default('{}');
        });
    }
};
