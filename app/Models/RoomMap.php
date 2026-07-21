<?php

namespace App\Models;

use App\Enums\TeamEnum;
use \Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $room_id
 * @property TeamEnum $team
 * @property array $units
 * @property array $paint
 * @property array $logs
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
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap whereLogs($value)
 * @property int|null $user_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RoomMapItem> $items
 * @property-read int|null $items_count
 * @method static \Illuminate\Database\Eloquent\Builder|RoomMap whereUserId($value)
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RoomChat> $chats
 * @property-read int|null $chats_count
 * @mixin \Eloquent
 */
class RoomMap extends Model
{
    protected $fillable = [
        'room_id',
        'team',
        'user_id',
        'units',
        'paint',
        'logs',
    ];

    protected $casts = [
        'units' => 'array',
        'paint' => 'array',
        'logs' => 'array',
        'team' => TeamEnum::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RoomMapItem::class, 'room_map_id');
    }

    public function chats(): BelongsToMany
    {
        return $this->belongsToMany(RoomChat::class);
    }

    static public function getRoomMapForConnection(Connection $connection): ?RoomMap {
        $room = Room::query()
            ->where('id', $connection->room_id)
            ->firstOrFail();

        $isPlayerRoomMap = ($room->options['isPlayerRoomMap'] ?? false)
            && in_array($connection->team, [TeamEnum::BLUE, TeamEnum::RED], true);
        $roomMapTeam = $connection->team === TeamEnum::SPECTATOR
            ? TeamEnum::ADMIN
            : $connection->team;

        if ($isPlayerRoomMap && !$connection->room_map_user_id) {
            return null;
        }

        return RoomMap::query()->firstOrCreate([
            'room_id' => $connection->room_id,
            'team' => $roomMapTeam,
            'user_id' => $isPlayerRoomMap ? $connection->room_map_user_id : null,
        ]);
    }
}
