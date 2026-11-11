<?php

namespace App\Http\Controllers;

use App\Models\CourseSchedule;
use App\Models\RollCall;
use App\Models\Setting;
use App\Services\RollCalls;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RollCallController extends Controller
{
    /** Clases del día (hoy por defecto) con su estado. */
    public function index(Request $request, RollCalls $rollCalls): View
    {
        $tz = Setting::get('timezone');
        $today = CarbonImmutable::now($tz)->toDateString();
        $date = $this->validDate($request->query('fecha')) ?? $today;

        $classes = $rollCalls->classesOn($request->user(), $date)->map(function (CourseSchedule $s) use ($date) {
            [$start, $end] = $s->windowOn($date);
            $now = CarbonImmutable::now();
            $s->setAttribute('window_state', $now->lt($start) ? 'next' : ($now->gt($end) ? 'done' : 'now'));
            $s->setAttribute('roll_call', RollCall::where('course_schedule_id', $s->id)->whereDate('date', $date)->withCount([
                'marks as decided_count' => fn ($q) => $q->whereNotNull('status'),
                'marks',
            ])->first());

            return $s;
        });

        return view('roll-calls.index', [
            'classes' => $classes,
            'date' => $date,
            'today' => $today,
            'isToday' => $date === $today,
            'dateLabel' => ucfirst(CarbonImmutable::parse($date, $tz)->locale('es')->isoFormat('dddd D [de] MMMM')),
        ]);
    }

    public function show(Request $request, CourseSchedule $schedule, string $date, RollCalls $rollCalls): View|RedirectResponse
    {
        $this->authorize('view', $schedule->course);

        $date = $this->validDate($date) ?? abort(404);
        $tz = Setting::get('timezone');
        $day = CarbonImmutable::parse($date, $tz);
        abort_unless($day->isoWeekday() === $schedule->weekday, 404);

        if ($day->gt(CarbonImmutable::now($tz)->endOfDay())) {
            return redirect()->route('roll-calls.index', ['fecha' => $date])
                ->with('status', 'Ese pase de lista todavía no existe: se abre el día de la clase.');
        }

        return view('roll-calls.show', ['rollCall' => $rollCalls->open($schedule, $date)]);
    }

    private function validDate(?string $value): ?string
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
