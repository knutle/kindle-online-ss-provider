<?php

namespace Tests\Feature;

use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_weather_dashboard_is_public_at_its_new_url(): void
    {
        $this->get('/dashboard')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('weather-dashboard')->where('calendar', null),
        );
    }

    public function test_root_and_authentication_routes_are_not_available(): void
    {
        foreach (['/', '/login', '/register', '/account/dashboard', '/settings/profile', '/.well-known/passkey-endpoints'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->post('/login')->assertNotFound();
        $this->post('/logout')->assertNotFound();
    }

    public function test_fresh_migrations_preserve_sessions_without_user_tables(): void
    {
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
        $this->assertFalse(Schema::hasTable('passkeys'));
    }

    public function test_dashboard_accepts_an_old_database_session(): void
    {
        config(['session.driver' => 'database']);
        $loginKey = 'login_web_'.sha1(SessionGuard::class);

        $this->withSession([$loginKey => 1])->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('auth'));
        $this->assertTrue(Auth::guest());
    }

    public function test_migrations_do_not_drop_existing_user_data(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('passkeys', fn (Blueprint $table) => $table->id());
        DB::table('users')->insert(['id' => 1]);
        DB::table('passkeys')->insert(['id' => 1]);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('passkeys')->count());
    }
}
