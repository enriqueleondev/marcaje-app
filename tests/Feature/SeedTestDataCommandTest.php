<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedTestDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_fictitious_data(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->artisan('marcaje:datos-prueba', ['--alumnos' => 3, '--dias' => 2, '--yes' => true, '--sin-recrear' => true])->assertSuccessful();

        $this->assertSame(36, Student::count());
        $this->assertSame(16, User::count());
        $this->assertSame(72, Course::count());
        $this->assertTrue(Student::doesntHave('activeQr')->doesntExist());
        $this->assertSame('Plantel de Prueba', Setting::institutionName());
    }

    public function test_refuses_to_run_against_a_real_domain(): void
    {
        config(['app.url' => 'https://escuela-real.mx']);
        User::factory()->create();

        $this->artisan('marcaje:datos-prueba', ['--yes' => true])->assertFailed();
        $this->assertSame(1, User::count(), 'no debió borrar nada');
    }
}
