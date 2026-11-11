<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'account', 'role:admin'])->get('/_test/admin', fn () => 'ok');
        Route::middleware(['web', 'auth', 'account', 'role:admin,teacher'])->get('/_test/staff', fn () => 'ok');
    }

    public function test_admin_only_route(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/_test/admin')->assertOk();
        $this->actingAs(User::factory()->teacher()->create())->get('/_test/admin')->assertForbidden();
    }

    public function test_route_shared_by_roles(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/_test/staff')->assertOk();
        $this->actingAs(User::factory()->teacher()->create())->get('/_test/staff')->assertOk();
    }

    public function test_guest_is_redirected_from_role_routes(): void
    {
        $this->get('/_test/admin')->assertRedirect(route('login'));
    }

    public function test_gates(): void
    {
        $admin = User::factory()->admin()->create();
        $teacher = User::factory()->teacher()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('admin'));
        $this->assertFalse(Gate::forUser($teacher)->allows('admin'));
        $this->assertTrue(Gate::forUser($teacher)->allows('teacher'));
    }

    public function test_home_shows_role(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('home'))->assertSee('Dirección');
        $this->actingAs(User::factory()->teacher()->create())->get(route('home'))->assertSee('Docente');
    }
}
