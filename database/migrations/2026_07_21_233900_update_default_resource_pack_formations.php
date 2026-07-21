<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $jsonPath = base_path('resources/default_resourcepack.json');

        if (!File::exists($jsonPath)) {
            throw new RuntimeException("Default resource pack not found: {$jsonPath}");
        }

        $packData = json_decode(File::get($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        $now = now();

        DB::table('resource_packs')
            ->where('is_default', true)
            ->update([
                'data' => json_encode($packData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Previous default pack content is not recoverable.
    }
};
