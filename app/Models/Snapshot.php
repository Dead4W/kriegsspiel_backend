<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $room_map_id
 * @property array $units
 * @property array $paint
 * @property \Illuminate\Support\Carbon $ingame_time
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot query()
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot whereIngameTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot wherePaint($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot whereRoomMapId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot whereUnits($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Snapshot extends Model
{
    protected $fillable = [
        'room_map_id',
        'units',
        'paint',
        'ingame_time',
    ];

    protected $casts = [
        'units' => 'array',
        'paint' => 'array',
        'ingame_time' => 'datetime',
    ];
}
