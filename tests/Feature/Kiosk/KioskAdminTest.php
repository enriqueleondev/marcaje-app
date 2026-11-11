<?php

namespace Tests\Feature\Kiosk;

use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_a_kiosk_and_sees_the_pairing_code_once(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('kiosks.store'), ['name' => 'Kiosco 1', 'location' => 'Acceso principal'])->assertRedirect();
        $kiosk = Kiosk::sole();
        $token = session('kiosk_token');

        $this->assertMatchesRegularExpression('/^mk_[A-Za-z0-9]{40}$/', $token);
        $this->assertSame(hash('sha256', $token), $kiosk->token_hash);
        $this->get(route('kiosks.show', $kiosk))->assertSee($token)->assertSee('Vincula la tableta');
        $this->get(route('kiosks.show', $kiosk))->assertDontSee($token);
        $this->get(route('kiosks.index'))->assertSee('Esperando vinculación');
    }

    public function test_regenerate_and_revoke(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $kiosk = new Kiosk(['name' => 'K']);
        $old = $kiosk->issueToken();

        $this->post(route('kiosks.regenerate', $kiosk))->assertSessionHas('kiosk_token');
        $this->assertNull(Kiosk::findByToken($old));

        $this->post(route('kiosks.revoke', $kiosk));
        $this->assertNotNull($kiosk->fresh()->revoked_at);
        $this->get(route('kiosks.index'))->assertSee('Desvinculado');
    }

    public function test_teachers_cannot_manage_kiosks(): void
    {
        $this->actingAs(User::factory()->teacher()->create());
        $this->get(route('kiosks.index'))->assertForbidden();
        $this->post(route('kiosks.store'), ['name' => 'X'])->assertForbidden();
    }

    public function test_kiosk_page_and_manifest_are_public(): void
    {
        $this->get(route('kiosk.show'))->assertOk()->assertSee('Vincula este kiosco');
        $this->get(route('kiosk.manifest'))->assertOk()->assertJsonPath('display', 'fullscreen')->assertJsonPath('start_url', '/kiosco');
    }
}
