<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $room_map_id
 * @property string $type
 * @property string $item_id
 * @property array $data
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Eloquent
 */
class RoomMapItem extends Model
{
    protected $fillable = [
        'room_map_id',
        'type',
        'item_id',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];
}
