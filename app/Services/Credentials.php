<?php

namespace App\Services;

use App\Enums\CredentialType;
use App\Models\AuditLog;
use App\Models\Credential;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Altas, regeneración, revocación y búsqueda de credenciales.
 *
 * QR: 8 caracteres aleatorios de un alfabeto de 32 sin ambiguos (sin 0, O, 1, I),
 * unos 10^12 valores posibles. Se guarda sin guion y el QR codifica solo letras y
 * números, para que cualquier lector USB lo teclee igual sin importar el idioma del teclado.
 *
 * NFC: se guarda el UID tal como lo teclea el lector (normalizado). Por eso conviene
 * vincular las pegatinas con el mismo modelo y configuración de lector que usan los kioscos.
 */
class Credentials
{
    public const QR_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const QR_LENGTH = 8;

    /** Crea un QR si el alumno no tiene uno activo. */
    public function ensureQr(Student $student): Credential
    {
        return $student->activeQr()->first() ?? $this->issueQr($student);
    }

    /** Revoca el QR actual (si hay) y crea uno nuevo. El anterior deja de funcionar. */
    public function regenerateQr(Student $student, ?User $by = null): Credential
    {
        return DB::transaction(function () use ($student, $by) {
            $old = $student->activeQr()->lockForUpdate()->first();
            if ($old) {
                $this->markRevoked($old, $by);
            }
            $new = $this->issueQr($student);
            AuditLog::record('credential.qr_regenerated', $student, ['old' => $old?->value, 'new' => $new->value], $by);

            return $new;
        });
    }

    /**
     * Vincula una pegatina NFC al alumno, reemplazando la que tuviera.
     *
     * @throws ValidationException si el UID no es válido o ya pertenece a otro alumno
     */
    public function linkNfc(Student $student, string $raw, ?User $by = null, string $field = 'nfc'): Credential
    {
        $uid = Credential::normalize($raw);

        if (strlen($uid) < 4 || strlen($uid) > 32) {
            throw ValidationException::withMessages([$field => 'No se leyó un UID válido. Pasa la pegatina otra vez por el lector.']);
        }

        $current = $student->activeNfc()->first();
        if ($current?->value === $uid) {
            return $current;
        }

        $taken = Credential::active()->where('value', $uid)->with('student')->first();
        if ($taken) {
            throw ValidationException::withMessages([$field => $taken->type === CredentialType::Nfc
                ? "Esa pegatina ya está vinculada a {$taken->student?->full_name}. Quítala de ese alumno primero."
                : 'Ese valor coincide con un código QR. Pasa una pegatina NFC, no un QR.']);
        }

        try {
            return DB::transaction(function () use ($student, $uid, $current, $by) {
                if ($current) {
                    $this->markRevoked($current, $by);
                }
                $credential = $student->credentials()->create(['type' => CredentialType::Nfc, 'value' => $uid]);
                AuditLog::record($current ? 'credential.nfc_replaced' : 'credential.nfc_linked', $student, ['uid' => $uid, 'old' => $current?->value], $by);

                return $credential;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => 'Esa pegatina se acaba de vincular a otro alumno. Revisa y vuelve a intentar.']);
        }
    }

    /** Revoca la credencial activa de un tipo. Devuelve false si no había. */
    public function revoke(Student $student, CredentialType $type, ?User $by = null): bool
    {
        $credential = $student->credentials()->active()->where('type', $type)->first();
        if (! $credential) {
            return false;
        }

        $this->markRevoked($credential, $by);
        AuditLog::record("credential.{$type->value}_revoked", $student, ['value' => $credential->value], $by);

        return true;
    }

    /** Revoca todas las credenciales activas (al dar de baja a un alumno). */
    public function revokeAll(Student $student, ?User $by = null): void
    {
        foreach ($student->credentials()->active()->get() as $credential) {
            $this->markRevoked($credential, $by);
        }
    }

    /**
     * Busca al alumno de una lectura de kiosco (QR o NFC).
     * Ignora credenciales revocadas y alumnos dados de baja.
     */
    public function findActiveByScan(string $raw): ?Credential
    {
        $value = Credential::normalize($raw);
        if ($value === '') {
            return null;
        }

        return Credential::active()
            ->where('value', $value)
            ->whereHas('student')
            ->with('student.group')
            ->first();
    }

    public static function randomCode(): string
    {
        $max = strlen(self::QR_ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::QR_LENGTH; $i++) {
            $code .= self::QR_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private function issueQr(Student $student): Credential
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = self::randomCode();
            if (Credential::active()->where('value', $code)->exists()) {
                continue;
            }
            try {
                return $student->credentials()->create(['type' => CredentialType::Qr, 'value' => $code]);
            } catch (UniqueConstraintViolationException) {
                continue; // carrera con otro alta: probar otro código
            }
        }

        throw new RuntimeException('No se pudo generar un código QR único.');
    }

    private function markRevoked(Credential $credential, ?User $by): void
    {
        $credential->forceFill([
            'revoked_at' => now(),
            'revoked_by' => ($by ?? Auth::user())?->getKey(),
        ])->save();
    }
}
