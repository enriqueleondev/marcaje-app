<?php

namespace App\Enums;

/** Cómo lee credenciales cada kiosco. */
enum KioskReadMode: string
{
    case Usb = 'usb';        // lectores USB (NFC y QR) que teclean el código
    case Camera = 'camera';  // cámara integrada o webcam lee el QR
    case Both = 'both';      // cámara activa y lectores USB al mismo tiempo

    public function label(): string
    {
        return match ($this) {
            self::Usb => 'Lector USB',
            self::Camera => 'Cámara',
            self::Both => 'Ambos',
        };
    }

    public function usesCamera(): bool
    {
        return $this !== self::Usb;
    }
}
