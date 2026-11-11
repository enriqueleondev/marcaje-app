<?php

namespace Tests\Feature\Kiosk;

use App\Enums\KioskReadMode;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskCameraModeTest extends TestCase
{
    use RefreshDatabase;

    private function kiosk(string $name = 'Kiosco 1'): array
    {
        $k = new Kiosk(['name' => $name]);
        $token = $k->issueToken();

        return [$k, $token];
    }

    public function test_new_kiosks_read_with_usb_by_default(): void
    {
        [$k] = $this->kiosk();
        $this->assertSame(KioskReadMode::Usb, $k->fresh()->read_mode);
    }

    public function test_admin_sets_the_read_mode_per_kiosk_in_settings(): void
    {
        [$k1] = $this->kiosk('Kiosco 1');
        [$k2] = $this->kiosk('Kiosco 2');
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('settings.edit'))->assertOk()->assertSee('Kioscos: modo de lectura')->assertSee('Cámara')->assertSee('Ambos');
        $this->put(route('settings.kiosks'), ['modes' => [$k1->id => 'camera', $k2->id => 'both']])->assertSessionHas('status');

        $this->assertSame(KioskReadMode::Camera, $k1->fresh()->read_mode);
        $this->assertSame(KioskReadMode::Both, $k2->fresh()->read_mode);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kiosks.read_mode_updated']);
        $this->get(route('kiosks.index'))->assertSee('Cámara')->assertSee('Ambos');

        $this->put(route('settings.kiosks'), ['modes' => [$k1->id => 'laser']])->assertSessionHasErrors('modes.'.$k1->id);
        $this->assertSame(KioskReadMode::Camera, $k1->fresh()->read_mode);
    }

    public function test_the_kiosk_receives_its_read_mode(): void
    {
        [$k, $token] = $this->kiosk();
        $k->update(['read_mode' => 'both']);

        $this->withToken($token)->getJson(route('kiosk.api.state'))->assertJsonPath('kiosk.read_mode', 'both');
    }

    public function test_camera_is_allowed_only_on_the_kiosk(): void
    {
        $this->assertStringContainsString('camera=(self)', $this->get(route('kiosk.show'))->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('camera=()', $this->get(route('login'))->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('camera=()', $this->getJson('/api/kiosco/estado')->headers->get('Permissions-Policy'));
    }

    public function test_kiosk_page_has_the_camera_box_and_strict_csp(): void
    {
        $r = $this->get(route('kiosk.show'))->assertOk()
            ->assertSee('Centra tu código QR aquí')
            ->assertSee('La imagen no se guarda ni se envía');
        $this->assertStringNotContainsString('unsafe-eval', $r->headers->get('Content-Security-Policy'));
    }

    public function test_teachers_cannot_change_kiosk_modes(): void
    {
        [$k] = $this->kiosk();
        $this->actingAs(User::factory()->teacher()->create());

        $this->put(route('settings.kiosks'), ['modes' => [$k->id => 'camera']])->assertForbidden();
        $this->assertSame(KioskReadMode::Usb, $k->fresh()->read_mode);
    }
}
