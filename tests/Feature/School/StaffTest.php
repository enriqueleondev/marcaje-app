<?php

namespace Tests\Feature\School;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_creating_an_account_shows_a_temporary_password_once(): void
    {
        $response = $this->post(route('teachers.store'), [
            'first_name' => 'Laura', 'last_name_paternal' => 'Pineda', 'last_name_maternal' => 'Ávila',
            'email' => ' Laura.Pineda@Escuela.MX ', 'role' => 'teacher',
        ]);

        $teacher = User::where('email', 'laura.pineda@escuela.mx')->sole();
        $response->assertRedirect(route('teachers.edit', $teacher));
        $temporary = session('temporary_password');

        $this->assertTrue(Hash::check($temporary, $teacher->password));
        $this->assertTrue($teacher->must_change_password);
        $this->assertSame(Role::Teacher, $teacher->role);

        $this->get(route('teachers.edit', $teacher))->assertSee($temporary);
        $this->get(route('teachers.edit', $teacher))->assertDontSee($temporary);
    }

    public function test_index_lists_teachers_and_admins(): void
    {
        $t = User::factory()->teacher()->withTemporaryPassword()->create();

        $this->get(route('teachers.index'))->assertOk()
            ->assertSee($t->full_name)->assertSee($this->admin->full_name)
            ->assertSee('Contraseña temporal, vence en 24 h');
    }

    public function test_issue_temporary_password_for_someone_else_only(): void
    {
        $t = User::factory()->teacher()->create();

        $this->post(route('teachers.temporary-password', $t))->assertSessionHas('temporary_password');
        $this->assertTrue($t->fresh()->must_change_password);

        $this->post(route('teachers.temporary-password', $this->admin))->assertForbidden();
    }

    public function test_deactivate_and_activate(): void
    {
        $t = User::factory()->teacher()->create();

        $this->patch(route('teachers.toggle-active', $t))->assertRedirect();
        $this->assertFalse($t->fresh()->is_active);
        $this->patch(route('teachers.toggle-active', $t));
        $this->assertTrue($t->fresh()->is_active);
    }

    public function test_cannot_deactivate_delete_or_demote_yourself(): void
    {
        $this->patch(route('teachers.toggle-active', $this->admin))->assertForbidden();
        $this->delete(route('teachers.destroy', $this->admin))->assertForbidden();
        $this->put(route('teachers.update', $this->admin), [
            'first_name' => 'A', 'last_name_paternal' => 'B', 'email' => $this->admin->email, 'role' => 'teacher',
        ])->assertForbidden();

        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_delete_and_restore(): void
    {
        $t = User::factory()->teacher()->create();

        $this->delete(route('teachers.destroy', $t))->assertRedirect(route('teachers.index'));
        $this->assertSoftDeleted($t);
        $this->get(route('teachers.index', ['ver' => 'bajas']))->assertSee($t->full_name);

        $this->post(route('teachers.restore', $t->id))->assertRedirect(route('teachers.edit', $t));
        $this->assertNotSoftDeleted($t);
    }

    public function test_edit_validates_unique_email_and_role(): void
    {
        $t = User::factory()->teacher()->create();
        $other = User::factory()->teacher()->create();

        $this->put(route('teachers.update', $t), ['first_name' => 'A', 'last_name_paternal' => 'B', 'email' => $other->email, 'role' => 'teacher'])
            ->assertSessionHasErrors('email');
        $this->put(route('teachers.update', $t), ['first_name' => 'A', 'last_name_paternal' => 'B', 'email' => $t->email, 'role' => 'root'])
            ->assertSessionHasErrors('role');
        $this->put(route('teachers.update', $t), ['first_name' => 'A', 'last_name_paternal' => 'B', 'email' => $t->email, 'role' => 'admin'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($t->fresh()->isAdmin());
    }

    public function test_teacher_cannot_manage_accounts(): void
    {
        $this->actingAs(User::factory()->teacher()->create());
        $this->get(route('teachers.index'))->assertForbidden();
        $this->post(route('teachers.store'), [])->assertForbidden();
    }
}
