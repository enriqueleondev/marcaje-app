<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Entry;
use App\Models\Kiosk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Alta, vinculación y baja de kioscos. Solo dirección.
 */
class KioskController extends Controller
{
    public function index(): View
    {
        return view('kiosks.index', [
            'kiosks' => Kiosk::withCount(['entries as today_count' => fn ($q) => $q->onLocalDate()])->orderBy('name')->get(),
            'recent' => Entry::with(['student.group', 'kiosk'])->orderByDesc('scanned_at')->limit(15)->get(),
            'todayCount' => Entry::onLocalDate()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $kiosk = new Kiosk($data);
        $token = $kiosk->issueToken();
        AuditLog::record('kiosk.created', $kiosk);

        return redirect()->route('kiosks.show', $kiosk)->with('kiosk_token', $token);
    }

    public function show(Kiosk $kiosk): View
    {
        $token = session('kiosk_token');

        return view('kiosks.show', [
            'kiosk' => $kiosk,
            'token' => $token,
            'pairingUrl' => $token ? Kiosk::pairingUrl($token) : null,
        ]);
    }

    public function update(Request $request, Kiosk $kiosk): RedirectResponse
    {
        $kiosk->update($this->validated($request));

        return back()->with('status', 'Kiosco actualizado.');
    }

    /** Token nuevo: el anterior deja de funcionar y hay que volver a vincular la tableta. */
    public function regenerate(Kiosk $kiosk): RedirectResponse
    {
        $token = $kiosk->issueToken();
        AuditLog::record('kiosk.token_regenerated', $kiosk);

        return redirect()->route('kiosks.show', $kiosk)->with('kiosk_token', $token);
    }

    public function revoke(Kiosk $kiosk): RedirectResponse
    {
        $kiosk->forceFill(['revoked_at' => now()])->save();
        AuditLog::record('kiosk.revoked', $kiosk);

        return redirect()->route('kiosks.show', $kiosk)->with('status', "{$kiosk->name} quedó desvinculado. Ya no puede registrar entradas.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:120'],
        ], [
            'name.required' => 'Ponle un nombre al kiosco (ej. "Kiosco 1").',
            'name.max' => 'El nombre admite hasta 80 caracteres.',
            'location.max' => 'La ubicación admite hasta 120 caracteres.',
        ]);
    }
}
