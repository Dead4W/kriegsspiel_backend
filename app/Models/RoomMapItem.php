<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $room_map_id
 * @property string $type
 * @property string $item_id
 * @property array $data
 * @property bool $shared
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem whereItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem whereRoomMapId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMapItem whereShared($value)
 * @mixin \Eloquent
 */
class RoomMapItem extends Model
{
    protected $fillable = [
        'room_map_id',
        'type',
        'item_id',
        'data',
        'shared',
    ];

    protected $casts = [
        'data' => 'array',
        'shared' => 'boolean',
    ];
}
