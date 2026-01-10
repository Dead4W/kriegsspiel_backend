<?php

namespace App\Models;

use App\Enums\TeamEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $room_id
 * @property TeamEnum $team
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|Connection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Connection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Connection query()
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereLastMessageAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereRoomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereTeam($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Connection extends Model
{
    protected $fillable = [
        'room_id',
        'team',
        'last_message_at',
    ];

    protected $casts = [
        'team' => TeamEnum::class,
        'last_message_at' => 'datetime',
    ];
}
