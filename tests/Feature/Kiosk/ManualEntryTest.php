<?php

namespace Tests\Feature\Kiosk;

use App\Enums\ManualReason;
use App\Livewire\ManualEntry;
use App\Livewire\ReportsPage;
use App\Models\Course;
use App\Models\Entry;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManualEntryTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Mexico_City';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:30', self::TZ));
    }

    public function test_teacher_registers_a_manual_entry_with_reason_and_author(): void
    {
        $teacher = User::factory()->teacher()->create(['first_name' => 'Laura', 'last_name_paternal' => 'Pineda', 'last_name_maternal' => null]);
        $s = Student::factory()->create(['first_name' => 'Iker', 'last_name_paternal' => 'Navarro']);
        Course::create(['teacher_id' => $teacher->id, 'name' => 'Física'])->students()->attach($s);
        $this->actingAs($teacher);

        $this->get(route('entries.manual'))->assertOk()->assertSeeLivewire(ManualEntry::class);

        Livewire::test(ManualEntry::class)
            ->set('search', 'Navarro')->assertSee('Navarro')
            ->call('pick', $s->id)
            ->set('reason', 'forgot')->set('time', '07:12')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('notice', "Entrada manual registrada: {$s->full_name}, 07:12.")
            ->assertSee('Olvidó su credencial. Registró Laura Pineda.');

        $e = Entry::sole();
        $this->assertSame('manual', $e->method);
        $this->assertSame(ManualReason::Forgot, $e->manual_reason);
        $this->assertSame($teacher->id, $e->created_by);
        $this->assertNull($e->kiosk_id);
        $this->assertSame('07:12', $e->scanned_at->timezone(self::TZ)->format('H:i'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'entry.manual']);
    }

    public function test_reason_is_required_and_other_needs_a_note(): void
    {
        $s = Student::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManualEntry::class)->call('save')->assertHasErrors(['studentId', 'reason']);
        Livewire::test(ManualEntry::class)->call('pick', $s->id)->set('reason', 'other')->call('save')->assertHasErrors(['note']);
        Livewire::test(ManualEntry::class)->call('pick', $s->id)->set('reason', 'other')->set('note', 'Cita médica')->call('save')->assertHasNoErrors();

        $this->assertSame('Cita médica', Entry::sole()->manual_note);
    }

    public function test_future_times_and_duplicates_are_rejected(): void
    {
        $s = Student::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManualEntry::class)->call('pick', $s->id)->set('reason', 'damaged')->set('time', '09:00')->call('save')->assertHasErrors(['time']);

        Livewire::test(ManualEntry::class)->call('pick', $s->id)->set('reason', 'damaged')->set('time', '08:00')->call('save')->assertHasNoErrors()->assertSet('noticeIsError', false);
        $this->assertSame(1, Entry::count(), 'primera');
        Livewire::test(ManualEntry::class)->call('pick', $s->id)->set('reason', 'forgot')->set('time', '08:03')->call('save')
            ->assertSet('noticeIsError', true)
            ->assertSee('ya tiene entrada a las 08:00 (Manual)');

        $this->assertSame(1, Entry::count());
    }

    public function test_manual_entries_show_in_the_roll_call_and_reports(): void
    {
        $teacher = User::factory()->teacher()->create(['first_name' => 'Laura', 'last_name_paternal' => 'Pineda', 'last_name_maternal' => null]);
        $s = Student::factory()->create(['first_name' => 'Iker', 'last_name_paternal' => 'Navarro', 'last_name_maternal' => null]);
        $course = Course::create(['teacher_id' => $teacher->id, 'name' => 'Física']);
        $course->students()->attach($s);
        $schedule = $course->schedules()->create(['weekday' => 2, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']); // martes 10
        $this->actingAs($teacher);
        Livewire::test(ManualEntry::class)->call('pick', $s->id)->set('reason', 'damaged')->set('time', '07:30')->call('save');

        $this->get(route('roll-calls.show', [$schedule, '2026-11-10']))->assertOk()
            ->assertSee('Entrada manual')->assertSee('entró a las 07:30 (entrada manual)', false);

        $this->actingAs(User::factory()->admin()->create());
        Livewire::withQueryParams(['desde' => '2026-11-10', 'hasta' => '2026-11-10', 'ver' => 'manuales'])->test(ReportsPage::class)
            ->assertSee('Navarro, Iker')->assertSee('Credencial dañada')->assertSee('Laura Pineda');
        $csv = $this->get(route('reports.export', ['ver' => 'manuales', 'desde' => '2026-11-10', 'hasta' => '2026-11-10']))->streamedContent();
        $this->assertStringContainsString('2026-11-10,07:30', $csv);
        $this->assertStringContainsString('"Credencial dañada",,"Laura Pineda"', $csv);
    }
}
