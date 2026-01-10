<?php

namespace App\Models;

use App\Enums\TeamEnum;
use \Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $room_id
 * @property TeamEnum $team
 * @property array $units
 * @property array $paint
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap query()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap wherePaint($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap whereRoomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap whereTeam($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap whereUnits($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class RoomMap extends Model
{
    protected $fillable = [
        'room_id',
        'team',
        'units',
        'paint',
    ];

    protected $casts = [
        'units' => 'array',
        'paint' => 'array',
        'team' => TeamEnum::class,
    ];
}
