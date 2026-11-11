<?php

namespace App\Enums;

enum CredentialType: string
{
    case Qr = 'qr';
    case Nfc = 'nfc';

    public function label(): string
    {
        return match ($this) {
            self::Qr => 'Código QR',
            self::Nfc => 'NFC',
        };
    }
}
