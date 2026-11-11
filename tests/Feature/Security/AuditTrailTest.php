<?php

namespace Tests\Feature\Security;

use App\Enums\ManualReason;
use App\Livewire\StudentsIndex;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Services\Credentials;
use App\Services\EntryRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** La bitácora registra altas, bajas, contraseñas, credenciales y entradas manuales, con su autor. */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_actions_are_logged_with_their_author(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        // Altas y bajas de personal
        $this->post(route('teachers.store'), ['first_name' => 'Ana', 'last_name_paternal' => 'Paz', 'email' => 'ana@escuela.mx', 'role' => 'teacher']);
        $teacher = User::where('email', 'ana@escuela.mx')->sole();
        $this->post(route('teachers.temporary-password', $teacher));
        $this->delete(route('teachers.destroy', $teacher));

        // Altas y bajas de alumnos
        $this->post(route('students.store'), ['first_name' => 'Iker', 'last_name_paternal' => 'Nava', 'enrollment_number' => '900']);
        $student = Student::where('enrollment_number', '900')->sole();
        Livewire::test(StudentsIndex::class)->call('select', $student->id)->call('delete');
        $student->restore();

        // Credenciales
        app(Credentials::class)->regenerateQr($student, $admin);
        app(Credentials::class)->linkNfc($student, 'AABBCCDD', $admin);

        // Entrada manual
        app(EntryRecorder::class)->recordManual($student, ManualReason::Forgot, null, CarbonImmutable::now()->subMinute(), $admin);

        // Cambio de contraseña propio
        $this->put(route('password.update'), ['current_password' => 'password123', 'password' => 'nueva-clave-2026', 'password_confirmation' => 'nueva-clave-2026']);

        $expected = ['user.created', 'password.temporary_issued', 'user.deleted', 'student.created', 'student.deleted',
            'credential.qr_regenerated', 'credential.nfc_linked', 'entry.manual', 'password.changed'];
        $logged = AuditLog::pluck('action')->unique()->values()->all();
        foreach ($expected as $action) {
            $this->assertContains($action, $logged, "Falta en la bitácora: {$action}");
            $this->assertSame($admin->id, AuditLog::where('action', $action)->latest('id')->value('user_id'), "Autor incorrecto en {$action}");
        }
    }
}
