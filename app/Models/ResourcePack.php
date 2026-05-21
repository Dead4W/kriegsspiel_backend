<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourcePack extends Model
{
    protected $fillable = [
        'user_id',
        'public_id',
        'name',
        'is_public',
        'is_default',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
        'is_public' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
