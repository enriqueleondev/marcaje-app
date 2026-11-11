<?php

namespace Tests\Feature\RollCall;

use App\Livewire\CourseEditor;
use App\Models\Course;
use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CoursesTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_creates_a_class_and_its_group_is_added(): void
    {
        $group = Group::create(['grade' => 1, 'number' => 3]);
        Student::factory()->count(3)->for($group)->create();
        Student::factory()->create();
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->post(route('courses.store'), ['name' => 'Biología I', 'group_id' => $group->id, 'room' => 'Lab 2'])
            ->assertRedirect();

        $course = Course::sole();
        $this->assertSame($teacher->id, $course->teacher_id);
        $this->assertSame(3, $course->students()->count());
        $this->actingAs($teacher)->get(route('courses.index'))->assertSee('Biología I')->assertSee('Sin horario');
    }

    public function test_admin_creates_a_class_for_a_teacher(): void
    {
        $teacher = User::factory()->teacher()->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('courses.store'), ['name' => 'Química'])->assertSessionHasErrors('teacher_id');
        $this->post(route('courses.store'), ['name' => 'Química', 'teacher_id' => $teacher->id])->assertRedirect();
        $this->assertSame($teacher->id, Course::sole()->teacher_id);
    }

    public function test_teachers_only_see_their_own_classes(): void
    {
        $mine = Course::create(['teacher_id' => ($me = User::factory()->teacher()->create())->id, 'name' => 'Mía']);
        $other = Course::create(['teacher_id' => User::factory()->teacher()->create()->id, 'name' => 'Ajena']);

        $this->actingAs($me)->get(route('courses.index'))->assertSee('Mía')->assertDontSee('Ajena');
        $this->get(route('courses.show', $other))->assertForbidden();
        $this->get(route('courses.show', $mine))->assertOk();
    }

    public function test_schedules_and_students_in_the_editor(): void
    {
        $me = User::factory()->teacher()->create();
        $course = Course::create(['teacher_id' => $me->id, 'name' => 'Física']);
        $g = Group::create(['grade' => 2, 'number' => 1]);
        $a = Student::factory()->for($g)->create(['last_name_paternal' => 'Arias']);
        $b = Student::factory()->for($g)->create(['last_name_paternal' => 'Bravo']);
        $this->actingAs($me);

        Livewire::test(CourseEditor::class, ['course' => $course])
            ->set('weekday', 1)->set('startsAt', '07:50')->set('endsAt', '08:40')->call('addSchedule')
            ->assertHasNoErrors()
            ->set('startsAt', '08:00')->set('endsAt', '09:00')->call('addSchedule')
            ->assertHasErrors('startsAt')
            ->set('startsAt', '09:00')->set('endsAt', '08:00')->call('addSchedule')
            ->assertHasErrors('endsAt')
            ->call('setGrade', 2)->call('setGroupNumber', 1)
            ->assertSee('Arias')->assertSee('Bravo')
            ->set('toAdd', [$a->id])->call('addSelected')
            ->assertSet('notice', 'Se agregó 1 alumno.')
            ->call('addAllShown')
            ->call('removeStudent', $a->id);

        $this->assertSame(1, $course->schedules()->count());
        $this->assertSame([$b->id], $course->students()->pluck('students.id')->all());
    }

    public function test_other_teachers_cannot_use_the_editor(): void
    {
        $course = Course::create(['teacher_id' => User::factory()->teacher()->create()->id, 'name' => 'X']);
        $this->actingAs(User::factory()->teacher()->create());

        Livewire::test(CourseEditor::class, ['course' => $course])->assertForbidden();
    }
}
