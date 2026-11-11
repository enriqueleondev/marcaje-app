<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Tableta de entrada. Se autentica con un token propio (solo se guarda su hash).
 */
class Kiosk extends Model
{
    protected $fillable = ['name', 'location'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** Crea un token nuevo (invalida el anterior) y devuelve el token en claro, una sola vez. */
    public function issueToken(): string
    {
        $token = 'mk_'.Str::random(40);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'revoked_at' => null])->save();

        return $token;
    }

    public static function findByToken(?string $token): ?self
    {
        if (! $token || ! str_starts_with($token, 'mk_')) {
            return null;
        }

        return static::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }

    /** URL de vinculación: el token va en el fragmento (#), que nunca llega al servidor ni a sus logs. */
    public static function pairingUrl(string $token): string
    {
        return route('kiosk.show').'#vincular='.$token;
    }

    /** [clase de marcador, texto] para listas. */
    public function status(): array
    {
        return match (true) {
            ! $this->isActive() => ['falta', 'Desvinculado'],
            $this->last_seen_at === null => ['confirmar', 'Esperando vinculación'],
            $this->last_seen_at->gt(now()->subMinutes(3)) => ['presente', 'En línea'],
            default => ['retardo', 'Sin conexión desde '.$this->last_seen_at->timezone(Setting::get('timezone'))->format('d/m H:i')],
        };
    }
}
