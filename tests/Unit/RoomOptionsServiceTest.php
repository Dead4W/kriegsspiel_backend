<?php

namespace Tests\Unit;

use App\Services\RoomOptionsService;
use PHPUnit\Framework\TestCase;

class RoomOptionsServiceTest extends TestCase
{
    private RoomOptionsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomOptionsService();
    }

    public function test_a_task_may_still_be_changed_once_the_fighting_has_begun(): void
    {
        $kept = $this->service->keepRetaskableSettings([
            'red' => ['mission' => ['type' => 'capture', 'point' => ['x' => 10, 'y' => 20]]],
        ]);

        $this->assertSame(
            ['red' => ['mission' => ['type' => 'capture', 'point' => ['x' => 10, 'y' => 20]]]],
            $kept
        );
    }

    public function test_a_setup_may_not(): void
    {
        // Spawns and briefings describe how a side was set up; rewriting them
        // afterwards would edit a game already in progress.
        $kept = $this->service->keepRetaskableSettings([
            'red' => ['spawns' => [['from' => ['x' => 0, 'y' => 0], 'to' => ['x' => 1, 'y' => 1]]]],
            'blue' => ['briefing' => 'hold the bridge'],
        ]);

        $this->assertSame([], $kept);
    }

    public function test_a_mixed_patch_keeps_only_the_task(): void
    {
        $kept = $this->service->keepRetaskableSettings([
            'red' => ['briefing' => 'ignored', 'mission' => ['type' => 'destroy']],
        ]);

        $this->assertSame(['red' => ['mission' => ['type' => 'destroy']]], $kept);
    }

    public function test_a_task_survives_normalisation_with_its_point_intact(): void
    {
        // The task is a nested object where every other per-team setting is a
        // string or a list, so it is worth proving the depth cap does not eat it.
        $normalized = $this->service->normalizePerTeamSettingsPatch([
            'red' => [
                'mission' => [
                    'type' => 'defend',
                    'point' => ['x' => 1234.5, 'y' => 678.9],
                    'radiusMeters' => 250,
                    'byTime' => '1882-06-12 14:00:00',
                ],
            ],
        ]);

        $this->assertSame(
            [
                'type' => 'defend',
                'point' => ['x' => 1234.5, 'y' => 678.9],
                'radiusMeters' => 250,
                'byTime' => '1882-06-12 14:00:00',
            ],
            $normalized['red']['mission']
        );
    }

    public function test_a_force_allowance_survives_the_trip_from_the_settings_screen(): void
    {
        $normalized = $this->service->normalizeAdminPatch([
            'teamUnitLimits' => [
                'red' => ['marine' => 3, 'artillery' => 1],
                'blue' => ['marine' => 2],
            ],
        ]);

        $this->assertSame(
            ['marine' => 3, 'artillery' => 1],
            $normalized['teamUnitLimits']['red']
        );
        $this->assertSame(['marine' => 2], $normalized['teamUnitLimits']['blue']);
    }

    public function test_an_allowance_of_none_is_a_ban_and_not_a_blank(): void
    {
        // A type left out of the table is unlimited, so "none of these" has to
        // be said as a stored nothing rather than by omitting the row.
        $normalized = $this->service->normalizeAdminPatch([
            'teamUnitLimits' => ['red' => ['militia' => 0, 'marine' => -4]],
        ]);

        $this->assertArrayHasKey('militia', $normalized['teamUnitLimits']['red']);
        $this->assertNull($normalized['teamUnitLimits']['red']['militia']);
        $this->assertNull($normalized['teamUnitLimits']['red']['marine']);
    }

    public function test_a_player_is_never_told_the_other_side_s_allowance(): void
    {
        $sanitized = $this->service->sanitizeOptionsForTeam([
            'teamUnitLimits' => [
                'red' => ['marine' => 3],
                'blue' => ['marine' => 9],
            ],
        ], 'red');

        $this->assertSame(['marine' => 3], $sanitized['teamUnitLimits']['red']);
        $this->assertArrayNotHasKey('blue', $sanitized['teamUnitLimits']);
    }

    public function test_a_player_is_never_told_the_other_side_s_task(): void
    {
        $sanitized = $this->service->sanitizeOptionsForTeam([
            'perTeamSettings' => [
                'red' => ['mission' => ['type' => 'capture', 'point' => ['x' => 1, 'y' => 2]]],
                'blue' => ['mission' => ['type' => 'defend', 'point' => ['x' => 3, 'y' => 4]]],
            ],
        ], 'red');

        $this->assertArrayHasKey('red', $sanitized['perTeamSettings']);
        $this->assertArrayNotHasKey('blue', $sanitized['perTeamSettings']);
    }
}
