<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un bloque semanal de una clase: día y hora (hora local de la escuela).
 */
class CourseSchedule extends Model
{
    protected $fillable = ['weekday', 'starts_at', 'ends_at'];

    public const DAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function dayLabel(): string
    {
        return self::DAYS[$this->weekday];
    }

    /** "07:50 a 08:40" */
    public function timeRange(): string
    {
        return substr($this->starts_at, 0, 5).' a '.substr($this->ends_at, 0, 5);
    }

    /**
     * Inicio y fin de este bloque en una fecha local, en UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowOn(string $date): array
    {
        $tz = Setting::get('timezone');

        return [
            CarbonImmutable::parse("{$date} {$this->starts_at}", $tz)->utc(),
            CarbonImmutable::parse("{$date} {$this->ends_at}", $tz)->utc(),
        ];
    }
}
