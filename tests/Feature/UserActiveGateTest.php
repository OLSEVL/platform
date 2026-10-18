<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserActiveGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_inactive_user_and_does_not_log_in(): void
    {
        $response = $this->post('/register', [
            'name' => 'New Trader',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            // Попытка обойти гейт через форму — контроллер всё равно ставит false.
            'active' => '1',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertGuest();

        $user = User::query()->where('email', 'new@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->active);
    }

    public function test_user_can_login_after_admin_sets_active(): void
    {
        $this->post('/register', [
            'name' => 'Wait Trader',
            'email' => 'wait@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::query()->where('email', 'wait@example.com')->first();
        $this->assertNotNull($user);
        $user->active = true;
        $user->save();

        $this->post('/login', [
            'email' => 'wait@example.com',
            'password' => 'password123',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejected_when_user_is_inactive(): void
    {
        $user = User::factory()->create([
            'email' => 'off@example.com',
            'password' => 'secret123',
            'active' => false,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_ok_when_user_is_active(): void
    {
        $user = User::factory()->create([
            'email' => 'on@example.com',
            'password' => 'secret123',
            'active' => true,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_session_is_kicked_from_dashboard(): void
    {
        $user = User::factory()->create(['active' => false]);

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
