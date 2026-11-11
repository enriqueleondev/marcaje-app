<?php

namespace App\Models;

use App\Enums\ManualReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada a la escuela: una lectura válida de credencial en un kiosco (o manual).
 */
class Entry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime', 'received_at' => 'datetime', 'manual_reason' => ManualReason::class];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    /** Quién registró una entrada manual. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /** Entradas del día local de la escuela. */
    public function scopeOnLocalDate(Builder $query, ?\DateTimeInterface $day = null): void
    {
        $start = CarbonImmutable::instance($day ?? now())->setTimezone(Setting::get('timezone'))->startOfDay();
        $query->whereBetween('scanned_at', [$start->utc(), $start->endOfDay()->utc()]);
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            'nfc' => 'NFC',
            'qr' => 'QR',
            default => 'Manual',
        };
    }
}
