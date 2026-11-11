<?php

namespace Tests\Feature\RollCall;

use App\Enums\AttendanceStatus;
use App\Enums\MarkState;
use App\Enums\RollCallSort;
use App\Livewire\RollCallSheet;
use App\Models\Course;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Kiosk;
use App\Models\RollCall;
use App\Models\Student;
use App\Models\User;
use App\Services\RollCalls;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RollCallTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Mexico_City';

    private const DAY = '2026-11-09'; // lunes

    private User $teacher;

    private Course $course;

    private Student $ana;   // entra 07:10

    private Student $beto;  // entra 07:55 (ya empezada la clase)

    private Student $ciro;  // no entra

    private RollCalls $rollCalls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00');
        $this->rollCalls = app(RollCalls::class);
        $this->teacher = User::factory()->teacher()->create();
        $group = Group::create(['grade' => 1, 'number' => 3]);
        $this->course = Course::create(['teacher_id' => $this->teacher->id, 'name' => 'Biología I', 'group_id' => $group->id]);
        $this->course->schedules()->create(['weekday' => 1, 'starts_at' => '07:50:00', 'ends_at' => '08:40:00']);
        $this->ana = Student::factory()->for($group)->create(['first_name' => 'Ana', 'last_name_paternal' => 'Zúñiga', 'last_name_maternal' => 'Álvarez']);
        $this->beto = Student::factory()->for($group)->create(['first_name' => 'Beto', 'last_name_paternal' => 'Ávila', 'last_name_maternal' => 'Mora']);
        $this->ciro = Student::factory()->for($group)->create(['first_name' => 'Ciro', 'last_name_paternal' => 'López', 'last_name_maternal' => 'Bello']);
        $this->course->students()->attach([$this->ana->id, $this->beto->id, $this->ciro->id]);
        $this->entry($this->ana, '07:10');
        $this->entry($this->beto, '07:55');
    }

    private function at(string $time, string $day = self::DAY): void
    {
        $this->travelTo(CarbonImmutable::parse("{$day} {$time}", self::TZ));
    }

    private function entry(Student $s, string $time, string $day = self::DAY): Entry
    {
        return Entry::create([
            'student_id' => $s->id, 'method' => 'qr', 'kiosk_id' => null,
            'scanned_at' => CarbonImmutable::parse("{$day} {$time}", self::TZ)->utc(),
            'received_at' => now(),
        ]);
    }

    private function open(): RollCall
    {
        return $this->rollCalls->open($this->course->schedules()->first(), self::DAY);
    }

    private function state(RollCall $rc, Student $s): MarkState
    {
        return $rc->marks()->where('student_id', $s->id)->sole()->state();
    }

    public function test_opening_links_each_student_with_their_entry_of_the_day(): void
    {
        $this->entry($this->ana, '07:30', '2026-11-08'); // otro día: no cuenta
        $rc = $this->open();

        $this->assertSame(3, $rc->marks()->count());
        $this->assertSame(MarkState::Pending, $this->state($rc, $this->ana));
        $this->assertSame(MarkState::Pending, $this->state($rc, $this->beto));
        $this->assertSame(MarkState::Absent, $this->state($rc, $this->ciro));
        $this->assertTrue($rc->isOpen());
    }

    public function test_late_entries_show_up_while_the_list_is_open(): void
    {
        $rc = $this->open();
        $this->entry($this->ciro, '08:05');
        $this->rollCalls->sync($rc);

        $this->assertSame(MarkState::Pending, $this->state($rc, $this->ciro));
    }

    public function test_entries_after_the_class_do_not_count(): void
    {
        $this->entry($this->ciro, '09:00');
        $this->assertSame(MarkState::Absent, $this->state($this->open(), $this->ciro));
    }

    public function test_marking_states_and_not_in_class(): void
    {
        $rc = $this->open();
        $this->rollCalls->mark($rc, $this->ana->id, AttendanceStatus::Absent, $this->teacher);
        $this->rollCalls->mark($rc, $this->beto->id, AttendanceStatus::Late, $this->teacher);
        $this->rollCalls->mark($rc, $this->ciro->id, AttendanceStatus::Absent, $this->teacher);

        $this->assertSame(MarkState::NotInClass, $this->state($rc, $this->ana));   // entró pero no está en clase
        $this->assertSame(MarkState::Late, $this->state($rc, $this->beto));
        $this->assertSame(MarkState::Absent, $this->state($rc, $this->ciro));      // falta: no hay entrada

        $this->rollCalls->mark($rc, $this->ana->id, null, $this->teacher);
        $this->assertSame(MarkState::Pending, $this->state($rc, $this->ana));
    }

    public function test_confirm_all_only_confirms_pending_entries(): void
    {
        $rc = $this->open();
        $this->rollCalls->mark($rc, $this->beto->id, AttendanceStatus::Late, $this->teacher);

        $this->assertSame(1, $this->rollCalls->confirmAll($rc, $this->teacher));
        $this->assertSame(MarkState::Present, $this->state($rc, $this->ana));
        $this->assertSame(MarkState::Late, $this->state($rc, $this->beto));
        $this->assertSame(MarkState::Absent, $this->state($rc, $this->ciro));
    }

    public function test_only_editable_during_the_class(): void
    {
        $this->at('07:40');
        $rc = $this->open();
        $this->assertFalse($rc->isOpen());

        try {
            $this->rollCalls->mark($rc, $this->ana->id, AttendanceStatus::Present, $this->teacher);
            $this->fail('No debió permitir marcar antes de la clase');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('07:50 a 08:40', $e->getMessage());
        }

        $this->at('08:41');
        $this->expectException(ValidationException::class);
        $this->rollCalls->confirmAll($rc->fresh(), $this->teacher);
    }

    public function test_sort_orders(): void
    {
        $rc = $this->open();
        $names = fn (RollCallSort $s) => $this->rollCalls->rows($rc, $s)->map(fn ($r) => $r['student']->first_name)->all();

        $this->assertSame(['Beto', 'Ciro', 'Ana'], $names(RollCallSort::Seat));        // alfabético por paterno: Ávila, López, Zúñiga
        $this->assertSame(['Beto', 'Ciro', 'Ana'], $names(RollCallSort::Paternal));
        $this->assertSame(['Ana', 'Ciro', 'Beto'], $names(RollCallSort::Maternal));    // Álvarez, Bello, Mora
        $this->assertSame(['Ana', 'Beto', 'Ciro'], $names(RollCallSort::FirstName));
        $this->assertSame(['Ana', 'Beto', 'Ciro'], $names(RollCallSort::Arrival));     // 07:10, 07:55, sin entrada
    }

    public function test_teacher_rearranges_seats_and_can_reset(): void
    {
        $rc = $this->open();
        $this->rollCalls->moveSeat($this->course, $this->ana->id, 0);
        $this->assertSame(['Ana', 'Beto', 'Ciro'], $this->rollCalls->rows($rc, RollCallSort::Seat)->map(fn ($r) => $r['student']->first_name)->all());

        $this->rollCalls->moveSeat($this->course, $this->beto->id, 5);
        $this->assertSame(['Ana', 'Ciro', 'Beto'], $this->rollCalls->seatOrder($this->course)->pluck('first_name')->all());

        $this->rollCalls->resetSeats($this->course);
        $this->assertSame(['Beto', 'Ciro', 'Ana'], $this->rollCalls->seatOrder($this->course)->pluck('first_name')->all());
    }

    public function test_sheet_component_marks_confirms_sorts_and_moves(): void
    {
        $this->actingAs($this->teacher);
        $rc = $this->open();

        Livewire::test(RollCallSheet::class, ['rollCall' => $rc])
            ->assertSee('Lista abierta')->assertSee('Confirmar todas las entradas (2)')->assertDontSee('Sin entradas por confirmar')
            ->call('setStatus', $this->beto->id, 'late')
            ->call('confirmAll')->assertSet('notice', '1 entrada confirmada.')
            ->set('sort', 'arrival')
            ->call('toggleReorder')->assertSet('sort', 'seat')->assertSet('reordering', true)
            ->call('moveSeat', $this->ciro->id, 0)
            ->call('moveBy', $this->ciro->id, 1)
            ->call('resetSeats');

        $this->assertSame(MarkState::Present, $this->state($rc, $this->ana));
        $this->assertSame(MarkState::Late, $this->state($rc, $this->beto));
        $this->assertSame(RollCallSort::Seat, $this->course->fresh()->roll_call_sort);
    }

    public function test_sheet_shows_a_message_when_closed(): void
    {
        $this->actingAs($this->teacher);
        $rc = $this->open();
        $this->at('09:00');

        Livewire::test(RollCallSheet::class, ['rollCall' => $rc])
            ->assertSee('Lista cerrada a las 08:40')
            ->call('setStatus', $this->ana->id, 'present')
            ->assertSet('notice', 'La lista solo se puede editar durante la clase (07:50 a 08:40).');
        $this->assertSame(MarkState::Pending, $this->state($rc, $this->ana));
    }

    public function test_index_and_show_pages(): void
    {
        $this->actingAs($this->teacher);
        $schedule = $this->course->schedules()->first();

        $this->get(route('roll-calls.index'))->assertOk()->assertSee('Biología I, 1° 3')->assertSee('En curso')->assertSee('Pasar lista');
        $this->get(route('roll-calls.show', [$schedule, self::DAY]))->assertOk()->assertSeeLivewire(RollCallSheet::class)->assertSee('Zúñiga');
        $this->get(route('roll-calls.show', [$schedule, '2026-11-10']))->assertNotFound();        // martes: no hay clase
        $this->get(route('roll-calls.show', [$schedule, '2026-11-16']))->assertRedirect();        // futuro
        $this->get(route('roll-calls.index', ['fecha' => '2026-11-10']))->assertSee('No hay clases este día');
    }

    public function test_other_teachers_cannot_open_the_list_but_admin_can(): void
    {
        $schedule = $this->course->schedules()->first();

        $this->actingAs(User::factory()->teacher()->create())->get(route('roll-calls.show', [$schedule, self::DAY]))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('roll-calls.show', [$schedule, self::DAY]))->assertOk();
    }

    public function test_kiosk_entries_reach_the_list(): void
    {
        $kiosk = new Kiosk(['name' => 'K']);
        $token = $kiosk->issueToken();
        $rc = $this->open();

        $this->withToken($token)->postJson(route('kiosk.api.scans'), ['scans' => [[
            'uuid' => (string) Str::uuid(), 'code' => $this->ciro->activeQr->value, 'scanned_at' => now()->toIso8601String(),
        ]]])->assertJsonPath('results.0.status', 'ok');

        $this->rollCalls->sync($rc);
        $this->assertSame(MarkState::Pending, $this->state($rc, $this->ciro));
    }
}
