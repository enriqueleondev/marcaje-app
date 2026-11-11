<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_a_temporary_password_forces_a_change_within_24_hours(): void
    {
        $admin = User::factory()->admin()->create();
        $teacher = User::factory()->teacher()->create();

        $plain = $teacher->issueTemporaryPassword($admin);
        $teacher->refresh();

        $this->assertMatchesRegularExpression('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/', $plain);
        $this->assertTrue(Hash::check($plain, $teacher->password));
        $this->assertTrue($teacher->must_change_password);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $teacher->password_expires_at->timestamp, 5);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password.temporary_issued', 'user_id' => $admin->id, 'subject_id' => $teacher->id]);
    }

    public function test_login_with_temporary_password_goes_to_change_password(): void
    {
        $teacher = User::factory()->create();
        $plain = $teacher->issueTemporaryPassword();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => $plain])
            ->assertRedirect(route('password.edit'));

        $this->get(route('home'))->assertRedirect(route('password.edit'));
        $this->get(route('password.edit'))->assertOk()->assertSee('Elige tu contraseña');
    }

    public function test_expired_temporary_password_cannot_log_in(): void
    {
        $teacher = User::factory()->create();
        $plain = $teacher->issueTemporaryPassword();
        $this->travel(25)->hours();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => $plain])
            ->assertSessionHasErrors(['email' => 'Tu contraseña temporal venció. Pide una nueva a dirección.']);

        $this->assertGuest();
    }

    public function test_session_ends_when_temporary_password_expires(): void
    {
        $teacher = User::factory()->withTemporaryPassword()->create();
        $this->actingAs($teacher);
        $this->travel(25)->hours();

        $this->get(route('password.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_changing_the_password_clears_the_temporary_flag(): void
    {
        $teacher = User::factory()->create();
        $plain = $teacher->issueTemporaryPassword();
        $this->actingAs($teacher->fresh());

        $this->put(route('password.update'), [
            'current_password' => $plain,
            'password' => 'nueva-clave-2026',
            'password_confirmation' => 'nueva-clave-2026',
        ])->assertRedirect(route('home'));

        $teacher->refresh();
        $this->assertFalse($teacher->must_change_password);
        $this->assertNull($teacher->password_expires_at);
        $this->assertTrue(Hash::check('nueva-clave-2026', $teacher->password));
        $this->assertSame(1, AuditLog::where('action', 'password.changed')->count());
        $this->get(route('home'))->assertOk();
    }

    public function test_new_password_must_be_strong_confirmed_and_different(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->put(route('password.update'), ['current_password' => 'password123', 'password' => 'corta1', 'password_confirmation' => 'corta1'])
            ->assertSessionHasErrors('password');
        $this->put(route('password.update'), ['current_password' => 'password123', 'password' => 'soloLetrasLargas', 'password_confirmation' => 'soloLetrasLargas'])
            ->assertSessionHasErrors('password');
        $this->put(route('password.update'), ['current_password' => 'password123', 'password' => 'nueva-clave-2026', 'password_confirmation' => 'otra-clave-2026']) // gitleaks:allow (contraseña de prueba)
            ->assertSessionHasErrors('password');
        $this->put(route('password.update'), ['current_password' => 'password123', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertSessionHasErrors('password');
        $this->put(route('password.update'), ['current_password' => 'mal', 'password' => 'nueva-clave-2026', 'password_confirmation' => 'nueva-clave-2026'])
            ->assertSessionHasErrors('current_password');
    }
}
