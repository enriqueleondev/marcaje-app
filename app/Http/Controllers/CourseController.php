<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('courses.index', [
            'courses' => Course::visibleTo($user)
                ->with(['group', 'schedules', 'teacher'])
                ->withCount('students')
                ->orderByDesc('is_active')->orderBy('name')
                ->get(),
            'groups' => Group::ordered()->get(),
            'teachers' => $user->isAdmin() ? User::where('role', 'teacher')->active()->orderByName()->get() : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'room' => ['nullable', 'string', 'max:60'],
            'teacher_id' => [Rule::requiredIf($user->isAdmin()), 'nullable', 'integer', Rule::exists('users', 'id')->where('role', 'teacher')],
        ], [
            'name.required' => 'Escribe el nombre de la clase (ej. "Biología I").',
            'teacher_id.required' => 'Elige al docente de la clase.',
        ]);

        $course = Course::create([
            ...$data,
            'teacher_id' => $user->isAdmin() ? $data['teacher_id'] : $user->id,
        ]);

        // Si la clase es de un grupo, se agregan sus alumnos de una vez; el docente puede ajustar después.
        if ($course->group_id) {
            $course->students()->attach($course->group->students()->pluck('id'));
        }

        AuditLog::record('course.created', $course);

        return redirect()->route('courses.show', $course)->with('status', 'Clase creada. Agrega su horario para poder pasar lista.');
    }

    public function show(Course $course): View
    {
        $this->authorize('view', $course);

        return view('courses.show', ['course' => $course]);
    }
}
