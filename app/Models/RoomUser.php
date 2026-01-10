<?php

namespace App\Models;

use App\Enums\TeamEnum;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $room_id
 * @property int $user_id
 * @property TeamEnum $team
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|RoomUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomUser query()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomUser whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomUser whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomUser whereRoomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomUser whereTeam($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomUser whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomUser whereUserId($value)
 * @mixin \Eloquent
 */
class RoomUser extends Pivot {
    protected $table = 'room_user';

    protected $casts = [
        'team' => TeamEnum::class,
    ];
}
