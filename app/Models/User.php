<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\LoginThrottle;
use App\Support\TemporaryPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * Personal que usa el sistema: dirección (admin) y docentes.
 */
#[Fillable(['role', 'first_name', 'last_name_paternal', 'last_name_maternal', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /** Horas de vigencia de una contraseña temporal. */
    public const TEMPORARY_PASSWORD_HOURS = 24;

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isTeacher(): bool
    {
        return $this->role === Role::Teacher;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Nombre completo: nombre(s) y apellidos. */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name_paternal} {$this->last_name_maternal}"));
    }

    protected function initials(): Attribute
    {
        return Attribute::get(fn () => mb_strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name_paternal, 0, 1)));
    }

    public function temporaryPasswordExpired(): bool
    {
        return $this->must_change_password
            && $this->password_expires_at !== null
            && $this->password_expires_at->isPast();
    }

    /**
     * Asigna una contraseña temporal que vence en 24 h y obliga a cambiarla al entrar.
     * Devuelve la contraseña en claro para mostrarla una sola vez.
     */
    public function issueTemporaryPassword(?User $issuedBy = null): string
    {
        $plain = TemporaryPassword::generate();

        $this->forceFill([
            'password' => $plain,
            'must_change_password' => true,
            'password_expires_at' => now()->addHours(self::TEMPORARY_PASSWORD_HOURS),
            'remember_token' => null,
        ])->save();

        AuditLog::record('password.temporary_issued', $this, by: $issuedBy);

        return $plain;
    }

    /** Cambia la contraseña definitiva y quita la obligación de cambiarla. */
    public function changePassword(string $plain): void
    {
        $this->forceFill([
            'password' => $plain,
            'must_change_password' => false,
            'password_expires_at' => null,
        ])->save();

        AuditLog::record('password.changed', $this, by: $this);
    }

    /**
     * Estado del acceso para mostrar en listas: [clase del marcador, texto].
     *
     * @return array{0: string, 1: string}
     */
    public function accessStatus(): array
    {
        return match (true) {
            $this->trashed() => ['falta', 'Dada de baja'],
            ! $this->is_active => ['falta', 'Suspendida'],
            LoginThrottle::accountLockedFor($this->email) > 0 => ['retardo', 'Bloqueada por intentos fallidos'],
            $this->temporaryPasswordExpired() => ['retardo', 'Contraseña temporal vencida'],
            $this->must_change_password => ['confirmar', 'Contraseña temporal, vence en '.max(1, (int) ceil(now()->diffInMinutes($this->password_expires_at) / 60)).' h'],
            default => ['presente', 'Activa'],
        };
    }

    /** Cierra todas sus sesiones abiertas (incluido "mantener la sesión"). Devuelve cuántas había. */
    public function revokeSessions(): int
    {
        $this->forceFill(['remember_token' => null])->saveQuietly();

        return DB::table('sessions')->where('user_id', $this->id)->delete();
    }

    public function activeSessions(): int
    {
        return DB::table('sessions')->where('user_id', $this->id)->count();
    }

    /** ¿El alumno está inscrito en alguna clase de este docente? */
    public function teachesStudent(int $studentId): bool
    {
        return DB::table('course_student')
            ->join('courses', 'courses.id', '=', 'course_student.course_id')
            ->where('courses.teacher_id', $this->id)
            ->where('course_student.student_id', $studentId)
            ->exists();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrderByName(Builder $query): void
    {
        $query->orderBy('last_name_paternal')->orderBy('last_name_maternal')->orderBy('first_name');
    }
}
