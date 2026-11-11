<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pase de lista de una clase en una fecha. Solo se edita dentro del horario.
 */
class RollCall extends Model
{
    protected $fillable = ['course_id', 'course_schedule_id', 'date', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'confirmed_all_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(CourseSchedule::class, 'course_schedule_id');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(AttendanceMark::class);
    }

    public function isOpen(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $at->betweenIncluded($this->starts_at, $this->ends_at);
    }

    public function hasEnded(?CarbonImmutable $at = null): bool
    {
        return ($at ?? CarbonImmutable::now())->gt($this->ends_at);
    }

    public function localTime(string $field): string
    {
        return $this->{$field}->timezone(Setting::get('timezone'))->format('H:i');
    }
}
