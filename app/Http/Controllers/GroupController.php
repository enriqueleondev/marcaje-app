<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupController extends Controller
{
    public function index(): View
    {
        $groups = Group::ordered()->withCount('students')->get();

        $missing = collect(Group::grades())
            ->crossJoin(Group::numbers())
            ->reject(fn ($p) => $groups->contains(fn ($g) => $g->grade === $p[0] && $g->number === $p[1]))
            ->count();

        return view('groups.index', [
            'byGrade' => $groups->groupBy('grade'),
            'missing' => $missing,
            'grades' => Group::grades(),
            'numbers' => Group::numbers(),
        ]);
    }

    /** Crea los grupos que falten según la configuración (grados × grupos por grado). */
    public function storeDefaults(): RedirectResponse
    {
        $created = 0;
        foreach (Group::grades() as $grade) {
            foreach (Group::numbers() as $number) {
                $created += Group::firstOrCreate(['grade' => $grade, 'number' => $number])->wasRecentlyCreated ? 1 : 0;
            }
        }

        AuditLog::record('groups.created_defaults', meta: ['created' => $created]);

        return back()->with('status', $created === 1 ? 'Se creó 1 grupo.' : "Se crearon {$created} grupos.");
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:60']], ['label.max' => 'La etiqueta admite hasta 60 caracteres.']);

        $group->update(['label' => $data['label'] ?: null]);

        return back()->with('status', "Grupo {$group->short_name} actualizado.");
    }

    public function destroy(Group $group): RedirectResponse
    {
        if ($group->students()->exists()) {
            return back()->withErrors(['group' => "El grupo {$group->short_name} tiene alumnos. Muévelos a otro grupo antes de eliminarlo."]);
        }

        $group->delete();
        AuditLog::record('group.deleted', meta: ['grade' => $group->grade, 'number' => $group->number]);

        return back()->with('status', "Grupo {$group->short_name} eliminado.");
    }
}
