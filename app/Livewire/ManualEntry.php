<?php

namespace App\Livewire;

use App\Enums\ManualReason;
use App\Models\Entry;
use App\Models\Setting;
use App\Models\Student;
use App\Services\EntryRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Registro manual de entrada (dirección y docentes): para el alumno que no pudo
 * pasar su credencial. Motivo obligatorio; queda registrado quién la hizo.
 */
class ManualEntry extends Component
{
    public string $search = '';

    public ?int $studentId = null;

    public string $reason = '';

    public string $note = '';

    public string $time = '';

    public ?string $notice = null;

    public bool $noticeIsError = false;

    public function mount(): void
    {
        $this->time = now(Setting::get('timezone'))->format('H:i');
    }

    public function pick(int $id): void
    {
        $this->authorize('registerManualEntry', Student::findOrFail($id));
        $this->studentId = $id;
        $this->notice = null;
        $this->resetErrorBag();
    }

    public function clearStudent(): void
    {
        $this->studentId = null;
    }

    public function save(EntryRecorder $recorder): void
    {
        $tz = Setting::get('timezone');
        $this->validate([
            'studentId' => ['required', 'integer', 'exists:students,id'],
            'reason' => ['required', Rule::enum(ManualReason::class)],
            'note' => [Rule::requiredIf($this->reason === ManualReason::Other->value), 'nullable', 'string', 'max:200'],
            'time' => ['required', 'date_format:H:i'],
        ], [
            'studentId.required' => 'Elige al alumno.',
            'reason.required' => 'Elige el motivo.',
            'note.required' => 'Escribe el motivo.',
            'note.max' => 'El motivo admite hasta 200 caracteres.',
            'time.date_format' => 'Usa el formato 24 h, ej. 07:15.',
        ]);

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', now($tz)->toDateString().' '.$this->time, $tz);
        if ($at->gt(CarbonImmutable::now()->addMinute())) {
            $this->addError('time', 'La hora no puede ser posterior a ahora.');

            return;
        }

        $student = Student::findOrFail($this->studentId);
        $this->authorize('registerManualEntry', $student);
        $result = $recorder->recordManual($student, ManualReason::from($this->reason), trim($this->note), $at, auth()->user());

        if ($result['status'] === 'duplicate') {
            $prev = $result['previous'];
            $this->noticeIsError = true;
            $this->notice = "{$student->full_name} ya tiene entrada a las {$prev->scanned_at->timezone($tz)->format('H:i')} ({$prev->methodLabel()}). No se registró otra.";

            return;
        }

        $this->noticeIsError = false;
        $this->notice = "Entrada manual registrada: {$student->full_name}, {$at->format('H:i')}.";
        $this->reset(['studentId', 'search', 'reason', 'note']);
        $this->time = now($tz)->format('H:i');
    }

    #[Computed]
    public function student(): ?Student
    {
        return $this->studentId ? Student::with('group')->find($this->studentId) : null;
    }

    #[Computed]
    public function results()
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        $user = auth()->user();

        return Student::with('group')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('courses', fn ($c) => $c->where('teacher_id', $user->id)))
            ->search($this->search)->orderByName()->limit(12)->get();
    }

    public function render(): View
    {
        $tz = Setting::get('timezone');

        return view('livewire.manual-entry', [
            'reasons' => ManualReason::cases(),
            'today' => Entry::with(['student.group', 'creator'])->where('method', 'manual')->onLocalDate()->orderByDesc('scanned_at')->get(),
            'existing' => $this->student ? Entry::where('student_id', $this->student->id)->onLocalDate()->orderBy('scanned_at')->first() : null,
            'tz' => $tz,
        ]);
    }
}
