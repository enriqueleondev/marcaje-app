<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_admin(): void
    {
        $this->artisan('marcaje:crear-admin', [
            '--nombre' => 'Gabriela', '--paterno' => 'Solís', '--materno' => 'Ruiz',
            '--email' => 'direccion@escuela.mx', '--password' => 'clave-segura-2026', // gitleaks:allow (contraseña de prueba)
        ])->assertSuccessful();

        $user = User::where('email', 'direccion@escuela.mx')->sole();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame('Gabriela Solís Ruiz', $user->full_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'subject_id' => $user->id]);
    }

    public function test_rejects_weak_password_and_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ya@escuela.mx']);

        $this->artisan('marcaje:crear-admin', [
            '--nombre' => 'A', '--paterno' => 'B', '--materno' => '', '--email' => 'ya@escuela.mx', '--password' => 'corta',
        ])->assertFailed();

        $this->assertSame(1, User::count());
    }
}
