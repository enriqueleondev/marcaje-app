<?php

namespace App\Livewire;

use App\Enums\AttendanceStatus;
use App\Enums\MarkState;
use App\Enums\RollCallSort;
use App\Models\RollCall;
use App\Models\Setting;
use App\Services\RollCalls;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Pase de lista (design/direccion-3.html): retícula de lugares + panel.
 * Se toca un lugar para elegirlo y se marca con los botones o con el teclado (P, R, F).
 */
class RollCallSheet extends Component
{
    #[Locked]
    public int $rollCallId;

    public string $sort = 'seat';

    public bool $reordering = false;

    public ?string $notice = null;

    public function mount(RollCall $rollCall): void
    {
        $this->authorize('view', $rollCall->course);
        $this->rollCallId = $rollCall->id;
        $this->sort = $rollCall->course->roll_call_sort->value;
    }

    #[Computed]
    public function rollCall(): RollCall
    {
        return RollCall::with(['course.group', 'course.teacher', 'schedule'])->findOrFail($this->rollCallId);
    }

    public function setStatus(int $studentId, ?string $status, RollCalls $rollCalls): void
    {
        $this->authorize('update', $this->rollCall->course);
        try {
            $rollCalls->mark($this->rollCall, $studentId, $status ? AttendanceStatus::from($status) : null, auth()->user());
            $this->notice = null;
        } catch (ValidationException $e) {
            $this->notice = $e->getMessage();
        }
    }

    public function confirmAll(RollCalls $rollCalls): void
    {
        $this->authorize('update', $this->rollCall->course);
        try {
            $n = $rollCalls->confirmAll($this->rollCall, auth()->user());
            $this->notice = $n === 1 ? '1 entrada confirmada.' : "{$n} entradas confirmadas.";
        } catch (ValidationException $e) {
            $this->notice = $e->getMessage();
        }
    }

    /** Llamado cada 20 s mientras la lista está abierta: liga entradas nuevas del kiosco. */
    public function refresh(RollCalls $rollCalls): void
    {
        $rollCalls->sync($this->rollCall);
    }

    public function updatedSort(string $value): void
    {
        $sort = RollCallSort::tryFrom($value) ?? RollCallSort::Seat;
        $this->sort = $sort->value;
        if ($sort !== RollCallSort::Seat) {
            $this->reordering = false;
        }
        if (auth()->user()->can('update', $this->rollCall->course)) {
            $this->rollCall->course->update(['roll_call_sort' => $sort]);
        }
    }

    public function toggleReorder(): void
    {
        $this->authorize('update', $this->rollCall->course);
        $this->sort = RollCallSort::Seat->value;
        $this->rollCall->course->update(['roll_call_sort' => RollCallSort::Seat]);
        $this->reordering = ! $this->reordering;
    }

    /** wire:sort: arrastrar un lugar a otra posición. */
    public function moveSeat(int $studentId, int $position, RollCalls $rollCalls): void
    {
        $this->authorize('update', $this->rollCall->course);
        $rollCalls->moveSeat($this->rollCall->course, $studentId, $position);
    }

    /** Alternativa con teclado o botones: mover un lugar antes o después. */
    public function moveBy(int $studentId, int $delta, RollCalls $rollCalls): void
    {
        $this->authorize('update', $this->rollCall->course);
        $order = $rollCalls->seatOrder($this->rollCall->course)->pluck('id');
        $index = $order->search($studentId);
        if ($index !== false) {
            $rollCalls->moveSeat($this->rollCall->course, $studentId, $index + $delta);
        }
    }

    public function resetSeats(RollCalls $rollCalls): void
    {
        $this->authorize('update', $this->rollCall->course);
        $rollCalls->resetSeats($this->rollCall->course);
        $this->notice = 'Lugares en orden alfabético.';
    }

    public function render(RollCalls $rollCalls): View
    {
        $rollCall = $this->rollCall;
        $rows = $rollCalls->rows($rollCall, RollCallSort::from($this->sort))->map(function (array $r) {
            $r['state'] = $r['mark']?->state() ?? MarkState::fromDecision(null, $r['entry'] !== null);

            return $r;
        });

        $counts = collect(MarkState::cases())->mapWithKeys(fn (MarkState $s) => [$s->value => 0])->all();
        foreach ($rows as $r) {
            $counts[$r['state']->value]++;
        }

        return view('livewire.roll-call-sheet', [
            'rc' => $rollCall,
            'course' => $rollCall->course,
            'rows' => $rows,
            'counts' => $counts,
            'open' => $rollCall->isOpen(),
            'ended' => $rollCall->hasEnded(),
            'canEdit' => auth()->user()->can('update', $rollCall->course),
            'progress' => $this->progress($rollCall),
            'tz' => Setting::get('timezone'),
        ]);
    }

    private function progress(RollCall $rc): int
    {
        $total = max(1, $rc->starts_at->diffInSeconds($rc->ends_at));
        $done = $rc->starts_at->diffInSeconds(now()); // negativo antes de empezar

        return (int) max(0, min(100, round($done / $total * 100)));
    }
}
