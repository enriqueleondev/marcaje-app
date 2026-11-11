<?php

namespace App\Models;

use App\Enums\RollCallSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Clase de un docente (ej. "Biología I" con 1° 3). Los alumnos se los asigna el docente.
 */
class Course extends Model
{
    protected $fillable = ['teacher_id', 'name', 'group_id', 'room', 'roll_call_sort', 'is_active'];

    protected $attributes = ['is_active' => true, 'roll_call_sort' => 'seat'];

    protected function casts(): array
    {
        return ['roll_call_sort' => RollCallSort::class, 'is_active' => 'boolean'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id')->withTrashed();
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(CourseSchedule::class)->orderBy('weekday')->orderBy('starts_at');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)->withPivot('position')->withTimestamps();
    }

    public function rollCalls(): HasMany
    {
        return $this->hasMany(RollCall::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->when(! $user->isAdmin(), fn ($q) => $q->where('teacher_id', $user->id));
    }

    /** "Biología I, 1° 3" */
    public function title(): string
    {
        return $this->group ? "{$this->name}, {$this->group->short_name}" : $this->name;
    }
}
