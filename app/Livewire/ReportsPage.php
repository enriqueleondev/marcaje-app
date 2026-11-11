<?php

namespace App\Livewire;

use App\Models\Group;
use App\Models\Setting;
use App\Services\Reports;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reportes de asistencia (solo dirección). Filtros en la URL para compartir o recargar.
 */
class ReportsPage extends Component
{
    public const TABS = ['alumnos' => 'Por alumno', 'no-en-aula' => 'Entró pero no llegó a clase', 'manuales' => 'Entradas manuales', 'clases' => 'Clases y docentes'];

    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'hasta')]
    public string $to = '';

    #[Url(as: 'grado', except: null)]
    public ?int $grade = null;

    #[Url(as: 'grupo', except: null)]
    public ?int $group = null;

    #[Url(as: 'ver', except: 'alumnos')]
    public string $tab = 'alumnos';

    #[Url(as: 'buscar', except: '')]
    public string $search = '';

    public function mount(): void
    {
        [$this->from, $this->to] = Reports::range($this->from, $this->to);
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'alumnos';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            [$this->from, $this->to] = Reports::range($this->from, $this->to);
        }
    }

    public function preset(string $which): void
    {
        $today = now(Setting::get('timezone'))->startOfDay();
        [$from, $to] = match ($which) {
            'hoy' => [$today, $today],
            'semana' => [$today->copy()->startOfWeek(), $today],
            'mes' => [$today->copy()->startOfMonth(), $today],
            default => [$today->copy()->subDays(29), $today],
        };
        [$this->from, $this->to] = Reports::range($from->toDateString(), $to->toDateString());
    }

    public function setGrade(?int $grade): void
    {
        $this->grade = $this->grade === $grade ? null : $grade;
    }

    public function setGroup(?int $group): void
    {
        $this->group = $this->group === $group ? null : $group;
    }

    public function render(Reports $reports): View
    {
        $filters = [$this->from, $this->to, $this->grade, $this->group];

        return view('livewire.reports-page', [
            'totals' => $reports->totals(...$filters),
            'students' => $this->tab === 'alumnos' ? $reports->byStudent(...[...$filters, $this->search]) : collect(),
            'notInClass' => $this->tab === 'no-en-aula' ? $reports->notInClass(...$filters) : collect(),
            'manual' => $this->tab === 'manuales' ? $reports->manualEntries(...$filters) : collect(),
            'courses' => $this->tab === 'clases' ? $reports->byCourse($this->from, $this->to) : collect(),
            'grades' => Group::grades(),
            'numbers' => Group::numbers(),
            'tabs' => self::TABS,
            'tz' => Setting::get('timezone'),
            'exportUrl' => route('reports.export', array_filter([
                'ver' => $this->tab, 'desde' => $this->from, 'hasta' => $this->to,
                'grado' => $this->grade, 'grupo' => $this->group, 'buscar' => $this->search ?: null,
            ], fn ($v) => $v !== null)),
        ]);
    }
}
