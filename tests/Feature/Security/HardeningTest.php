<?php

namespace Tests\Feature\Security;

use App\Models\AttendanceMark;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Kiosk;
use App\Models\Student;
use App\Models\User;
use App\Services\RollCalls;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_sent(): void
    {
        $r = $this->get(route('login'))->assertOk();

        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $r->assertHeader('X-Frame-Options', 'DENY');
        $r->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('camera=()', $r->headers->get('Permissions-Policy'));
        $csp = $r->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'/", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", str_replace("style-src-attr 'unsafe-inline'", '', $csp));

        // El script en línea lleva el mismo nonce que la cabecera
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertStringContainsString('nonce="'.$m[1].'"', $r->getContent());
    }

    public function test_kiosk_csp_has_no_unsafe_eval_and_hsts_only_over_https(): void
    {
        $kiosk = $this->get(route('kiosk.show'));
        $this->assertStringNotContainsString('unsafe-eval', $kiosk->headers->get('Content-Security-Policy'));
        $this->assertFalse($kiosk->headers->has('Strict-Transport-Security'));

        $this->get('https://localhost/entrar')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_no_inline_event_handlers_in_views(): void
    {
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $f) {
            if ($f->isFile() && preg_match('/\son(click|submit|change|load|input|error)\s*=/i', file_get_contents($f->getPathname()))) {
                $hits[] = $f->getFilename();
            }
        }
        $this->assertSame([], $hits, 'La CSP bloquea manejadores en línea; usa data-* y app.js');
    }

    public function test_login_locks_after_failures_per_account_and_per_ip(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 5) as $_) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'mal']);
        }
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors(['email' => 'Demasiados intentos. Vuelve a intentar en 15 minutos.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.locked']);
        $this->assertSame(5, AuditLog::where('action', 'auth.failed')->count());

        // Rociar muchas cuentas distintas desde la misma IP también se frena
        foreach (range(1, 20) as $i) {
            $this->post(route('login.store'), ['email' => "nadie{$i}@x.mx", 'password' => 'mal']);
        }
        $fresh = User::factory()->create();
        $this->post(route('login.store'), ['email' => $fresh->email, 'password' => 'password123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Pasados 15 minutos se puede volver a entrar
        $this->travel(16)->minutes();
        $this->post(route('login.store'), ['email' => $fresh->email, 'password' => 'password123'])->assertRedirect(route('home'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'subject_id' => $fresh->id]);
    }

    public function test_kiosk_scans_endpoint_is_rate_limited(): void
    {
        $kiosk = new Kiosk(['name' => 'K']);
        $token = $kiosk->issueToken();

        foreach (range(1, 240) as $_) {
            $this->withToken($token)->getJson(route('kiosk.api.state'))->assertOk();
        }
        $this->withToken($token)->postJson(route('kiosk.api.scans'), ['scans' => [['uuid' => (string) Str::uuid(), 'code' => 'ABCD']]])->assertStatus(429);
    }

    public function test_kiosk_tokens_are_stored_hashed(): void
    {
        $kiosk = new Kiosk(['name' => 'K']);
        $token = $kiosk->issueToken();

        $this->assertDatabaseMissing('kiosks', ['token_hash' => $token]);
        $this->assertSame(hash('sha256', $token), $kiosk->fresh()->token_hash);
        $this->assertArrayNotHasKey('token_hash', $kiosk->fresh()->toArray());
    }

    public function test_csv_export_neutralizes_formulas(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:30', 'America/Mexico_City'));
        $admin = User::factory()->admin()->create();
        $evil = Student::factory()->create(['first_name' => '=HYPERLINK("http://x.example","clic")', 'last_name_paternal' => '+Suma', 'last_name_maternal' => null]);
        $course = Course::create(['teacher_id' => User::factory()->teacher()->create()->id, 'name' => '@Clase']);
        $course->students()->attach($evil);
        $rc = app(RollCalls::class)->open($course->schedules()->create(['weekday' => 2, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']), '2026-11-10');
        AttendanceMark::where('roll_call_id', $rc->id)->update(['status' => 'present']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:30', 'America/Mexico_City')); // la clase ya terminó

        $csv = $this->actingAs($admin)->get(route('reports.export', ['desde' => '2026-11-10', 'hasta' => '2026-11-10']))->streamedContent();
        $this->assertStringContainsString("\"'+Suma, =HYPERLINK(", $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|,)[=+@-]/m', preg_replace('/^\xEF\xBB\xBF/', '', $csv));

        $csv = $this->get(route('reports.export', ['ver' => 'clases', 'desde' => '2026-11-10', 'hasta' => '2026-11-10']))->streamedContent();
        $this->assertStringContainsString("'@Clase", $csv);
    }

    public function test_csv_upload_rejects_non_csv_and_large_files(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('students.import.store'), ['file' => UploadedFile::fake()->create('alumnos.php', 10, 'application/x-php')])->assertSessionHasErrors('file');
        $this->post(route('students.import.store'), ['file' => UploadedFile::fake()->create('alumnos.csv', 3000, 'text/csv')])->assertSessionHasErrors('file');
    }

    public function test_csrf_protection_is_in_the_web_group_and_not_needed_by_the_kiosk_api(): void
    {
        // Laravel omite CSRF al correr pruebas, así que se verifica la configuración.
        $web = app('router')->getMiddlewareGroups()['web'] ?? [];
        $this->assertNotEmpty(array_filter($web, fn ($m) => str_contains($m, 'Csrf') || str_contains($m, 'RequestForgery')), 'falta la protección CSRF en el grupo web');

        // La API del kiosco no usa cookies (token Bearer), así que no es vulnerable a CSRF.
        $this->getJson('/api/kiosco/estado')->assertUnauthorized()->assertCookieMissing(config('session.cookie'));
    }

    public function test_production_errors_show_no_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_boom', fn () => throw new \RuntimeException('secreto-interno /home/app/.env'));

        $r = $this->get('/_boom')->assertStatus(500);
        $this->assertStringNotContainsString('secreto-interno', $r->getContent());
        $this->assertStringNotContainsString('RuntimeException', $r->getContent());
        $this->assertStringNotContainsString('.env', $r->getContent());
    }

    public function test_cookies_are_hardened_in_production(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertStringContainsString('APP_DEBUG=false', file_get_contents(base_path('.env.production.example')));

        // Con APP_ENV=production, la cookie de sesión es segura y la sesión va cifrada aunque falten las variables.
        $prev = getenv('APP_ENV');
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        $secure = getenv('SESSION_SECURE_COOKIE');
        putenv('SESSION_SECURE_COOKIE');
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
        $encrypt = getenv('SESSION_ENCRYPT');
        putenv('SESSION_ENCRYPT');
        unset($_ENV['SESSION_ENCRYPT'], $_SERVER['SESSION_ENCRYPT']);
        try {
            $session = require config_path('session.php');
        } finally {
            putenv("APP_ENV={$prev}");
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $prev;
            if ($secure !== false) {
                putenv("SESSION_SECURE_COOKIE={$secure}");
            }
            if ($encrypt !== false) {
                putenv("SESSION_ENCRYPT={$encrypt}");
            }
        }
        $this->assertTrue($session['secure']);
        $this->assertTrue($session['encrypt']);
    }

    public function test_dotenv_and_storage_are_not_under_the_public_directory(): void
    {
        $this->assertFileDoesNotExist(public_path('.env'));
        $this->assertDirectoryDoesNotExist(public_path('storage'));
        $this->assertStringContainsString('RewriteRule ^ index.php', file_get_contents(public_path('.htaccess')));
    }
}
