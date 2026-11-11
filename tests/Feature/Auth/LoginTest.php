<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Entrar')->assertSee('Cada entrada cuenta.');
    }

    public function test_login_shows_institution_name_only_when_configured(): void
    {
        $this->get(route('login'))->assertDontSee('<small>', false);

        Setting::put('institution_name', 'Escuela de Prueba');
        $this->get(route('login'))->assertSee('Escuela de Prueba');
    }

    public function test_staff_can_log_in(): void
    {
        $user = User::factory()->teacher()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->get(route('home'))->assertOk()->assertSee("Hola, {$user->first_name}.");
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->from(route('login'))
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'nope'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'El correo o la contraseña no coinciden.']);

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deleted_user_cannot_log_in(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $_) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'nope']);
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('email'));
    }

    public function test_deactivating_a_user_ends_their_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->update(['is_active' => false]);

        $this->get(route('home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_authenticated_user_skips_login_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('home'));
    }
}
