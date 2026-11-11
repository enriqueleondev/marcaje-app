<?php

namespace App\Models;

use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credencial de un alumno: código QR aleatorio o UID de una pegatina NFC.
 * Nunca se borra: al regenerar o quitar se marca como revocada y queda en el historial.
 */
class Credential extends Model
{
    protected $fillable = ['student_id', 'type', 'value'];

    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'revoked_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** Valor para mostrar: QR como "K7Q2-9XMA"; NFC tal como lo lee el lector. */
    protected function display(): Attribute
    {
        return Attribute::get(fn () => $this->type === CredentialType::Qr
            ? substr($this->value, 0, 4).'-'.substr($this->value, 4)
            : $this->value);
    }

    /**
     * Normaliza lo que teclea un lector o una persona: mayúsculas y solo letras y números.
     * "k7q2-9xma" → "K7Q29XMA"; "04:A2:3F:1B:5C:80:00" → "04A23F1B5C8000".
     */
    public static function normalize(string $raw): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($raw));
    }
}
