<?php

namespace Tests\Feature\School;

use App\Models\Group;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private function upload(string $content, string $name = 'alumnos.csv')
    {
        return $this->post(route('students.import.store'), ['file' => UploadedFile::fake()->createWithContent($name, $content)]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_imports_new_students_and_updates_existing_ones(): void
    {
        $existing = Student::factory()->create(['enrollment_number' => '20250002', 'first_name' => 'Viejo']);

        $this->upload("\xEF\xBB\xBFmatricula,nombre,apellido_paterno,apellido_materno,grado,grupo\n".
            "20250001,Ximena,Ortega,Ruiz,1,1\n".
            "20250002,Diego,Hernández,,2°,3\n".
            "\n".
            "20250003,Ana,Paz,,,\n".
            "20250004,Luis,Mar,,3.º,1\n")
            ->assertRedirect(route('students.index'))
            ->assertSessionHas('status', 'Importación lista: 3 alumnos nuevos y 1 actualizado.');

        $this->assertSame(4, Student::count());
        $this->assertSame('Diego', $existing->fresh()->first_name);
        $this->assertSame('2° 3', $existing->fresh()->group->short_name);
        $this->assertNull(Student::where('enrollment_number', '20250003')->sole()->group_id);
        $this->assertSame(3, Group::count(), 'crea los grupos válidos que falten');
        $this->assertDatabaseHas('student_imports', ['rows_total' => 4, 'rows_created' => 3, 'rows_updated' => 1]);
    }

    public function test_accepts_excel_semicolons_windows1252_and_header_variants(): void
    {
        $csv = mb_convert_encoding("Matrícula;Nombre(s);Apellido Paterno;Apellido Materno;Grado;Grupo\r\n9001;José;Núñez;Peña;3;4\r\n", 'Windows-1252', 'UTF-8');

        $this->upload($csv)->assertSessionHasNoErrors()->assertRedirect(route('students.index'));

        $s = Student::sole();
        $this->assertSame('José', $s->first_name);
        $this->assertSame('Núñez', $s->last_name_paternal);
    }

    public function test_nothing_is_saved_when_any_row_has_errors(): void
    {
        $this->upload("matricula,nombre,apellido_paterno,grado,grupo\n".
            "1,Ana,Paz,1,1\n".
            ",Beto,Ríos,1,1\n".
            "1,Ciro,Mena,1,1\n".
            "4,Dora,,9,1\n".
            "5,Eva,Sol,dos,1\n")
            ->assertRedirect()
            ->assertSessionHas('import_errors', fn ($errors) => collect($errors)->pluck('line')->all() === [3, 4, 5, 6]
                && str_contains($errors[1]['message'], 'se repite (también en la línea 2)')
                && str_contains($errors[2]['message'], 'Falta el apellido paterno; el grupo 9° 1 no existe'));

        $this->assertSame(0, Student::count());
        $this->assertNotNull(StudentImport::sole()->errors);
    }

    public function test_missing_columns_and_empty_files(): void
    {
        $this->upload("nombre,grado\nAna,1\n")->assertSessionHas('import_errors', fn ($e) => str_contains($e[0]['message'], 'Faltan columnas: matricula, apellido_paterno'));
        $this->upload("matricula,nombre,apellido_paterno\n")->assertSessionHas('import_errors', fn ($e) => str_contains($e[0]['message'], 'solo encabezados'));
        $this->post(route('students.import.store'), [])->assertSessionHasErrors('file');
    }

    public function test_reimporting_restores_removed_students(): void
    {
        $removed = Student::factory()->create(['enrollment_number' => '55']);
        $removed->delete();

        $this->upload("matricula,nombre,apellido_paterno\n55,Rosa,Vera\n")->assertRedirect(route('students.index'));

        $this->assertNotSoftDeleted($removed);
        $this->assertSame('Rosa', $removed->fresh()->first_name);
    }

    public function test_template_download_and_page(): void
    {
        $this->get(route('students.import'))->assertOk()->assertSee('Descargar plantilla');

        $response = $this->get(route('students.import.template'))->assertOk();
        $this->assertStringContainsString('matricula,nombre,apellido_paterno,apellido_materno,grado,grupo', $response->streamedContent());
    }

    public function test_teacher_cannot_import(): void
    {
        $this->actingAs(User::factory()->teacher()->create());
        $this->get(route('students.import'))->assertForbidden();
        $this->upload("matricula,nombre,apellido_paterno\n1,A,B\n")->assertForbidden();
    }
}
