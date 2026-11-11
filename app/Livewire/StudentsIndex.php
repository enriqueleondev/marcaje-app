<?php

namespace App\Livewire;

use App\Enums\CredentialType;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Student;
use App\Services\Credentials;
use App\Services\Reports;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lista de alumnos + inspector (design/direccion-3.html, pantalla "Alumnos").
 * Los filtros viven en la URL para poder compartir o recargar la vista.
 */
class StudentsIndex extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    #[Url(as: 'grado', except: null)]
    public ?int $grade = null;

    /** null = todos, 0 = sin grupo, 1..N = número de grupo */
    #[Url(as: 'grupo', except: null)]
    public ?int $group = null;

    #[Url(as: 'bajas', except: false)]
    public bool $trashed = false;

    #[Url(as: 'alumno', except: null)]
    public ?int $selected = null;

    public ?int $groupId = null;

    /** Mensaje de confirmación de la última acción. */
    public ?string $notice = null;

    /** Modo "pasa la pegatina por el lector". */
    public bool $linkingNfc = false;

    public string $nfcInput = '';

    public function mount(): void
    {
        if ($this->trashed && ! Gate::allows('admin')) {
            $this->trashed = false;
        }
        $this->syncGroupField();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'grade', 'group', 'trashed'], true)) {
            $this->resetPage();
        }
    }

    public function setGrade(?int $grade): void
    {
        $this->grade = $this->grade === $grade ? null : $grade;
        if ($this->group === 0) {
            $this->group = null;
        }
        $this->resetPage();
    }

    public function setGroup(?int $group): void
    {
        $this->group = $this->group === $group ? null : $group;
        if ($group === 0) {
            $this->grade = null;
        }
        $this->resetPage();
    }

    public function toggleTrashed(): void
    {
        Gate::authorize('admin');
        $this->trashed = ! $this->trashed;
        $this->selected = null;
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $this->selected = $id;
        $this->notice = null;
        $this->cancelNfc();
        unset($this->student);
        $this->syncGroupField();
        $this->dispatch('student-selected');
    }

    public function saveGroup(): void
    {
        $student = $this->student;
        abort_if($student === null, 404);
        $this->authorize('changeGroup', $student);

        $this->validate(['groupId' => ['nullable', 'integer', 'exists:groups,id']]);

        $from = $student->group?->short_name;
        $student->update(['group_id' => $this->groupId]);
        $student->load('group');

        AuditLog::record('student.group_changed', $student, ['from' => $from, 'to' => $student->group?->short_name]);
        $this->notice = ("{$student->full_name} ahora está en ".($student->group?->name ?? 'ningún grupo').'.');
        unset($this->student);
    }

    public function delete(): void
    {
        $student = $this->student;
        abort_if($student === null, 404);
        $this->authorize('delete', $student);

        $student->delete();
        AuditLog::record('student.deleted', $student);
        $this->notice = ("{$student->full_name} fue dado de baja. Su historial se conserva.");
        $this->selected = null;
        unset($this->student);
    }

    public function restore(): void
    {
        $student = $this->student;
        abort_if($student === null, 404);
        $this->authorize('restore', $student);

        $student->restore();
        AuditLog::record('student.restored', $student);
        $this->notice = ("{$student->full_name} fue reactivado.");
        $this->trashed = false;
        unset($this->student);
    }

    // ── Credenciales (solo dirección) ───────────────────────────

    public function regenerateQr(Credentials $credentials): void
    {
        $student = $this->credentialTarget();
        $credentials->regenerateQr($student, auth()->user());
        $this->notice = 'Código QR nuevo generado. El anterior ya no funciona: imprime la credencial otra vez.';
        unset($this->student);
    }

    public function revokeQr(Credentials $credentials): void
    {
        $student = $this->credentialTarget();
        $credentials->revoke($student, CredentialType::Qr, auth()->user());
        $this->notice = 'Código QR revocado. El alumno ya no puede registrar su entrada con QR.';
        unset($this->student);
    }

    public function issueQr(Credentials $credentials): void
    {
        $student = $this->credentialTarget();
        $credentials->ensureQr($student);
        $this->notice = 'Código QR generado.';
        unset($this->student);
    }

    public function startNfc(): void
    {
        $this->credentialTarget();
        $this->linkingNfc = true;
        $this->nfcInput = '';
        $this->resetErrorBag('nfcInput');
    }

    public function cancelNfc(): void
    {
        $this->linkingNfc = false;
        $this->nfcInput = '';
        $this->resetErrorBag('nfcInput');
    }

    public function linkNfc(Credentials $credentials): void
    {
        $student = $this->credentialTarget();
        $credential = $credentials->linkNfc($student, $this->nfcInput, auth()->user(), 'nfcInput');
        $this->cancelNfc();
        $this->notice = "Pegatina NFC {$credential->value} vinculada a {$student->first_name}.";
        unset($this->student);
    }

    public function revokeNfc(Credentials $credentials): void
    {
        $student = $this->credentialTarget();
        $credentials->revoke($student, CredentialType::Nfc, auth()->user());
        $this->notice = 'Pegatina NFC desvinculada. Ya puede usarse para otro alumno.';
        unset($this->student);
    }

    private function credentialTarget(): Student
    {
        $student = $this->student;
        abort_if($student === null || $student->trashed(), 404);
        $this->authorize('update', $student);

        return $student;
    }

    #[Computed]
    public function student(): ?Student
    {
        if ($this->selected === null) {
            return null;
        }

        return Student::query()
            ->when($this->trashed, fn ($q) => $q->withTrashed())
            ->with(['group', 'activeQr', 'activeNfc', 'credentials' => fn ($q) => $q->whereNotNull('revoked_at')->latest('revoked_at')->limit(6)])
            ->find($this->selected);
    }

    #[Computed]
    public function groups()
    {
        return Group::ordered()->get();
    }

    private function syncGroupField(): void
    {
        $this->groupId = $this->student?->group_id;
    }

    public function render(): View
    {
        $students = Student::query()
            ->when($this->trashed, fn ($q) => $q->onlyTrashed())
            ->with('group')
            ->search($this->search)
            ->inGradeGroup($this->grade, $this->group)
            ->orderByName()
            ->paginate(self::PER_PAGE);

        $days = $this->student ? app(Reports::class)->recentDays($this->student) : [];

        return view('livewire.students-index', [
            'days' => $days,
            'students' => $students,
            'grades' => Group::grades(),
            'numbers' => Group::numbers(),
        ]);
    }
}
