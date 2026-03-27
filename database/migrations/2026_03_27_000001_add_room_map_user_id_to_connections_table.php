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
        Schema::table('connections', function (Blueprint $table) {
            $table->unsignedBigInteger('room_map_user_id')
                ->nullable()
                ->after('user_id')
                ->index('idx_connections_room_map_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropIndex('idx_connections_room_map_user_id');
            $table->dropColumn('room_map_user_id');
        });
    }
};
