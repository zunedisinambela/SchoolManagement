<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_open_the_panel(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/admin')->assertSuccessful();
    }

    public function test_a_non_admin_is_denied(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    /**
     * An unknown panel id must not inherit the admin rule.
     */
    public function test_access_is_denied_for_an_unrecognised_panel(): void
    {
        $admin = User::factory()->superAdmin()->create();

        // A panel's id() may only be set once, so a stub stands in for the
        // hypothetical second panel rather than a real registered one.
        $otherPanel = Mockery::mock(Panel::class);
        $otherPanel->shouldReceive('getId')->andReturn('siswa');

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($admin->canAccessPanel($otherPanel));
    }
}
