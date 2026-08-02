<?php

namespace Tests\Unit;

use App\Services\ChatOrdersService;
use PHPUnit\Framework\TestCase;

class ChatOrdersServiceTest extends TestCase
{
    private const OWNED = ['u1' => true, 'u2' => true];

    private static function move(int $x, int $y): array
    {
        return ['type' => 'move', 'state' => ['target' => ['x' => $x, 'y' => $y]]];
    }

    public function test_it_keeps_a_well_formed_order_for_owned_units(): void
    {
        $sanitized = ChatOrdersService::sanitizeWithOwnedUnits([
            'status' => 'ready',
            'origin' => 'author',
            'perUnit' => [
                ['unitId' => 'u1', 'commands' => [self::move(10, 10)]],
                ['unitId' => 'u2', 'commands' => [['type' => 'changeFormation', 'state' => ['formation' => 'column']]]],
            ],
        ], self::OWNED);

        $this->assertNotNull($sanitized);
        $this->assertSame('ready', $sanitized['status']);
        $this->assertSame('author', $sanitized['origin']);
        $this->assertCount(2, $sanitized['perUnit']);
    }

    public function test_a_plan_for_a_unit_the_team_does_not_own_is_dropped(): void
    {
        $sanitized = ChatOrdersService::sanitizeWithOwnedUnits([
            'status' => 'ready',
            'perUnit' => [
                ['unitId' => 'u1', 'commands' => [self::move(10, 10)]],
                ['unitId' => 'theirs', 'commands' => [self::move(20, 20)]],
            ],
        ], self::OWNED);

        $this->assertCount(1, $sanitized['perUnit']);
        $this->assertSame('u1', $sanitized['perUnit'][0]['unitId']);
    }

    public function test_one_bad_plan_does_not_cost_the_player_the_whole_message(): void
    {
        // The message still goes through; only the part that may not be
        // authored is removed.
        $sanitized = ChatOrdersService::sanitizeWithOwnedUnits([
            'perUnit' => [
                ['unitId' => 'theirs', 'commands' => [self::move(20, 20)]],
                ['unitId' => 'u2', 'commands' => [self::move(30, 30)]],
            ],
        ], self::OWNED);

        $this->assertCount(1, $sanitized['perUnit']);
        $this->assertSame('u2', $sanitized['perUnit'][0]['unitId']);
    }

    public function test_a_command_type_the_game_does_not_have_is_dropped(): void
    {
        $sanitized = ChatOrdersService::sanitizeWithOwnedUnits([
            'perUnit' => [[
                'unitId' => 'u1',
                'commands' => [
                    self::move(10, 10),
                    ['type' => 'deleteUnit', 'state' => []],
                    ['type' => 'attack', 'state' => ['targetId' => 'x']],
                ],
            ]],
        ], self::OWNED);

        $types = array_column($sanitized['perUnit'][0]['commands'], 'type');
        $this->assertSame(['move', 'attack'], $types);
    }

    public function test_a_command_without_a_state_is_not_a_command(): void
    {
        $sanitized = ChatOrdersService::sanitizeWithOwnedUnits([
            'perUnit' => [[
                'unitId' => 'u1',
                'commands' => [['type' => 'move'], 'move', ['type' => 'move', 'state' => 'nope']],
            ]],
        ], self::OWNED);

        $this->assertNull($sanitized);
    }

    public function test_an_order_left_with_nothing_to_apply_is_not_stored(): void
    {
        $this->assertNull(ChatOrdersService::sanitizeWithOwnedUnits([
            'status' => 'ready',
            'perUnit' => [['unitId' => 'theirs', 'commands' => [self::move(1, 1)]]],
        ], self::OWNED));

        $this->assertNull(ChatOrdersService::sanitizeWithOwnedUnits(['status' => 'ready'], self::OWNED));
        $this->assertNull(ChatOrdersService::sanitizeWithOwnedUnits(null, self::OWNED));
        $this->assertNull(ChatOrdersService::sanitizeWithOwnedUnits(['perUnit' => 'ready'], self::OWNED));
    }

    public function test_unknown_keys_are_not_stored(): void
    {
        $sanitized = ChatOrdersService::sanitizeWithOwnedUnits([
            'status' => 'ready',
            'delivered' => true,
            'perUnit' => [[
                'unitId' => 'u1',
                'commands' => [self::move(10, 10)],
                'applyToTeam' => 'blue',
            ]],
        ], self::OWNED);

        $this->assertArrayNotHasKey('delivered', $sanitized);
        $this->assertArrayNotHasKey('applyToTeam', $sanitized['perUnit'][0]);
    }

    public function test_a_team_that_owns_nothing_can_author_nothing(): void
    {
        $this->assertNull(ChatOrdersService::sanitizeWithOwnedUnits([
            'perUnit' => [['unitId' => 'u1', 'commands' => [self::move(1, 1)]]],
        ], []));
    }

    public function test_notes_and_labels_survive_because_the_engine_reads_them(): void
    {
        $sanitized = ChatOrdersService::sanitizeWithOwnedUnits([
            'perUnit' => [[
                'unitId' => 'u1',
                'unitLabel' => '1st battalion',
                'commands' => [self::move(10, 10)],
                'notes' => ['hold the bridge'],
            ]],
        ], self::OWNED);

        $this->assertSame('1st battalion', $sanitized['perUnit'][0]['unitLabel']);
        $this->assertSame(['hold the bridge'], $sanitized['perUnit'][0]['notes']);
    }
}
