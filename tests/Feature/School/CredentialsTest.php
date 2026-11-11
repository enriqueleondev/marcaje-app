<?php

namespace Tests\Feature\School;

use App\Enums\CredentialType;
use App\Livewire\StudentsIndex;
use App\Models\Credential;
use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use App\Services\Credentials;
use chillerlan\QRCode\QRCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CredentialsTest extends TestCase
{
    use RefreshDatabase;

    private Credentials $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credentials = app(Credentials::class);
    }

    public function test_every_new_student_gets_a_random_qr_code(): void
    {
        $a = Student::factory()->create();
        $b = Student::factory()->create();

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $a->activeQr->value);
        $this->assertNotSame($a->activeQr->value, $b->activeQr->value);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $a->activeQr->display);
    }

    public function test_random_codes_avoid_ambiguous_characters(): void
    {
        foreach (range(1, 300) as $_) {
            $this->assertDoesNotMatchRegularExpression('/[01OI]/', Credentials::randomCode());
        }
    }

    public function test_regenerating_revokes_the_old_code_and_keeps_history(): void
    {
        $student = Student::factory()->create();
        $old = $student->activeQr;

        $new = $this->credentials->regenerateQr($student);

        $this->assertNotSame($old->value, $new->value);
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertNull($this->credentials->findActiveByScan($old->value));
        $this->assertTrue($this->credentials->findActiveByScan($new->display)->student->is($student));
        $this->assertSame(2, $student->credentials()->count());
    }

    public function test_link_nfc_normalizes_and_finds_by_scan(): void
    {
        $student = Student::factory()->create();

        $nfc = $this->credentials->linkNfc($student, ' 04:a2:3f:1b:5c:80:00 ');

        $this->assertSame('04A23F1B5C8000', $nfc->value);
        $this->assertSame(CredentialType::Nfc, $this->credentials->findActiveByScan('04A23F1B5C8000')->type);
        $this->assertSame($student->id, $this->credentials->findActiveByScan('04-A2-3F-1B-5C-80-00')->student_id);
    }

    public function test_a_sticker_cannot_belong_to_two_students(): void
    {
        $ana = Student::factory()->create(['first_name' => 'Ana', 'last_name_paternal' => 'Paz', 'last_name_maternal' => null]);
        $beto = Student::factory()->create();
        $this->credentials->linkNfc($ana, 'AABBCCDD');

        try {
            $this->credentials->linkNfc($beto, 'aa:bb:cc:dd');
            $this->fail('Debió rechazar la pegatina');
        } catch (ValidationException $e) {
            $this->assertSame('Esa pegatina ya está vinculada a Ana Paz. Quítala de ese alumno primero.', $e->errors()['nfc'][0]);
        }

        $this->credentials->revoke($ana, CredentialType::Nfc);
        $this->assertSame($beto->id, $this->credentials->linkNfc($beto, 'AABBCCDD')->student_id);
    }

    public function test_database_enforces_one_active_value_and_one_active_credential_per_type(): void
    {
        $a = Student::factory()->create();
        $b = Student::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Credential::create(['student_id' => $b->id, 'type' => 'qr', 'value' => $a->activeQr->value]);
    }

    public function test_database_rejects_a_second_active_qr_for_the_same_student(): void
    {
        $a = Student::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Credential::create(['student_id' => $a->id, 'type' => 'qr', 'value' => 'ZZZZZZZZ']);
    }

    public function test_replacing_a_sticker_and_invalid_reads(): void
    {
        $student = Student::factory()->create();
        $first = $this->credentials->linkNfc($student, '11223344');
        $second = $this->credentials->linkNfc($student, '55667788');

        $this->assertNotNull($first->fresh()->revoked_at);
        $this->assertTrue($second->isActive());

        $this->expectException(ValidationException::class);
        $this->credentials->linkNfc($student, '::');
    }

    public function test_qr_values_cannot_be_linked_as_nfc(): void
    {
        $a = Student::factory()->create();
        $b = Student::factory()->create();

        $this->expectException(ValidationException::class);
        $this->credentials->linkNfc($b, $a->activeQr->value);
    }

    public function test_removing_a_student_revokes_credentials_and_restoring_issues_a_new_qr(): void
    {
        $student = Student::factory()->create();
        $oldQr = $student->activeQr->value;
        $this->credentials->linkNfc($student, 'DEADBEEF');

        $student->delete();
        $this->assertNull($this->credentials->findActiveByScan($oldQr));
        $this->assertNull($this->credentials->findActiveByScan('DEADBEEF'));
        $this->assertSame(0, $student->credentials()->active()->count());

        $student->restore();
        $this->assertNotNull($student->fresh()->activeQr);
        $this->assertNotSame($oldQr, $student->fresh()->activeQr->value);
        $this->assertNull($student->fresh()->activeNfc);
    }

    public function test_admin_manages_credentials_from_the_inspector(): void
    {
        $student = Student::factory()->create(['first_name' => 'Iker']);
        $oldQr = $student->activeQr->value;
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(StudentsIndex::class)
            ->call('select', $student->id)
            ->assertSee($student->activeQr->display)
            ->assertSee('Vincular NFC')
            ->call('startNfc')->assertSet('linkingNfc', true)
            ->set('nfcInput', '04:11:22:33:44:55:66')->call('linkNfc')
            ->assertSet('linkingNfc', false)
            ->assertSet('notice', 'Pegatina NFC 04112233445566 vinculada a Iker.')
            ->call('regenerateQr')
            ->assertSee('Credenciales anteriores');

        $this->assertSame('04112233445566', $student->fresh()->activeNfc->value);
        $this->assertNotSame($oldQr, $student->fresh()->activeQr->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'credential.qr_regenerated', 'subject_id' => $student->id]);
    }

    public function test_inspector_shows_link_errors(): void
    {
        $owner = Student::factory()->create(['first_name' => 'Ana', 'last_name_paternal' => 'Paz', 'last_name_maternal' => null]);
        $this->credentials->linkNfc($owner, 'CAFE1234');
        $other = Student::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(StudentsIndex::class)
            ->call('select', $other->id)->call('startNfc')
            ->set('nfcInput', 'CAFE1234')->call('linkNfc')
            ->assertHasErrors(['nfcInput'])
            ->assertSee('Esa pegatina ya está vinculada a Ana Paz.')
            ->assertSet('linkingNfc', true);
    }

    public function test_teachers_do_not_see_or_manage_credentials(): void
    {
        $student = Student::factory()->create();
        $this->actingAs(User::factory()->teacher()->create());

        Livewire::test(StudentsIndex::class)
            ->call('select', $student->id)
            ->assertDontSee($student->activeQr->display)
            ->call('regenerateQr')->assertForbidden();

        $this->get(route('students.qr.png', $student))->assertForbidden();
        $this->get(route('credentials.index'))->assertForbidden();
    }

    public function test_png_download_is_a_scannable_qr(): void
    {
        $student = Student::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('students.qr.png', $student))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame($student->activeQr->value, (string) (new QRCode)->readFromBlob($response->getContent()));
        $this->get(route('students.qr.svg', $student))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_print_sheets(): void
    {
        $group = Group::create(['grade' => 2, 'number' => 1]);
        $in = Student::factory()->for($group)->create();
        $out = Student::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('credentials.print', ['grupo' => $group->id]))->assertOk()
            ->assertSee($in->activeQr->display)->assertDontSee($out->activeQr->display);
        $this->get(route('credentials.print', ['alumno' => $out->id]))->assertOk()->assertSee($out->activeQr->display);
        $this->get(route('credentials.print', ['grupo' => 'sin']))->assertOk()->assertSee($out->activeQr->display);
        $this->get(route('credentials.print'))->assertNotFound();
    }

    public function test_credentials_page_and_generate_missing(): void
    {
        $student = Student::factory()->create();
        $this->credentials->revoke($student, CredentialType::Qr);
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('credentials.index'))->assertOk()->assertSee('Generar el QR que falta');
        $this->post(route('credentials.generate'))->assertSessionHas('status', 'Se generó 1 código QR.');
        $this->assertNotNull($student->fresh()->activeQr);
    }
}
