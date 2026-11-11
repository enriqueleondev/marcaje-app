<?php

namespace Tests\Feature\School;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\LoginThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    private function upload(string $csv, string $role = 'teacher')
    {
        return $this->post(route('teachers.import.preview'), ['role' => $role, 'file' => UploadedFile::fake()->createWithContent('personal.csv', $csv)]);
    }

    public function test_preview_shows_errors_per_row_and_creates_nothing(): void
    {
        $gone = User::factory()->create(['email' => 'baja@escuela.mx']);
        $gone->delete();

        $this->upload("nombre,apellido_paterno,apellido_materno,correo\n".
            "Ana,Paz,,ana@escuela.mx\n".
            "Beto,Ríos,,ANA@escuela.mx\n".
            ",Mena,,ciro@escuela.mx\n".
            "Dora,Luz,,no-es-correo\n".
            "Eva,Sol,,baja@escuela.mx\n")->assertRedirect(route('teachers.import'));

        $this->get(route('teachers.import'))->assertOk()
            ->assertSee('Hay 4 filas con error')
            ->assertSee('El correo se repite (también en la línea 2).')
            ->assertSee('Falta el nombre.')
            ->assertSee('no es válido')
            ->assertSee('Ya existe una cuenta con ese correo (puede estar dada de baja).')
            ->assertDontSee('Crear ');

        $this->post(route('teachers.import.confirm'))->assertSessionHasErrors('file');
        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_valid_import_creates_accounts_with_temporary_passwords_shown_once(): void
    {
        $this->upload("Nombre(s);Apellido Paterno;Apellido Materno;Correo electrónico\nLaura;Pineda;Ávila;Laura.Pineda@Escuela.MX\nMartín;Cárdenas;;martin@escuela.mx\n", 'admin');
        $this->get(route('teachers.import'))->assertSee('Se crearán 2 cuentas')->assertSee('Crear 2 cuentas');

        $this->post(route('teachers.import.confirm'))->assertRedirect(route('teachers.import.done'));
        $page = $this->get(route('teachers.import.done'))->assertOk()->assertSee('2 cuentas creadas');

        $laura = User::where('email', 'laura.pineda@escuela.mx')->sole();
        $this->assertSame(Role::Admin, $laura->role);
        $this->assertTrue($laura->must_change_password);
        preg_match('/laura\.pineda@escuela\.mx<\/td><td class="mono">([a-z0-9-]{14})</', $page->getContent(), $m);
        $this->assertTrue(Hash::check($m[1], $laura->password), 'la contraseña mostrada es la real');
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.imported']);
        $this->assertSame(2, AuditLog::where('action', 'password.temporary_issued')->count());

        $this->get(route('teachers.import.done'))->assertRedirect(route('teachers.index')); // ya no se vuelve a mostrar
    }

    public function test_confirm_revalidates_if_an_email_was_taken_meanwhile(): void
    {
        $this->upload("nombre,apellido_paterno,correo\nAna,Paz,ana@escuela.mx\n");
        User::factory()->create(['email' => 'ana@escuela.mx']);

        $this->post(route('teachers.import.confirm'))->assertSessionHasErrors('file');
        $this->assertSame(1, User::where('email', 'ana@escuela.mx')->count());
    }

    public function test_template_download(): void
    {
        $csv = $this->get(route('teachers.import.template'))->assertOk()->streamedContent();
        $this->assertStringContainsString('nombre,apellido_paterno,apellido_materno,correo', $csv);
    }

    public function test_revoke_sessions_and_suspend_logs_out_immediately(): void
    {
        $t = User::factory()->teacher()->create();
        foreach (['s1', 's2'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $t->id, 'payload' => '', 'last_activity' => time()]);
        }

        $this->get(route('teachers.edit', $t))->assertSee('Sesiones abiertas')->assertSee('Cerrar sus sesiones');
        $this->post(route('teachers.revoke-sessions', $t))->assertSessionHas('status', 'Se cerraron 2 sesiones.');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $t->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.sessions_revoked', 'subject_id' => $t->id]);

        DB::table('sessions')->insert(['id' => 's3', 'user_id' => $t->id, 'payload' => '', 'last_activity' => time()]);
        $this->patch(route('teachers.toggle-active', $t));
        $this->assertFalse($t->fresh()->is_active);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $t->id)->count(), 'suspender cierra sus sesiones');

        $this->post(route('teachers.revoke-sessions', $this->admin))->assertForbidden();
    }

    public function test_admin_sees_and_unlocks_an_account_locked_by_failed_logins(): void
    {
        $t = User::factory()->teacher()->create();
        auth()->logout();
        foreach (range(1, 5) as $_) {
            $this->post(route('login.store'), ['email' => $t->email, 'password' => 'mal']);
        }
        $this->assertGreaterThan(0, LoginThrottle::accountLockedFor($t->email));

        $this->actingAs($this->admin);
        $this->get(route('teachers.index'))->assertSee('Bloqueada por intentos fallidos');
        $this->get(route('teachers.edit', $t))->assertSee('Desbloquear ahora');
        $this->post(route('teachers.unlock', $t))->assertSessionHas('status');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.unlocked', 'subject_id' => $t->id]);

        auth()->logout();
        $this->post(route('login.store'), ['email' => $t->email, 'password' => 'password123'])->assertRedirect(route('home'));
    }

    public function test_lock_is_per_account_from_any_ip(): void
    {
        $t = User::factory()->create();
        auth()->logout();
        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->post(route('login.store'), ['email' => $t->email, 'password' => 'mal']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->post(route('login.store'), ['email' => $t->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_last_active_admin_and_self_demotion_rules(): void
    {
        $this->put(route('teachers.update', $this->admin), ['first_name' => 'A', 'last_name_paternal' => 'B', 'email' => $this->admin->email, 'role' => 'teacher'])->assertForbidden();
        $this->patch(route('teachers.toggle-active', $this->admin))->assertForbidden();
        $this->delete(route('teachers.destroy', $this->admin))->assertForbidden();

        $other = User::factory()->admin()->create();
        $this->put(route('teachers.update', $other), ['first_name' => 'A', 'last_name_paternal' => 'B', 'email' => $other->email, 'role' => 'teacher'])->assertSessionHasNoErrors();
        $this->assertSame(Role::Teacher, $other->fresh()->role);
    }

    public function test_teachers_cannot_manage_staff(): void
    {
        $t = User::factory()->teacher()->create();
        $other = User::factory()->teacher()->create();
        $this->actingAs($t);

        $this->get(route('teachers.import'))->assertForbidden();
        $this->post(route('teachers.import.preview'), [])->assertForbidden();
        $this->post(route('teachers.import.confirm'))->assertForbidden();
        $this->get(route('teachers.import.template'))->assertForbidden();
        $this->post(route('teachers.revoke-sessions', $other))->assertForbidden();
        $this->post(route('teachers.unlock', $other))->assertForbidden();
        $this->patch(route('teachers.toggle-active', $other))->assertForbidden();
    }
}
