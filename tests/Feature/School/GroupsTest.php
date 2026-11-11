<?php

namespace Tests\Feature\School;

use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_manages_groups(): void
    {
        $this->actingAs(User::factory()->teacher()->create())->get(route('groups.index'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('groups.index'))
            ->assertOk()->assertSee('Crear los 12 grupos que faltan');
    }

    public function test_creates_default_groups_from_settings_once(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('groups.defaults'))->assertSessionHas('status', 'Se crearon 12 grupos.');
        $this->actingAs($admin)->post(route('groups.defaults'))->assertSessionHas('status', 'Se crearon 0 grupos.');

        $this->assertSame(12, Group::count());
        $this->assertDatabaseHas('groups', ['grade' => 3, 'number' => 4]);
    }

    public function test_label_update_and_delete_rules(): void
    {
        $admin = User::factory()->admin()->create();
        $empty = Group::create(['grade' => 1, 'number' => 1]);
        $full = Group::create(['grade' => 1, 'number' => 2]);
        Student::factory()->for($full)->create();

        $this->actingAs($admin)->patch(route('groups.update', $empty), ['label' => 'Matutino'])->assertRedirect();
        $this->assertSame('Matutino', $empty->fresh()->label);

        $this->delete(route('groups.destroy', $full))->assertSessionHasErrors('group');
        $this->assertModelExists($full);

        $this->delete(route('groups.destroy', $empty))->assertSessionHasNoErrors();
        $this->assertModelMissing($empty);
    }
}
