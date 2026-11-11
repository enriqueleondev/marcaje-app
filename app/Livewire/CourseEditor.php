<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseSchedule;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Datos, horario y alumnos de una clase. El docente se asigna los alumnos.
 */
class CourseEditor extends Component
{
    #[Locked]
    public int $courseId;

    public string $name = '';

    public ?int $groupId = null;

    public string $room = '';

    public bool $isActive = true;

    public int $weekday = 1;

    public string $startsAt = '07:00';

    public string $endsAt = '07:50';

    public string $search = '';

    public ?int $grade = null;

    public ?int $groupNumber = null;

    /** @var list<int> */
    public array $toAdd = [];

    public ?string $notice = null;

    public function mount(Course $course): void
    {
        $this->authorize('view', $course);
        $this->courseId = $course->id;
        $this->name = $course->name;
        $this->groupId = $course->group_id;
        $this->room = (string) $course->room;
        $this->isActive = $course->is_active;
        if ($course->group) {
            $this->grade = $course->group->grade;
            $this->groupNumber = $course->group->number;
        }
    }

    #[Computed]
    public function course(): Course
    {
        return Course::with(['group', 'schedules', 'teacher'])->findOrFail($this->courseId);
    }

    public function saveDetails(): void
    {
        $this->authorize('update', $this->course);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'groupId' => ['nullable', 'integer', 'exists:groups,id'],
            'room' => ['nullable', 'string', 'max:60'],
            'isActive' => ['boolean'],
        ], ['name.required' => 'Escribe el nombre de la clase.']);

        $this->course->update([
            'name' => $data['name'],
            'group_id' => $data['groupId'],
            'room' => $data['room'] ?: null,
            'is_active' => $data['isActive'],
        ]);
        $this->notice = 'Datos guardados.';
        unset($this->course);
    }

    public function addSchedule(): void
    {
        $this->authorize('update', $this->course);
        $this->validate([
            'weekday' => ['required', 'integer', 'between:1,7'],
            'startsAt' => ['required', 'date_format:H:i'],
            'endsAt' => ['required', 'date_format:H:i', 'after:startsAt'],
        ], [
            'endsAt.after' => 'La hora de fin debe ser después de la de inicio.',
            '*.date_format' => 'Usa el formato 24 h, ej. 07:50.',
        ]);

        $overlap = $this->course->schedules()->where('weekday', $this->weekday)
            ->where('starts_at', '<', $this->endsAt.':00')->where('ends_at', '>', $this->startsAt.':00')->exists();
        if ($overlap) {
            $this->addError('startsAt', 'Ese horario se cruza con otro de esta misma clase.');

            return;
        }

        $this->course->schedules()->create([
            'weekday' => $this->weekday,
            'starts_at' => $this->startsAt.':00',
            'ends_at' => $this->endsAt.':00',
        ]);
        $this->notice = 'Horario agregado.';
        unset($this->course);
    }

    public function removeSchedule(int $id): void
    {
        $this->authorize('update', $this->course);
        $this->course->schedules()->whereKey($id)->delete();
        $this->notice = 'Horario quitado. Los pases de lista anteriores se conservan.';
        unset($this->course);
    }

    public function setGrade(?int $grade): void
    {
        $this->grade = $this->grade === $grade ? null : $grade;
        $this->toAdd = [];
    }

    public function setGroupNumber(?int $number): void
    {
        $this->groupNumber = $this->groupNumber === $number ? null : $number;
        $this->toAdd = [];
    }

    public function addSelected(): void
    {
        $this->authorize('update', $this->course);
        $ids = Student::whereIn('id', $this->toAdd)->pluck('id');
        $this->course->students()->syncWithoutDetaching($ids);
        AuditLog::record('course.students_added', $this->course, ['count' => $ids->count()]);
        $this->notice = $ids->count() === 1 ? 'Se agregó 1 alumno.' : "Se agregaron {$ids->count()} alumnos.";
        $this->toAdd = [];
    }

    public function addAllShown(): void
    {
        $this->toAdd = $this->candidates->pluck('id')->all();
        $this->addSelected();
    }

    public function removeStudent(int $id): void
    {
        $this->authorize('update', $this->course);
        $this->course->students()->detach($id);
        AuditLog::record('course.student_removed', $this->course, ['student_id' => $id]);
        $this->notice = 'Alumno quitado de la clase. Sus pases de lista anteriores se conservan.';
    }

    #[Computed]
    public function members()
    {
        return $this->course->students()->with('group')->orderByName()->get();
    }

    #[Computed]
    public function candidates()
    {
        if ($this->search === '' && ! $this->grade && ! $this->groupNumber) {
            return collect();
        }

        return Student::query()
            ->whereDoesntHave('courses', fn ($q) => $q->whereKey($this->courseId))
            ->with('group')
            ->search($this->search)
            ->inGradeGroup($this->grade, $this->groupNumber)
            ->orderByName()
            ->limit(80)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.course-editor', [
            'groups' => Group::ordered()->get(),
            'grades' => Group::grades(),
            'numbers' => Group::numbers(),
            'days' => CourseSchedule::DAYS,
        ]);
    }
}
