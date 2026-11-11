<?php

namespace Tests\Feature\School;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function valid(array $over = []): array
    {
        return [...['institution_name' => 'Plantel Norte', 'timezone' => 'America/Tijuana', 'duplicate_window_minutes' => 3, 'grades' => 3, 'groups_per_grade' => 5], ...$over];
    }

    public function test_admin_updates_settings_and_they_are_used(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('settings.edit'))->assertOk()->assertSee('Anti-duplicado del kiosco');
        $this->put(route('settings.update'), $this->valid())->assertSessionHas('status', 'Ajustes guardados.');

        $this->assertSame('Plantel Norte', Setting::institutionName());
        $this->assertSame('America/Tijuana', Setting::get('timezone'));
        $this->assertSame(3, Setting::get('duplicate_window_minutes'));
        $this->assertSame(5, Setting::get('groups_per_grade'));
        $this->get(route('home'))->assertSee('Plantel Norte');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        $this->put(route('settings.update'), $this->valid())->assertSessionHas('status', 'No había cambios.');
    }

    public function test_validation(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->put(route('settings.update'), $this->valid(['institution_name' => '', 'timezone' => 'Luna/Base', 'duplicate_window_minutes' => 0, 'grades' => 40]))
            ->assertSessionHasErrors(['institution_name', 'timezone', 'duplicate_window_minutes', 'grades']);
    }

    public function test_teachers_cannot_change_settings(): void
    {
        $this->actingAs(User::factory()->teacher()->create());
        $this->get(route('settings.edit'))->assertForbidden();
        $this->put(route('settings.update'), $this->valid())->assertForbidden();
    }
}
