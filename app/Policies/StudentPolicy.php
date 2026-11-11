<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

/**
 * Dirección: todo. Docentes: ven la lista completa (la necesitan para armar sus
 * clases), pero solo cambian el grupo o registran entradas manuales de alumnos
 * inscritos en sus clases.
 */
class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // solo personal tiene cuenta
    }

    public function view(User $user, Student $student): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Student $student): bool
    {
        return $user->isAdmin();
    }

    public function changeGroup(User $user, Student $student): bool
    {
        return $user->isAdmin() || $user->teachesStudent($student->id);
    }

    public function registerManualEntry(User $user, Student $student): bool
    {
        return $user->isAdmin() || $user->teachesStudent($student->id);
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Student $student): bool
    {
        return $user->isAdmin();
    }

    public function import(User $user): bool
    {
        return $user->isAdmin();
    }
}
