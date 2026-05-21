<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $exists = DB::table('resource_packs')
            ->where('is_default', true)
            ->exists();
        if ($exists) {
            return;
        }

        $jsonPath = base_path('resources/default_resourcepack.json');
        $contents = File::exists($jsonPath) ? File::get($jsonPath) : '{}';
        $decoded = json_decode($contents, true);
        $packData = is_array($decoded) ? $decoded : [];
        $now = now();

        DB::table('resource_packs')->insert([
            'user_id' => null,
            'public_id' => Str::uuid()->toString(),
            'name' => 'Default',
            'is_public' => true,
            'is_default' => true,
            'data' => json_encode($packData, JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('resource_packs')
            ->where('is_default', true)
            ->where('name', 'Default')
            ->delete();
    }
};
