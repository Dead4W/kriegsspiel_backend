<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $stage
 * @property string $uuid
 * @property string $password
 * @property string $admin_key
 * * @property string $red_key
 * * @property string $blue_key
 * @property array $options
 * @property string $name
 * @property \Illuminate\Support\Carbon $ingame_time
 * @property int $admin_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\RoomUser $pivot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder|Room newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Room newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Room query()
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereIngameTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereStage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereAdminKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereBlueKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereRedKey($value)
 * @property string $red_key
 * @property string $blue_key
 * @property string $weather
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereWeather($value)
 * @property string $map_url
 * @property string $height_map_url
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereHeightMapUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Room whereMapUrl($value)
 * @mixin \Eloquent
 */
class Room extends Model
{
    protected $casts = [
        'options' => 'array',
        'ingame_time' => 'datetime',
    ];

    protected $hidden = [
        'admin_key',
        'blue_key',
        'red_key',
        'password',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('team')
            ->withTimestamps()
            ->using(RoomUser::class);
    }
}
