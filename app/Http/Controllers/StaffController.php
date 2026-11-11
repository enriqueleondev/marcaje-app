<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StaffRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Cuentas del personal (docentes y dirección). Solo dirección.
 * Al dar de alta una cuenta se genera una contraseña temporal de 24 h.
 */
class StaffController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $show = $request->query('ver') === 'bajas' ? 'bajas' : 'activas';

        $users = User::query()
            ->when($show === 'bajas', fn ($q) => $q->onlyTrashed())
            ->orderByName()
            ->get()
            ->groupBy(fn (User $u) => $u->role->value);

        return view('teachers.index', [
            'show' => $show,
            'teachers' => $users->get(Role::Teacher->value, collect()),
            'admins' => $users->get(Role::Admin->value, collect()),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('teachers.form', ['user' => new User(['role' => Role::Teacher])]);
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        $user = User::create([...$request->validated(), 'password' => Str::random(40), 'is_active' => true]);
        AuditLog::record('user.created', $user, ['role' => $user->role->value]);
        $temporary = $user->issueTemporaryPassword($request->user());

        return redirect()->route('teachers.edit', $user)
            ->with('temporary_password', $temporary)
            ->with('status', "Cuenta creada para {$user->full_name}.");
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('teachers.form', ['user' => $user]);
    }

    public function update(StaffRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if (Role::from($data['role']) !== $user->role) {
            Gate::authorize('changeRole', $user);
        }

        $user->fill($data);
        $changed = array_keys($user->getDirty());
        $user->save();

        if ($changed) {
            AuditLog::record('user.updated', $user, ['changed' => $changed]);
        }

        return redirect()->route('teachers.edit', $user)->with('status', 'Datos guardados.');
    }

    public function temporaryPassword(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('issueTemporaryPassword', $user);

        $temporary = $user->issueTemporaryPassword($request->user());

        return redirect()->route('teachers.edit', $user)->with('temporary_password', $temporary);
    }

    public function toggleActive(User $user): RedirectResponse
    {
        if ($user->is_active) {
            Gate::authorize('deactivate', $user);
        } else {
            $this->authorize('update', $user);
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        AuditLog::record($user->is_active ? 'user.activated' : 'user.deactivated', $user);

        return redirect()->route('teachers.edit', $user)->with('status', $user->is_active
            ? "La cuenta de {$user->full_name} está activa de nuevo."
            : "La cuenta de {$user->full_name} quedó desactivada. Ya no puede entrar.");
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();
        AuditLog::record('user.deleted', $user);

        return redirect()->route('teachers.index')->with('status', "{$user->full_name} fue dado de baja.");
    }

    public function restore(int $id): RedirectResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $this->authorize('update', $user);

        $user->restore();
        AuditLog::record('user.restored', $user);

        return redirect()->route('teachers.edit', $user)->with('status', "{$user->full_name} fue reactivado. Genera una contraseña temporal para que pueda entrar.");
    }
}
