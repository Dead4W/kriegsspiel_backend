<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_map_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_map_id')->index('idx_room_map_items_room_map_id');
            $table->string('type', 32)->index('idx_room_map_items_type');
            $table->string('item_id', 128);
            $table->json('data');
            $table->timestamps();

            $table->index(['room_map_id', 'type'], 'idx_room_map_items_room_map_type');
            $table->unique(['room_map_id', 'type', 'item_id'], 'uq_room_map_items_room_map_type_item');
        });

        $this->backfillItemsFromRoomMaps();
    }

    public function down(): void
    {
        Schema::dropIfExists('room_map_items');
    }

    private function backfillItemsFromRoomMaps(): void
    {
        $now = now();

        DB::table('room_maps')
            ->select(['id', 'units', 'paint', 'logs'])
            ->orderBy('id')
            ->chunkById(100, function ($roomMaps) use ($now) {
                $insertRows = [];

                foreach ($roomMaps as $roomMap) {
                    $typesToData = [
                        'unit' => $this->decodeJsonToArray($roomMap->units),
                        'paint' => $this->decodeJsonToArray($roomMap->paint),
                        'log' => $this->decodeJsonToArray($roomMap->logs),
                    ];

                    foreach ($typesToData as $type => $data) {
                        foreach ($this->normalizeItems($data) as $itemId => $itemData) {
                            $insertRows[] = [
                                'room_map_id' => $roomMap->id,
                                'type' => $type,
                                'item_id' => $itemId,
                                'data' => json_encode($itemData),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }

                if (!$insertRows) {
                    return;
                }

                foreach (array_chunk($insertRows, 500) as $chunkRows) {
                    DB::table('room_map_items')->insert($chunkRows);
                }
            });
    }

    private function decodeJsonToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeItems(array $items): array
    {
        $result = [];

        foreach ($items as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $itemId = is_string($key) || is_int($key) ? (string) $key : null;
            if (($itemId === null || $itemId === '') && isset($value['id'])) {
                $itemId = (string) $value['id'];
            }

            if ($itemId === null || $itemId === '') {
                continue;
            }

            $result[$itemId] = $value;
        }

        return $result;
    }
};
