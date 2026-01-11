<?php

namespace App\Models;

use App\Enums\TeamEnum;
use \Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int $room_id
 * @property string $author
 * @property TeamEnum $author_team
 * @property array $unitIds
 * @property string $status
 * @property bool $delivered
 * @property TeamEnum $team
 * @property string $data
 * @property \Illuminate\Support\Carbon $ingame_time
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat query()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereAuthor($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereAuthorTeam($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereIngameTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereRoomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereTeam($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereUnitIds($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomChat whereDelivered($value)
 * @mixin \Eloquent
 */
class RoomChat extends Model
{
    protected $fillable = [
        'room_id',
        'team',
        'author',
        'data',
    ];

    protected $casts = [
        'author_team' => TeamEnum::class,
        'team' => TeamEnum::class,
        'unitIds' => 'array',
        'ingame_time' => 'datetime',
    ];
}
