<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ajustes de la instalación (una por cliente). Solo dirección.
 */
class SettingsController extends Controller
{
    /** Zonas horarias de México que se ofrecen; cualquier otra válida se acepta igual. */
    public const TIMEZONES = [
        'America/Mexico_City' => 'Centro (Ciudad de México)',
        'America/Monterrey' => 'Centro (Monterrey)',
        'America/Merida' => 'Centro (Mérida)',
        'America/Cancun' => 'Sureste (Cancún)',
        'America/Chihuahua' => 'Pacífico (Chihuahua)',
        'America/Mazatlan' => 'Pacífico (Mazatlán)',
        'America/Hermosillo' => 'Sonora (Hermosillo)',
        'America/Tijuana' => 'Noroeste (Tijuana)',
    ];

    public function edit(): View
    {
        return view('settings.edit', [
            'values' => [
                'institution_name' => Setting::institutionName(),
                'timezone' => Setting::get('timezone'),
                'duplicate_window_minutes' => (int) Setting::get('duplicate_window_minutes'),
                'grades' => (int) Setting::get('grades'),
                'groups_per_grade' => (int) Setting::get('groups_per_grade'),
            ],
            'timezones' => self::TIMEZONES,
            'groupsCount' => Group::count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'institution_name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'duplicate_window_minutes' => ['required', 'integer', 'between:1,60'],
            'grades' => ['required', 'integer', 'between:1,12'],
            'groups_per_grade' => ['required', 'integer', 'between:1,26'],
        ], [
            'institution_name.required' => 'Escribe el nombre de la institución.',
            'timezone.in' => 'Elige una zona horaria válida.',
            'duplicate_window_minutes.between' => 'Usa entre 1 y 60 minutos.',
            'grades.between' => 'Usa entre 1 y 12 grados.',
            'groups_per_grade.between' => 'Usa entre 1 y 26 grupos por grado.',
        ]);

        $changed = [];
        foreach ($data as $key => $value) {
            $value = is_numeric($value) && $key !== 'institution_name' ? (int) $value : trim($value);
            if (Setting::get($key) !== $value) {
                Setting::put($key, $value);
                $changed[] = $key;
            }
        }
        if ($changed) {
            AuditLog::record('settings.updated', meta: ['changed' => $changed]);
        }

        return back()->with('status', $changed ? 'Ajustes guardados.' : 'No había cambios.');
    }
}
