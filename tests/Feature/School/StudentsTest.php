<?php

namespace Tests\Feature\School;

use App\Livewire\StudentsIndex;
use App\Models\Course;
use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_open_the_student_list(): void
    {
        Student::factory()->create(['first_name' => 'Ximena', 'last_name_paternal' => 'Ortega', 'last_name_maternal' => 'Ruiz']);

        $this->actingAs(User::factory()->teacher()->create())->get(route('students.index'))
            ->assertOk()->assertSeeLivewire(StudentsIndex::class)->assertSee('Ortega Ruiz, Ximena')
            ->assertDontSee('Importar CSV');

        $this->actingAs(User::factory()->admin()->create())->get(route('students.index'))
            ->assertOk()->assertSee('Importar CSV');
    }

    public function test_search_and_grade_group_filters(): void
    {
        $g23 = Group::create(['grade' => 2, 'number' => 3]);
        $g11 = Group::create(['grade' => 1, 'number' => 1]);
        Student::factory()->for($g23)->create(['first_name' => 'Ana', 'last_name_paternal' => 'Zamora', 'enrollment_number' => 'A-100']);
        Student::factory()->for($g11)->create(['first_name' => 'Beto', 'last_name_paternal' => 'Arias', 'enrollment_number' => 'B-200']);
        Student::factory()->create(['first_name' => 'Ciro', 'last_name_paternal' => 'Mena', 'enrollment_number' => 'C-300']);

        $this->actingAs(User::factory()->teacher()->create());

        Livewire::test(StudentsIndex::class)
            ->assertSeeInOrder(['Arias', 'Mena', 'Zamora'])
            ->set('search', 'zamo')->assertSee('Zamora')->assertDontSee('Arias')
            ->set('search', 'B-200')->assertSee('Arias')->assertDontSee('Zamora')
            ->set('search', '')
            ->call('setGrade', 2)->assertSee('Zamora')->assertDontSee('Arias')->assertDontSee('Mena')
            ->call('setGrade', 2) // quitar filtro
            ->call('setGroup', 0)->assertSee('Mena')->assertDontSee('Zamora');
    }

    public function test_teacher_can_change_group_but_not_delete(): void
    {
        $group = Group::create(['grade' => 3, 'number' => 2]);
        $student = Student::factory()->create();
        $teacher = User::factory()->teacher()->create();
        Course::create(['teacher_id' => $teacher->id, 'name' => 'Física'])->students()->attach($student);

        $this->actingAs($teacher);

        Livewire::test(StudentsIndex::class)
            ->call('select', $student->id)
            ->set('groupId', $group->id)
            ->call('saveGroup')
            ->assertSet('notice', "{$student->full_name} ahora está en 3° grado, grupo 2.")
            ->call('delete')->assertForbidden();

        $this->assertSame($group->id, $student->fresh()->group_id);
        $this->assertNotSoftDeleted($student);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.group_changed', 'subject_id' => $student->id]);
    }

    public function test_admin_can_remove_and_restore_a_student(): void
    {
        $student = Student::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(StudentsIndex::class)
            ->call('select', $student->id)->call('delete')
            ->assertSet('selected', null);
        $this->assertSoftDeleted($student);

        Livewire::test(StudentsIndex::class)
            ->call('toggleTrashed')->assertSee($student->list_name)
            ->call('select', $student->id)->call('restore');
        $this->assertNotSoftDeleted($student);
    }

    public function test_teacher_cannot_see_removed_students(): void
    {
        $this->actingAs(User::factory()->teacher()->create());

        Livewire::test(StudentsIndex::class)->call('toggleTrashed')->assertForbidden();
        Livewire::withQueryParams(['bajas' => '1'])->test(StudentsIndex::class)->assertSet('trashed', false);
    }

    public function test_admin_creates_and_edits_students(): void
    {
        $group = Group::create(['grade' => 1, 'number' => 4]);
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('students.create'))->assertOk();
        $this->post(route('students.store'), [
            'first_name' => '  María   José ', 'last_name_paternal' => 'Luna', 'last_name_maternal' => '',
            'enrollment_number' => '20250042', 'group_id' => $group->id,
        ])->assertRedirect();

        $student = Student::where('enrollment_number', '20250042')->sole();
        $this->assertSame('María José', $student->first_name);
        $this->assertNull($student->last_name_maternal);
        $this->assertSame($group->id, $student->group_id);

        $this->put(route('students.update', $student), [
            'first_name' => 'María José', 'last_name_paternal' => 'Luna', 'last_name_maternal' => 'Treviño',
            'enrollment_number' => '20250042', 'group_id' => null,
        ])->assertRedirect(route('students.index', ['alumno' => $student->id]));
        $this->assertSame('Treviño', $student->fresh()->last_name_maternal);
        $this->assertNull($student->fresh()->group_id);
    }

    public function test_enrollment_number_must_be_unique_even_against_removed_students(): void
    {
        $old = Student::factory()->create(['enrollment_number' => '777']);
        $old->delete();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('students.store'), ['first_name' => 'A', 'last_name_paternal' => 'B', 'enrollment_number' => '777'])
            ->assertSessionHasErrors(['enrollment_number' => 'Ya hay un alumno con esa matrícula (puede estar dado de baja).']);
    }

    public function test_teacher_cannot_create_or_edit(): void
    {
        $student = Student::factory()->create();
        $this->actingAs(User::factory()->teacher()->create());

        $this->get(route('students.create'))->assertForbidden();
        $this->post(route('students.store'), [])->assertForbidden();
        $this->get(route('students.edit', $student))->assertForbidden();
        $this->put(route('students.update', $student), [])->assertForbidden();
    }
}
