<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

/**
 * Cada docente maneja sus clases; dirección puede ver y editar todas.
 */
class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->teacher_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Course $course): bool
    {
        return $this->view($user, $course);
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->view($user, $course);
    }
}
