<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|UserToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserToken query()
 * @method static \Illuminate\Database\Eloquent\Builder|UserToken whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserToken whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserToken whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserToken whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserToken whereUserId($value)
 * @property-read \App\Models\User|null $user
 * @mixin \Eloquent
 */
class UserToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
