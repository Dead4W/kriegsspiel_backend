<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;


/**
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $last_online_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\RoomUser $pivot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Room> $rooms
 * @property-read int|null $rooms_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Illuminate\Database\Eloquent\Builder|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User query()
 * @method static \Illuminate\Database\Eloquent\Builder|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereRoomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereTeam($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereUpdatedAt($value)
 * @property string|null $google_id
 * @property string|null $email
 * @property string|null $avatar
 * @method static \Illuminate\Database\Eloquent\Builder|User whereAvatar($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereGoogleId($value)
 * @mixin \Eloquent
 */
class User extends Model implements \Illuminate\Contracts\Auth\Authenticatable {
    protected $fillable = [
        'name',
        'google_id',
        'email',
        'avatar',
    ];

    protected $casts = [
        'last_online_at' => 'datetime',
    ];

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class)
            ->withPivot('team', 'is_ready')
            ->withTimestamps()
            ->using(RoomUser::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(UserToken::class, 'user_id', 'id');
    }

    public function getAuthIdentifierName()
    {
        // TODO: Implement getAuthIdentifierName() method.
    }

    public function getAuthIdentifier()
    {
        // TODO: Implement getAuthIdentifier() method.
    }

    public function getAuthPassword()
    {
        // TODO: Implement getAuthPassword() method.
    }

    public function getRememberToken()
    {
        // TODO: Implement getRememberToken() method.
    }

    public function setRememberToken($value)
    {
        // TODO: Implement setRememberToken() method.
    }

    public function getRememberTokenName()
    {
        // TODO: Implement getRememberTokenName() method.
    }
}
