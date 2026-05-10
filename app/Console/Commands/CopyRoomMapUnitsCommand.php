<?php

namespace App\Console\Commands;

use App\Models\RoomMap;
use App\Models\RoomMapItem;
use App\Services\RoomMapItemsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CopyRoomMapUnitsCommand extends Command
{
    protected $signature = 'room-map-items:copy-units {firstRoomMapId : ID первой карты} {secondRoomMapId : ID второй карты}';

    protected $description = 'Копирует юнитов между двумя room_map_items в обе стороны, кроме generals и messengers.';

    public function handle(): int
    {
        $firstRoomMapId = (int) $this->argument('firstRoomMapId');
        $secondRoomMapId = (int) $this->argument('secondRoomMapId');

        if ($firstRoomMapId <= 0 || $secondRoomMapId <= 0) {
            $this->error('room_map_id должен быть положительным числом.');
            return self::FAILURE;
        }

        if ($firstRoomMapId === $secondRoomMapId) {
            $this->error('firstRoomMapId и secondRoomMapId должны быть разными.');
            return self::FAILURE;
        }

        $existingMapIds = RoomMap::query()
            ->whereIn('id', [$firstRoomMapId, $secondRoomMapId])
            ->pluck('id')
            ->all();

        $missingMapIds = array_diff([$firstRoomMapId, $secondRoomMapId], $existingMapIds);
        if ($missingMapIds) {
            $this->error('Не найдены room_maps: ' . implode(', ', $missingMapIds));
            return self::FAILURE;
        }

        $firstToSecondCopied = 0;
        $secondToFirstCopied = 0;

        DB::transaction(function () use (
            $firstRoomMapId,
            $secondRoomMapId,
            &$firstToSecondCopied,
            &$secondToFirstCopied
        ) {
            $firstUnits = $this->loadCopyableUnits($firstRoomMapId);
            $secondUnits = $this->loadCopyableUnits($secondRoomMapId);

            $firstToSecondCopied = $this->copyUnits($firstUnits, $secondRoomMapId);
            $secondToFirstCopied = $this->copyUnits($secondUnits, $firstRoomMapId);
        });

        $this->info("Готово: {$firstRoomMapId} -> {$secondRoomMapId}: {$firstToSecondCopied} шт.");
        $this->info("Готово: {$secondRoomMapId} -> {$firstRoomMapId}: {$secondToFirstCopied} шт.");

        return self::SUCCESS;
    }

    private function loadCopyableUnits(int $roomMapId): array
    {
        return RoomMapItem::query()
            ->where('room_map_id', $roomMapId)
            ->where('type', RoomMapItemsService::TYPE_UNIT)
            ->get()
            ->filter(function (RoomMapItem $item): bool {
                $unit = $item->data;
                if (!is_array($unit)) {
                    return false;
                }

                $unitType = strtolower((string) ($unit['type'] ?? ''));
                return !in_array($unitType, ['general', 'messenger'], true);
            })
            ->all();
    }

    /**
     * @param RoomMapItem[] $units
     */
    private function copyUnits(array $units, int $targetRoomMapId): int
    {
        $copied = 0;

        foreach ($units as $unitItem) {
            RoomMapItem::query()->updateOrCreate(
                [
                    'room_map_id' => $targetRoomMapId,
                    'type' => RoomMapItemsService::TYPE_UNIT,
                    'item_id' => (string) $unitItem->item_id,
                ],
                [
                    'data' => $unitItem->data,
                ]
            );

            $copied++;
        }

        return $copied;
    }
}
