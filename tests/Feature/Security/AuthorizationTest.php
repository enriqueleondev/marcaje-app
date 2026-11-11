<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\AuthenticateKiosk;
use App\Livewire\ManualEntry;
use App\Livewire\RollCallSheet;
use App\Livewire\StudentsIndex;
use App\Models\Course;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Kiosk;
use App\Models\Student;
use App\Models\User;
use App\Services\RollCalls;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Autorización por rol en todas las rutas y acciones, e IDOR (acceso a registros
 * ajenos cambiando un id).
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Rutas exclusivas de dirección, leídas del router: si se agrega una ruta sin 'role:admin', esta prueba no la cubre, pero la de abajo sí la detecta. */
    private function adminRoutes()
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('role:admin', $r->gatherMiddleware(), true));
    }

    /** @var array<string, int> ids reales por nombre de parámetro (en MySQL no empiezan en 1) */
    private array $ids = [];

    private function fillUri(string $uri): string
    {
        return '/'.ltrim(preg_replace_callback('/\{([^}?]+)\??\}/', fn ($m) => (string) ($this->ids[$m[1]] ?? 1), $uri), '/');
    }

    private function seedOneOfEach(): void
    {
        $g = Group::create(['grade' => 1, 'number' => 1]);
        $student = Student::factory()->for($g)->create();
        $user = User::factory()->admin()->create();
        $kiosk = new Kiosk(['name' => 'K']);
        $kiosk->issueToken();
        $this->ids = ['group' => $g->id, 'student' => $student->id, 'user' => $user->id, 'kiosk' => $kiosk->id, 'id' => $user->id];
    }

    public function test_every_admin_route_rejects_teachers_and_guests(): void
    {
        $this->seedOneOfEach();
        $routes = $this->adminRoutes();
        $this->assertGreaterThan(25, $routes->count(), 'se esperaban todas las rutas de dirección');

        $teacher = User::factory()->teacher()->create();
        foreach ($routes as $route) {
            $method = strtolower(collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first());
            $uri = $this->fillUri($route->uri());

            $this->actingAs($teacher)->{$method}($uri)
                ->assertStatus(403, "Docente debería recibir 403 en {$method} {$uri}");
            auth()->logout();
            $this->{$method}($uri)->assertRedirect(route('login'));
        }
    }

    public function test_every_web_route_requires_login_except_public_ones(): void
    {
        $public = ['login', 'login.store', 'kiosk.show', 'kiosk.manifest'];
        $unprotected = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('web', $r->gatherMiddleware(), true))
            ->reject(fn ($r) => in_array('auth', $r->gatherMiddleware(), true))
            ->reject(fn ($r) => in_array($r->getName(), $public, true))
            ->reject(fn ($r) => str_starts_with($r->uri(), 'livewire') || str_starts_with($r->uri(), 'storage') || str_starts_with($r->uri(), '_'))
            ->map(fn ($r) => $r->uri())
            ->values()->all();

        $this->assertSame([], $unprotected, 'Rutas web sin login: '.implode(', ', $unprotected));
    }

    public function test_kiosk_api_routes_require_a_kiosk_token(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/'));
        $this->assertNotEmpty($routes);
        foreach ($routes as $r) {
            $this->assertContains(AuthenticateKiosk::class, $r->gatherMiddleware(), $r->uri());
        }
        // Una sesión de dirección no sirve para la API del kiosco
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/kiosco/padron')->assertUnauthorized();
    }

    public function test_teachers_only_change_group_of_students_in_their_classes(): void
    {
        $teacher = User::factory()->teacher()->create();
        $mine = Student::factory()->create();
        $other = Student::factory()->create();
        $target = Group::create(['grade' => 2, 'number' => 2]);
        Course::create(['teacher_id' => $teacher->id, 'name' => 'Física'])->students()->attach($mine);
        $this->actingAs($teacher);

        Livewire::test(StudentsIndex::class)->call('select', $mine->id)->assertSee('Cambiar grupo')
            ->set('groupId', $target->id)->call('saveGroup')->assertOk();
        $this->assertSame($target->id, $mine->fresh()->group_id);

        Livewire::test(StudentsIndex::class)->call('select', $other->id)->assertDontSee('Cambiar grupo')
            ->set('groupId', $target->id)->call('saveGroup')->assertForbidden();
        $this->assertNull($other->fresh()->group_id);
    }

    public function test_teachers_only_register_manual_entries_for_their_students(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:30', 'America/Mexico_City'));
        $teacher = User::factory()->teacher()->create();
        $mine = Student::factory()->create(['last_name_paternal' => 'Propio']);
        $other = Student::factory()->create(['last_name_paternal' => 'Ajeno']);
        Course::create(['teacher_id' => $teacher->id, 'name' => 'Física'])->students()->attach($mine);
        $this->actingAs($teacher);

        Livewire::test(ManualEntry::class)->set('search', 'Ajeno')->assertDontSee('Ajeno');
        Livewire::test(ManualEntry::class)->call('pick', $other->id)->assertForbidden();
        Livewire::test(ManualEntry::class)->set('studentId', $other->id)->set('reason', 'forgot')->call('save')->assertForbidden();
        Livewire::test(ManualEntry::class)->set('search', 'Propio')->assertSee('Propio')->call('pick', $mine->id)->set('reason', 'forgot')->call('save')->assertHasNoErrors();

        $this->assertSame([$mine->id], Entry::pluck('student_id')->all());
    }

    public function test_teachers_cannot_reach_other_teachers_classes_or_roll_calls(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:30', 'America/Mexico_City'));
        $owner = User::factory()->teacher()->create();
        $course = Course::create(['teacher_id' => $owner->id, 'name' => 'Ajena']);
        $s = Student::factory()->create();
        $course->students()->attach($s);
        $schedule = $course->schedules()->create(['weekday' => 2, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']);
        $rc = app(RollCalls::class)->open($schedule, '2026-11-10');
        $intruder = User::factory()->teacher()->create();
        $this->actingAs($intruder);

        $this->get(route('courses.show', $course))->assertForbidden();
        $this->get(route('roll-calls.show', [$schedule, '2026-11-10']))->assertForbidden();
        Livewire::test(RollCallSheet::class, ['rollCall' => $rc])->assertForbidden();
        $this->get(route('roll-calls.index'))->assertDontSee('Ajena');
        $this->get(route('courses.index'))->assertDontSee('Ajena');
    }

    public function test_locked_ids_cannot_be_swapped_from_the_browser(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:30', 'America/Mexico_City'));
        $mine = User::factory()->teacher()->create();
        $myCourse = Course::create(['teacher_id' => $mine->id, 'name' => 'Mía']);
        $mySchedule = $myCourse->schedules()->create(['weekday' => 2, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']);
        $myRc = app(RollCalls::class)->open($mySchedule, '2026-11-10');
        $other = Course::create(['teacher_id' => User::factory()->teacher()->create()->id, 'name' => 'Ajena']);
        $otherRc = app(RollCalls::class)->open($other->schedules()->create(['weekday' => 2, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']), '2026-11-10');
        $this->actingAs($mine);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(RollCallSheet::class, ['rollCall' => $myRc])->set('rollCallId', $otherRc->id);
    }
}
