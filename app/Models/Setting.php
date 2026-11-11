<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Configuración propia de cada instalación (una por cliente).
 * Nada específico de una escuela va en el código: va aquí.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** Valores por defecto cuando la instalación aún no los define. */
    public const DEFAULTS = [
        'institution_name' => null,
        'timezone' => 'America/Mexico_City',
        'grades' => 3,
        'groups_per_grade' => 4,
        'duplicate_window_minutes' => 5,
    ];

    private const CACHE_KEY = 'settings.all';

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());

        return $all[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /** Nombre de la institución, o null si la instalación aún no lo define. */
    public static function institutionName(): ?string
    {
        return static::get('institution_name');
    }
}
