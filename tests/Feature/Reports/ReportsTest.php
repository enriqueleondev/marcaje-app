<?php

namespace Tests\Feature\Reports;

use App\Enums\AttendanceStatus;
use App\Livewire\ReportsPage;
use App\Livewire\StudentsIndex;
use App\Models\Course;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use App\Services\Reports;
use App\Services\RollCalls;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Escenario: lunes 9 y martes 10 de noviembre de 2026, clase diaria 07:50–08:40.
 *   Ana:  lun presente,              mar entrada sin confirmar
 *   Beto: lun retardo,               mar entró pero "no en aula"
 *   Ciro: lun falta (sin entrada),   mar sin entrada y sin marca (falta)
 * Además, el miércoles 11 la clase terminó y nadie abrió la lista.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Mexico_City';

    private User $teacher;

    private Course $course;

    private Group $group;

    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00', self::TZ)); // todo se da de alta la semana anterior
        $this->teacher = User::factory()->teacher()->create(['first_name' => 'Laura', 'last_name_paternal' => 'Pineda', 'last_name_maternal' => null]);
        $this->group = Group::create(['grade' => 2, 'number' => 1]);
        $other = Group::create(['grade' => 3, 'number' => 1]);
        $this->course = Course::create(['teacher_id' => $this->teacher->id, 'name' => 'Física', 'group_id' => $this->group->id]);
        foreach ([1, 2, 3] as $day) {
            $this->course->schedules()->create(['weekday' => $day, 'starts_at' => '07:50:00', 'ends_at' => '08:40:00']);
        }
        foreach (['Ana' => $this->group, 'Beto' => $this->group, 'Ciro' => $other] as $name => $g) {
            $this->s[$name] = Student::factory()->for($g)->create(['first_name' => $name, 'last_name_paternal' => $name.'ez', 'last_name_maternal' => null]);
            $this->course->students()->attach($this->s[$name]);
        }

        $this->day('2026-11-09', ['Ana' => '07:10', 'Beto' => '07:20'], ['Ana' => AttendanceStatus::Present, 'Beto' => AttendanceStatus::Late, 'Ciro' => AttendanceStatus::Absent]);
        $this->day('2026-11-10', ['Ana' => '07:15', 'Beto' => '07:30'], ['Beto' => AttendanceStatus::Absent]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00', self::TZ));
    }

    private function day(string $date, array $entries, array $marks): void
    {
        foreach ($entries as $name => $time) {
            Entry::create(['student_id' => $this->s[$name]->id, 'method' => 'nfc', 'scanned_at' => CarbonImmutable::parse("{$date} {$time}", self::TZ)->utc(), 'received_at' => now()]);
        }
        $this->travelTo(CarbonImmutable::parse("{$date} 08:00", self::TZ));
        $rc = app(RollCalls::class)->open($this->course->schedules()->where('weekday', CarbonImmutable::parse($date)->isoWeekday())->first(), $date);
        foreach ($marks as $name => $status) {
            app(RollCalls::class)->mark($rc, $this->s[$name]->id, $status, $this->teacher);
        }
    }

    private function row(string $name)
    {
        return app(Reports::class)->byStudent('2026-11-09', '2026-11-11')->firstWhere('student.id', $this->s[$name]->id);
    }

    public function test_totals_follow_the_rules(): void
    {
        $t = app(Reports::class)->totals('2026-11-09', '2026-11-11');

        $this->assertSame(6, $t['total']);
        $this->assertSame(1, $t['present']);
        $this->assertSame(1, $t['late']);
        $this->assertSame(1, $t['unconfirmed']);
        $this->assertSame(1, $t['not_in_class']);
        $this->assertSame(2, $t['absent']);
        $this->assertSame(50, $t['rate']);      // (1 + 1 + 1) / 6
        $this->assertSame(4, $t['entries']);
        $this->assertSame(2, $t['roll_calls']);
    }

    public function test_by_student(): void
    {
        $ana = $this->row('Ana');
        $this->assertSame([2, 1, 1, 100], [$ana->total, $ana->present, $ana->unconfirmed, $ana->rate]);

        $beto = $this->row('Beto');
        $this->assertSame([1, 1, 50], [$beto->late, $beto->not_in_class, $beto->rate]);

        $ciro = $this->row('Ciro');
        $this->assertSame([2, 0], [$ciro->absent, $ciro->rate]);

        $order = app(Reports::class)->byStudent('2026-11-09', '2026-11-11')->pluck('student.first_name')->all();
        $this->assertSame(['Ciro', 'Beto', 'Ana'], $order, 'los de menor asistencia primero');
    }

    public function test_filters_by_grade_group_and_dates(): void
    {
        $this->assertSame(['Beto', 'Ana'], app(Reports::class)->byStudent('2026-11-09', '2026-11-11', 2, 1)->pluck('student.first_name')->all());
        $this->assertSame(1, app(Reports::class)->byStudent('2026-11-09', '2026-11-09')->firstWhere('student.id', $this->s['Ana']->id)->total);
        $this->assertSame(4, app(Reports::class)->totals('2026-11-09', '2026-11-11', 2, 1)['entries']); // Ana y Beto, dos días
    }

    public function test_entered_but_not_in_class(): void
    {
        $rows = app(Reports::class)->notInClass('2026-11-09', '2026-11-11');

        $this->assertCount(1, $rows);
        $this->assertSame('Beto', $rows[0]->student->first_name);
        $this->assertSame('2026-11-10', $rows[0]->rollCall->date->toDateString());
    }

    public function test_classes_without_roll_call_and_unconfirmed_entries(): void
    {
        $r = app(Reports::class)->byCourse('2026-11-09', '2026-11-11')->first();

        $this->assertSame(3, $r->expected);   // lun, mar, mié (ya terminó)
        $this->assertSame(1, $r->missing);    // el miércoles nadie abrió la lista
        $this->assertSame(1, $r->unconfirmed);
    }

    public function test_sessions_before_the_schedule_existed_are_not_counted(): void
    {
        $new = Course::create(['teacher_id' => $this->teacher->id, 'name' => 'Nueva']);
        $new->schedules()->create(['weekday' => 1, 'starts_at' => '07:00:00', 'ends_at' => '07:50:00']); // creada el miércoles 11

        $this->assertNull(app(Reports::class)->byCourse('2026-11-09', '2026-11-11')->firstWhere('course.id', $new->id));
    }

    public function test_range_is_normalized(): void
    {
        $this->assertSame(['2026-11-01', '2026-11-05'], Reports::range('2026-11-05', '2026-11-01'));
        $this->assertSame(['2026-11-09', '2026-11-11'], Reports::range('basura', null)); // por defecto: esta semana hasta hoy
        [$from, $to] = Reports::range('2025-01-01', '2026-11-11');
        $this->assertSame(Reports::MAX_DAYS, (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)));
    }

    public function test_page_tabs_and_export(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('reports.index', ['desde' => '2026-11-09', 'hasta' => '2026-11-11']))->assertOk()->assertSee('50%')->assertSee('Anaez, Ana');

        Livewire::withQueryParams(['desde' => '2026-11-09', 'hasta' => '2026-11-11'])->test(ReportsPage::class)
            ->set('tab', 'no-en-aula')->assertSee('Betoez, Beto')->assertDontSee('Anaez, Ana')
            ->set('tab', 'clases')->assertSee('Física')->assertSee('Laura Pineda')
            ->set('tab', 'alumnos')->set('search', 'Ciro')->assertSee('Ciroez, Ciro')->assertDontSee('Anaez, Ana')
            ->call('setGrade', 2)->assertDontSee('Ciroez, Ciro');

        $csv = $this->get(route('reports.export', ['desde' => '2026-11-09', 'hasta' => '2026-11-11']))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFmatricula,alumno,grupo,clases,presente,retardo,sin_confirmar,no_en_aula,falta,asistencia_pct", $csv);
        $this->assertStringContainsString('"Anaez, Ana","2° 1",2,1,0,1,0,0,100', $csv);

        $csv = $this->get(route('reports.export', ['ver' => 'clases', 'desde' => '2026-11-09', 'hasta' => '2026-11-11']))->streamedContent();
        $this->assertStringContainsString('Física,"2° 1","Laura Pineda",3,1,1', $csv);
    }

    public function test_teachers_cannot_see_reports(): void
    {
        $this->actingAs($this->teacher);
        $this->get(route('reports.index'))->assertForbidden();
        $this->get(route('reports.export'))->assertForbidden();
    }

    public function test_student_inspector_shows_recent_school_days(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(StudentsIndex::class)->call('select', $this->s['Ciro']->id)
            ->assertSee('Últimos 2 días de clases')->assertSee('Entró 0 de 2');
        Livewire::test(StudentsIndex::class)->call('select', $this->s['Ana']->id)
            ->assertSee('Entró 2 de 2');
    }
}
