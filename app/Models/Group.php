<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo escolar: grado + número (ej. 2° grado, grupo 3).
 */
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    protected $fillable = ['grade', 'number', 'label'];

    protected function casts(): array
    {
        return ['grade' => 'integer', 'number' => 'integer'];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /** "2° grado, grupo 3" */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => "{$this->grade}° grado, grupo {$this->number}");
    }

    /** "2° 3" */
    protected function shortName(): Attribute
    {
        return Attribute::get(fn () => "{$this->grade}° {$this->number}");
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('grade')->orderBy('number');
    }

    /** Grados configurados para esta instalación (1..N). */
    public static function grades(): array
    {
        return range(1, max(1, (int) Setting::get('grades')));
    }

    /** Números de grupo configurados por grado (1..N). */
    public static function numbers(): array
    {
        return range(1, max(1, (int) Setting::get('groups_per_grade')));
    }
}
