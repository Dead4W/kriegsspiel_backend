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
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('resource_pack_id')
                ->nullable()
                ->index('idx-rooms-resource_pack_id')
                ->after('admin_id');
            $table->foreign('resource_pack_id', 'fk-rooms-resource_pack_id')
                ->references('id')
                ->on('resource_packs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign('fk-rooms-resource_pack_id');
            $table->dropIndex('idx-rooms-resource_pack_id');
            $table->dropColumn('resource_pack_id');
        });
    }
};
