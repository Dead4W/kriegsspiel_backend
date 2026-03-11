<?php

namespace App\Models;

use App\Enums\DataTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $room_map_id
 * @property DataTypeEnum $data_type
 * @property string|null $data_raw
 * @property array $units
 * @property array $paint
 * @property array $logs
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
 * @method static \Illuminate\Database\Eloquent\Builder|Snapshot whereLogs($value)
 * @mixin \Eloquent
 */
class Snapshot extends Model
{
    /** @var array|null Cached decoded data when data_type is gz_compressed */
    private ?array $decodedCompressedData = null;

    protected $attributes = [
        'data_type' => 'json',
    ];

    protected $fillable = [
        'room_map_id',
        'data_type',
        'data_raw',
        'units',
        'paint',
        'ingame_time',
        'logs',
    ];

    protected $casts = [
        'data_type' => DataTypeEnum::class,
        'ingame_time' => 'datetime',
    ];

    /**
     * data_raw: base64-encoded when stored (PostgreSQL text columns require valid UTF-8).
     */
    protected function dataRaw(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (?string $value) {
                if ($value === null || $value === '') {
                    return null;
                }
                $decoded = base64_decode($value, true);
                return $decoded !== false ? $decoded : $value;
            },
            set: fn ($value) => $value !== null ? base64_encode($value) : null,
        );
    }

    /**
     * Get units array on the fly (decompresses when data_type is gz_compressed).
     */
    protected function units(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (): array {
                if ($this->data_type === DataTypeEnum::GZ_COMPRESSED && $this->data_raw !== null) {
                    $decoded = $this->decodeCompressedData();
                    return $decoded['units'] ?? [];
                }
                $raw = $this->getRawOriginal('units');
                return $raw !== null ? json_decode($raw, true) ?? [] : [];
            },
            set: fn (array $value) => json_encode($value),
        );
    }

    /**
     * Get paint array on the fly (decompresses when data_type is gz_compressed).
     */
    protected function paint(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (): array {
                if ($this->data_type === DataTypeEnum::GZ_COMPRESSED && $this->data_raw !== null) {
                    $decoded = $this->decodeCompressedData();
                    return $decoded['paint'] ?? [];
                }
                $raw = $this->getRawOriginal('paint');
                return $raw !== null ? json_decode($raw, true) ?? [] : [];
            },
            set: fn (array $value) => json_encode($value),
        );
    }

    /**
     * Get logs array on the fly (decompresses when data_type is gz_compressed).
     */
    protected function logs(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (): array {
                if ($this->data_type === DataTypeEnum::GZ_COMPRESSED && $this->data_raw !== null) {
                    $decoded = $this->decodeCompressedData();
                    return $decoded['logs'] ?? [];
                }
                $raw = $this->getRawOriginal('logs');
                return $raw !== null ? json_decode($raw, true) ?? [] : [];
            },
            set: fn (array $value) => json_encode($value),
        );
    }

    /**
     * Decode gz-compressed data_raw to array (cached per instance).
     * data_raw is base64-decoded by cast; may be false if invalid.
     */
    private function decodeCompressedData(): array
    {
        if ($this->decodedCompressedData !== null) {
            return $this->decodedCompressedData;
        }
        $raw = $this->data_raw;
        if ($raw === null || $raw === false) {
            return $this->decodedCompressedData = ['units' => [], 'paint' => [], 'logs' => []];
        }
        $decompressed = gzuncompress($raw);
        if ($decompressed === false) {
            return $this->decodedCompressedData = ['units' => [], 'paint' => [], 'logs' => []];
        }
        $decoded = json_decode($decompressed, true);
        $this->decodedCompressedData = is_array($decoded) ? $decoded : ['units' => [], 'paint' => [], 'logs' => []];
        return $this->decodedCompressedData;
    }
}
