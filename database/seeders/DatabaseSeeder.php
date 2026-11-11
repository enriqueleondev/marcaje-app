<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos de desarrollo. En producción la primera cuenta se crea con
 * `php artisan marcaje:crear-admin`, nunca con este seeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('No se siembran cuentas de prueba en producción. Usa php artisan marcaje:crear-admin.');

            return;
        }

        User::factory()->admin()->create([
            'first_name' => 'Gabriela',
            'last_name_paternal' => 'Solís',
            'last_name_maternal' => null,
            'email' => 'direccion@example.com',
        ]);

        $teacher = User::factory()->teacher()->create([
            'first_name' => 'Ricardo',
            'last_name_paternal' => 'Ibarra',
            'last_name_maternal' => 'Luna',
            'email' => 'docente@example.com',
        ]);

        foreach (Group::grades() as $grade) {
            foreach (Group::numbers() as $number) {
                $group = Group::create(['grade' => $grade, 'number' => $number]);
                Student::factory()->count(8)->for($group)->create();
            }
        }
        Student::factory()->count(3)->create(); // sin grupo

        // Clases de ejemplo del docente, de lunes a viernes.
        foreach ([['Biología I', 1, 1, 'Laboratorio 2', '07:00', '07:50'], ['Química II', 2, 3, 'Aula B-14', '08:00', '08:50']] as [$name, $grade, $number, $room, $start, $end]) {
            $group = Group::where('grade', $grade)->where('number', $number)->first();
            $course = Course::create(['teacher_id' => $teacher->id, 'name' => $name, 'group_id' => $group->id, 'room' => $room]);
            $course->students()->attach($group->students()->pluck('id'));
            foreach (range(1, 5) as $day) {
                $course->schedules()->create(['weekday' => $day, 'starts_at' => "{$start}:00", 'ends_at' => "{$end}:00"]);
            }
        }
    }
}
