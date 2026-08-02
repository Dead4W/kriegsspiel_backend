<?php

namespace App\Services;

use App\Enums\TeamEnum;
use App\Models\Room;
use App\Models\RoomMap;
use App\Models\RoomMapItem;

/**
 * Validates the ready-made commands a chat message may carry.
 *
 * A message with an `orders` payload is applied to its units verbatim when the
 * courier arrives, so the payload is as powerful as the direct order channel
 * and has to be checked as carefully. The checks here mirror the ones
 * `direct_view_send_order` already performs: a client may only write commands
 * for units its own team owns, and only of types the game recognises.
 *
 * Everything is deterministic and reads the umpire's board, which is the only
 * authority on who owns what.
 */
class ChatOrdersService
{
    /** Command types a client may author. Anything else is not a command. */
    public const ALLOWED_COMMAND_TYPES = [
        'move',
        'attack',
        'wait',
        'retreat',
        'changeFormation',
        'follow',
    ];

    /** Keys kept on the payload. Unknown keys are dropped rather than stored. */
    private const ALLOWED_KEYS = [
        'status',
        'origin',
        'generatedAt',
        'summary',
        'unresolvedLocations',
        'hintPositions',
        'perUnit',
        'rawPlan',
    ];

    private const ALLOWED_PER_UNIT_KEYS = [
        'unitId',
        'unitLabel',
        'commands',
        'notes',
        'state',
    ];

    /**
     * Returns the payload with everything the team may not author removed, or
     * null when nothing usable is left.
     *
     * A plan naming a unit of the other side is dropped rather than rejecting
     * the whole message, so that a malformed order never costs a player the
     * ability to talk.
     */
    public static function sanitizeForTeam(mixed $orders, Room $room, TeamEnum $team): ?array
    {
        if (!is_array($orders)) {
            return null;
        }

        // The umpire's board decides ownership; a team's own board can be
        // behind, and is in any case not the authority.
        return self::sanitizeWithOwnedUnits($orders, self::ownedUnitIds($room, $team));
    }

    /**
     * The checks themselves, over an already-known set of owned unit ids.
     *
     * @param array<string, bool> $ownedUnitIds
     */
    public static function sanitizeWithOwnedUnits(mixed $orders, array $ownedUnitIds): ?array
    {
        if (!is_array($orders)) {
            return null;
        }

        $sanitized = array_intersect_key($orders, array_flip(self::ALLOWED_KEYS));
        $sanitized['perUnit'] = self::sanitizePerUnit($orders['perUnit'] ?? null, $ownedUnitIds);

        if (!$sanitized['perUnit']) {
            return null;
        }

        return $sanitized;
    }

    /**
     * Unit ids belonging to a team, as the umpire's board has them.
     *
     * Spectators and the umpire itself own nothing through this path: the
     * umpire edits orders through `chat_orders_update`, which is guarded
     * separately.
     */
    private static function ownedUnitIds(Room $room, TeamEnum $team): array
    {
        if (!in_array($team, [TeamEnum::RED, TeamEnum::BLUE], true)) {
            return [];
        }

        $umpireRoomMapId = (int) RoomMap::query()
            ->where('room_id', $room->id)
            ->where('team', TeamEnum::ADMIN)
            ->value('id');
        if ($umpireRoomMapId <= 0) {
            return [];
        }

        $owned = [];
        $units = RoomMapItem::query()
            ->where('room_map_id', $umpireRoomMapId)
            ->where('type', RoomMapItemsService::TYPE_UNIT)
            ->get(['item_id', 'data']);

        foreach ($units as $unit) {
            $data = is_array($unit->data) ? $unit->data : [];
            if ((string) ($data['team'] ?? '') !== $team->value) {
                continue;
            }
            $owned[(string) $unit->item_id] = true;
        }

        return $owned;
    }

    private static function sanitizePerUnit(mixed $perUnit, array $ownedUnitIds): array
    {
        if (!is_array($perUnit)) {
            return [];
        }

        $plans = [];
        foreach ($perUnit as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $unitId = (string) ($plan['unitId'] ?? '');
            if ($unitId === '' || !isset($ownedUnitIds[$unitId])) {
                continue;
            }

            $commands = self::sanitizeCommands($plan['commands'] ?? null);
            if (!$commands) {
                continue;
            }

            $sanitizedPlan = array_intersect_key($plan, array_flip(self::ALLOWED_PER_UNIT_KEYS));
            $sanitizedPlan['unitId'] = $unitId;
            $sanitizedPlan['commands'] = $commands;
            $plans[] = $sanitizedPlan;
        }

        return $plans;
    }

    private static function sanitizeCommands(mixed $commands): array
    {
        if (!is_array($commands)) {
            return [];
        }

        return array_values(array_filter(
            $commands,
            fn ($command) => is_array($command)
                && in_array((string) ($command['type'] ?? ''), self::ALLOWED_COMMAND_TYPES, true)
                && is_array($command['state'] ?? null),
        ));
    }
}
