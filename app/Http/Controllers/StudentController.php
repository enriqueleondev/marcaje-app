<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentRequest;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function index(): View
    {
        return view('students.index');
    }

    public function create(): View
    {
        $this->authorize('create', Student::class);

        return view('students.form', ['student' => new Student, 'groups' => Group::ordered()->get()]);
    }

    public function store(StudentRequest $request): RedirectResponse
    {
        $student = Student::create($request->validated());
        AuditLog::record('student.created', $student);

        return redirect()->route('students.index', ['alumno' => $student->id])
            ->with('status', "{$student->full_name} fue agregado.");
    }

    public function edit(Student $student): View
    {
        $this->authorize('update', $student);

        return view('students.form', ['student' => $student, 'groups' => Group::ordered()->get()]);
    }

    public function update(StudentRequest $request, Student $student): RedirectResponse
    {
        $student->fill($request->validated());
        $changes = $student->getDirty();
        $student->save();

        if ($changes) {
            AuditLog::record('student.updated', $student, ['changed' => array_keys($changes)]);
        }

        return redirect()->route('students.index', ['alumno' => $student->id])
            ->with('status', 'Datos guardados.');
    }
}
