<?php

namespace App\Models;

use App\Enums\CredentialType;
use App\Services\Credentials;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Alumno. No es usuario del sistema: solo lo manejan docentes y dirección.
 * Dar de baja = soft delete, para conservar su historial de asistencia.
 */
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['first_name', 'last_name_paternal', 'last_name_maternal', 'enrollment_number', 'group_id'];

    /**
     * Todo alumno nace con un código QR. Al darlo de baja se revocan sus
     * credenciales (la pegatina NFC queda libre para otro); al reactivarlo
     * recibe un QR nuevo y hay que volver a vincular su NFC.
     */
    protected static function booted(): void
    {
        static::created(fn (Student $s) => app(Credentials::class)->ensureQr($s));
        static::softDeleted(fn (Student $s) => app(Credentials::class)->revokeAll($s));
        static::restored(fn (Student $s) => app(Credentials::class)->ensureQr($s));
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class)->withPivot('position')->withTimestamps();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    protected function initials(): Attribute
    {
        return Attribute::get(fn () => mb_strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name_paternal, 0, 1)));
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    public function activeQr(): HasOne
    {
        return $this->hasOne(Credential::class)->where('type', CredentialType::Qr)->whereNull('revoked_at');
    }

    public function activeNfc(): HasOne
    {
        return $this->hasOne(Credential::class)->where('type', CredentialType::Nfc)->whereNull('revoked_at');
    }

    /** "Ortega Ruiz, Ximena": como aparece en listas. */
    protected function listName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->last_name_paternal} {$this->last_name_maternal}").", {$this->first_name}");
    }

    /** "Ximena Ortega Ruiz" */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name_paternal} {$this->last_name_maternal}"));
    }

    public function scopeOrderByName(Builder $query): void
    {
        $query->orderBy('last_name_paternal')->orderBy('last_name_maternal')->orderBy('first_name');
    }

    /** Busca por nombre, apellidos o matrícula. Cada palabra debe coincidir con algún campo. */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        foreach (preg_split('/\s+/', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';
            $query->where(fn (Builder $q) => $q
                ->where('first_name', 'like', $like)
                ->orWhere('last_name_paternal', 'like', $like)
                ->orWhere('last_name_maternal', 'like', $like)
                ->orWhere('enrollment_number', 'like', $like));
        }
    }

    /** Filtra por grado y/o grupo. $group = 0 significa "sin grupo". */
    public function scopeInGradeGroup(Builder $query, ?int $grade, ?int $group): void
    {
        if ($group === 0) {
            $query->whereNull('group_id');

            return;
        }

        if ($grade || $group) {
            $query->whereHas('group', fn (Builder $q) => $q
                ->when($grade, fn ($q) => $q->where('grade', $grade))
                ->when($group, fn ($q) => $q->where('number', $group)));
        }
    }
}
