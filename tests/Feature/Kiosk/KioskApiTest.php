<?php

namespace Tests\Feature\Kiosk;

use App\Models\Entry;
use App\Models\Group;
use App\Models\Kiosk;
use App\Models\Setting;
use App\Models\Student;
use App\Services\Credentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KioskApiTest extends TestCase
{
    use RefreshDatabase;

    private Kiosk $kiosk;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kiosk = new Kiosk(['name' => 'Kiosco 1', 'location' => 'Acceso principal']);
        $this->token = $this->kiosk->issueToken();
    }

    private function scan(array $scans, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->postJson(route('kiosk.api.scans'), ['scans' => $scans]);
    }

    private function one(string $code, ?string $at = null, ?string $uuid = null): array
    {
        return ['uuid' => $uuid ?? (string) Str::uuid(), 'code' => $code, 'scanned_at' => $at ?? now()->toIso8601String()];
    }

    public function test_requires_a_valid_token(): void
    {
        $this->getJson(route('kiosk.api.state'))->assertUnauthorized();
        $this->withToken('mk_'.str_repeat('x', 40))->getJson(route('kiosk.api.state'))->assertUnauthorized();
        $this->withToken($this->token)->getJson(route('kiosk.api.state'))->assertOk()
            ->assertJsonPath('kiosk.name', 'Kiosco 1')
            ->assertJsonPath('duplicate_window_minutes', 5);
        $this->assertNotNull($this->kiosk->fresh()->last_seen_at);
    }

    public function test_revoked_or_regenerated_tokens_stop_working(): void
    {
        $new = $this->kiosk->issueToken();
        $this->withToken($this->token)->getJson(route('kiosk.api.state'))->assertUnauthorized();
        $this->withToken($new)->getJson(route('kiosk.api.state'))->assertOk();

        $this->kiosk->forceFill(['revoked_at' => now()])->save();
        $this->withToken($new)->getJson(route('kiosk.api.state'))->assertUnauthorized();
    }

    public function test_records_qr_and_nfc_entries(): void
    {
        $group = Group::create(['grade' => 2, 'number' => 3]);
        $student = Student::factory()->for($group)->create(['first_name' => 'Ximena', 'last_name_paternal' => 'Ortega', 'last_name_maternal' => 'Ruiz']);
        $other = Student::factory()->create();
        app(Credentials::class)->linkNfc($other, '04:A2:3F:1B');

        $this->scan([$this->one(strtolower($student->activeQr->display)), $this->one('04A23F1B')])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.0.method', 'QR')
            ->assertJsonPath('results.0.student.n', 'Ximena Ortega Ruiz')
            ->assertJsonPath('results.0.student.g', '2° 3')
            ->assertJsonPath('results.0.student.i', 'XO')
            ->assertJsonPath('results.1.status', 'ok')
            ->assertJsonPath('results.1.method', 'NFC')
            ->assertJsonPath('today_count', 2);

        $this->assertSame(2, Entry::count());
        $this->assertSame($this->kiosk->id, Entry::first()->kiosk_id);
    }

    public function test_same_uuid_is_recorded_once(): void
    {
        $student = Student::factory()->create();
        $scan = $this->one($student->activeQr->value);

        $this->scan([$scan])->assertJsonPath('results.0.status', 'ok');
        $this->scan([$scan])->assertJsonPath('results.0.status', 'ok');

        $this->assertSame(1, Entry::count());
    }

    public function test_ignores_the_same_student_within_the_window(): void
    {
        $student = Student::factory()->create();
        $code = $student->activeQr->value;
        $t = now()->subHour()->startOfMinute(); // en el pasado: una hora futura se ajustaría a "ahora"

        $this->scan([$this->one($code, $t->toIso8601String())])->assertJsonPath('results.0.status', 'ok');
        $this->scan([$this->one($code, $t->copy()->addMinutes(4)->toIso8601String())])
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.0.previous_time', $t->copy()->timezone(Setting::get('timezone'))->format('H:i'));
        $this->scan([$this->one($code, $t->copy()->addMinutes(6)->toIso8601String())])->assertJsonPath('results.0.status', 'ok');

        $this->assertSame(2, Entry::count());
    }

    public function test_window_is_configurable(): void
    {
        Setting::put('duplicate_window_minutes', 1);
        $student = Student::factory()->create();
        $t = now()->subMinutes(10);

        $this->scan([$this->one($student->activeQr->value, $t->toIso8601String())]);
        $this->scan([$this->one($student->activeQr->value, $t->copy()->addMinutes(2)->toIso8601String())])->assertJsonPath('results.0.status', 'ok');
    }

    public function test_unknown_revoked_and_removed_credentials(): void
    {
        $student = Student::factory()->create();
        $old = $student->activeQr->value;
        app(Credentials::class)->regenerateQr($student);
        $removed = Student::factory()->create();
        $removedCode = $removed->activeQr->value;
        $removed->delete();

        $this->scan([$this->one('ZZZZ9999'), $this->one($old), $this->one($removedCode)])
            ->assertJsonPath('results.0.status', 'unknown')
            ->assertJsonPath('results.1.status', 'unknown')
            ->assertJsonPath('results.2.status', 'unknown');

        $this->assertSame(0, Entry::count());
    }

    public function test_offline_scans_keep_device_time_within_limits(): void
    {
        $student = Student::factory()->create();
        $a = Student::factory()->create();
        $b = Student::factory()->create();
        $earlier = now()->subHours(3)->startOfMinute();

        $this->scan([
            $this->one($student->activeQr->value, $earlier->toIso8601String()),
            $this->one($a->activeQr->value, now()->addHour()->toIso8601String()),
            $this->one($b->activeQr->value, now()->subDays(8)->toIso8601String()),
            $this->one($b->activeQr->value, 'no es fecha'),
        ])
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.1.status', 'ok')
            ->assertJsonPath('results.2.status', 'invalid')
            ->assertJsonPath('results.3.status', 'invalid');

        $this->assertTrue(Entry::where('student_id', $student->id)->sole()->scanned_at->eq($earlier));
        $this->assertTrue(Entry::where('student_id', $a->id)->sole()->scanned_at->lte(now()->addMinute()));
    }

    public function test_validates_the_batch(): void
    {
        $this->scan([])->assertUnprocessable();
        $this->scan([['uuid' => 'x', 'code' => 'ABCD']])->assertJsonPath('results.0.status', 'invalid');
        $this->scan(array_fill(0, 201, $this->one('ABCD')))->assertUnprocessable();
    }

    public function test_roster_contains_only_active_credentials_and_supports_versions(): void
    {
        $group = Group::create(['grade' => 1, 'number' => 2]);
        $s = Student::factory()->for($group)->create();
        $revoked = $s->activeQr->value;
        app(Credentials::class)->regenerateQr($s);
        $gone = Student::factory()->create();
        $gone->delete();

        $r = $this->withToken($this->token)->getJson(route('kiosk.api.roster'))->assertOk();
        $values = collect($r->json('credentials'))->pluck('v');

        $this->assertTrue($values->contains($s->fresh()->activeQr->value));
        $this->assertFalse($values->contains($revoked));
        $this->assertSame([$s->id], collect($r->json('students'))->pluck('id')->all());
        $this->assertSame('1° 2', $r->json('students.0.g'));

        $version = $r->json('version');
        $this->withToken($this->token)->getJson(route('kiosk.api.roster', ['version' => $version]))->assertJsonPath('unchanged', true);

        app(Credentials::class)->linkNfc($s, 'AABBCCDD');
        $this->assertNotSame($version, $this->withToken($this->token)->getJson(route('kiosk.api.roster', ['version' => $version]))->json('version'));
    }

    public function test_state_lists_last_five_entries_of_this_kiosk_today(): void
    {
        $students = Student::factory()->count(6)->create();
        foreach ($students as $i => $s) {
            $this->scan([$this->one($s->activeQr->value, now()->subMinutes(10 - $i)->toIso8601String())]);
        }

        $recent = $this->withToken($this->token)->getJson(route('kiosk.api.state'))->assertJsonPath('today_count', 6)->json('recent');
        $this->assertCount(5, $recent);
        $this->assertSame($students[5]->id, $recent[0]['student']['id']);
    }
}
