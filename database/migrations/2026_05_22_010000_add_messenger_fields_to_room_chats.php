<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_chats', function (Blueprint $table) {
            $table
                ->uuid('quoted_message_uuid')
                ->nullable()
                ->after('uuid')
                ->index('idx_room_chats_quoted_uuid');
            $table
                ->uuid('messenger_id')
                ->nullable()
                ->after('quoted_message_uuid')
                ->index('idx_room_chats_messenger_id');
            $table
                ->string('delivery_status', 24)
                ->nullable()
                ->after('delivered');
            $table
                ->json('route_points')
                ->nullable()
                ->after('unitIds');
        });
    }

    public function down(): void
    {
        Schema::table('room_chats', function (Blueprint $table) {
            $table->dropIndex('idx_room_chats_quoted_uuid');
            $table->dropIndex('idx_room_chats_messenger_id');
            $table->dropColumn(['quoted_message_uuid', 'messenger_id', 'delivery_status', 'route_points']);
        });
    }
};

