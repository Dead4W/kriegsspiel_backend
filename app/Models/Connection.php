<?php

namespace App\Models;

use App\Enums\TeamEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $room_id
 * @property int|null $user_id
 * @property int|null $room_map_user_id
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
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereRoomMapUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereTeam($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Connection whereUpdatedAt($value)
 * @property-read string|null $meta_from
 * @property-read string $name
 * @property-read \App\Models\User|null $user
 * @mixin \Eloquent
 */
class Connection extends Model
{
    protected $fillable = [
        'room_id',
        'user_id',
        'room_map_user_id',
        'team',
        'last_message_at',
    ];

    protected $casts = [
        'team' => TeamEnum::class,
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function getMetaFromAttribute(): ?string
    {
        return $this->user?->name;
    }

    public function getNameAttribute(): string
    {
        return $this->user?->name ?? "fd#{$this->id}";
    }
}
