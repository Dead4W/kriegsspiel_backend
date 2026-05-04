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
        Schema::table('user_tokens', function (Blueprint $table) {
            $table->index('token', 'idx_user_tokens_token');
        });

        Schema::table('room_chats', function (Blueprint $table) {
            $table->index(['room_id', 'uuid'], 'idx_room_chats_room_uuid');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->index(['room_id', 'team', 'room_map_user_id'], 'idx_connections_room_team_map_user');
        });

        Schema::table('room_user', function (Blueprint $table) {
            $table->index(['room_id', 'team'], 'idx_room_user_room_team');
        });

        Schema::table('snapshots', function (Blueprint $table) {
            $table->index(['data_type', 'created_at'], 'idx_snapshots_data_type_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropIndex('idx_snapshots_data_type_created_at');
        });

        Schema::table('room_user', function (Blueprint $table) {
            $table->dropIndex('idx_room_user_room_team');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropIndex('idx_connections_room_team_map_user');
        });

        Schema::table('room_chats', function (Blueprint $table) {
            $table->dropIndex('idx_room_chats_room_uuid');
        });

        Schema::table('user_tokens', function (Blueprint $table) {
            $table->dropIndex('idx_user_tokens_token');
        });
    }
};
