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
        Schema::create('resource_packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index('idx-resource_packs-user_id');
            $table->string('public_id', 64)->unique('uniq-resource_packs-public_id');
            $table->string('name', 255);
            $table->boolean('is_public')->default(false)->index('idx-resource_packs-is_public');
            $table->boolean('is_default')->default(false)->index('idx-resource_packs-is_default');
            $table->json('data');
            $table->timestamps();

            $table->foreign('user_id', 'fk-resource_packs-user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_packs');
    }
};
