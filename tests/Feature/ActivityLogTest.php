<?php

namespace Tests\Feature;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    public function test_login_is_recorded_with_the_causer(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => 'password']));

        $logins = Activity::query()->inLog('auth')->forEvent('login')->get();

        // Exactly one. Laravel's listener auto-discovery matches any `handle*`
        // method, so a listener method named handleLogin would be registered
        // twice -- once by discovery, once by AppServiceProvider -- and write
        // every row in duplicate.
        $this->assertCount(1, $logins, 'Login should produce exactly one activity record.');
        $this->assertTrue($logins->first()->causer->is($user));
    }

    public function test_failed_login_is_recorded_without_the_password(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'wrong-password']));

        $activity = Activity::query()->inLog('auth')->forEvent('failed')->latest('id')->first();

        $this->assertNotNull($activity, 'Failed login did not produce an activity record.');
        $this->assertSame($user->email, $activity->getProperty('email'));

        $serialised = json_encode($activity->properties->toArray());
        $this->assertStringNotContainsString('wrong-password', $serialised);
    }

    public function test_model_changes_are_recorded_without_the_password_hash(): void
    {
        $user = User::factory()->create();
        $user->update(['name' => 'Nama Baru', 'password' => 'rahasia-baru']);

        $activity = Activity::query()->forSubject($user)->forEvent('updated')->latest('id')->first();

        $this->assertNotNull($activity, 'Model update did not produce an activity record.');

        $changes = $activity->attribute_changes->toArray();
        $this->assertSame('Nama Baru', $changes['attributes']['name']);
        $this->assertArrayNotHasKey('password', $changes['attributes']);
        $this->assertArrayNotHasKey('password', $changes['old'] ?? []);
    }

    public function test_admin_can_render_the_list_and_view_pages(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        activity('user')
            ->causedBy($admin)
            ->performedOn($admin)
            ->event('updated')
            ->withProperties(['attributes' => ['name' => 'Baru'], 'old' => ['name' => 'Lama']])
            ->log('Data pengguna diubah');

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Activity::all());

        Livewire::test(ViewActivity::class, ['record' => Activity::latest('id')->first()->getKey()])
            ->assertSuccessful();
    }

    public function test_the_causer_filter_narrows_results_to_one_user(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $this->actingAs($admin);

        $mine = activity()->causedBy($admin)->log('punya admin');
        $theirs = activity()->causedBy($other)->log('punya orang lain');

        Livewire::test(ListActivities::class)
            ->filterTable('causer_id', $admin->getKey())
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_non_admin_cannot_access_the_resource(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(ActivityResource::canAccess());

        $this->get(ActivityResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_log_is_read_only(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $activity = activity()->causedBy($admin)->log('tidak boleh diubah');

        $this->assertFalse(ActivityResource::canCreate());
        $this->assertFalse(ActivityResource::canEdit($activity));
        $this->assertFalse(ActivityResource::canDelete($activity));
        $this->assertArrayNotHasKey('edit', ActivityResource::getPages());
        $this->assertArrayNotHasKey('create', ActivityResource::getPages());
    }
}
