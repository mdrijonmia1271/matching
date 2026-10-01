<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_staff_member_can_open_their_profile_and_password_pages(): void
    {
        $user = $this->staff('sales_staff');

        $this->actingAs($user)->get(route('admin.profile.show'))
            ->assertOk()
            ->assertSee($user->email)
            ->assertSee('Change password');

        $this->actingAs($user)->get(route('admin.profile.password'))->assertOk();
    }

    public function test_customers_cannot_open_the_admin_profile(): void
    {
        $customer = \App\Models\User::factory()->create();

        $this->actingAs($customer)->get(route('admin.profile.show'))->assertForbidden();
    }

    public function test_staff_can_update_their_own_details_but_not_their_role(): void
    {
        $user = $this->staff('sales_staff');
        $roleId = $user->role_id;

        $this->actingAs($user)
            ->patch(route('admin.profile.update'), [
                'name' => 'New Name',
                'email' => 'new@example.com',
                'phone' => '01700000000',
                'role_id' => 1,
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame($roleId, $user->role_id);
        $this->assertTrue($user->is_active);
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $user = $this->staff();

        $this->actingAs($user)
            ->put(route('admin.profile.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'newpass123',
                'password_confirmation' => 'newpass123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->put(route('admin.profile.password.update'), [
                'current_password' => 'password123',
                'password' => 'newpass123',
                'password_confirmation' => 'newpass123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('newpass123', $user->refresh()->password));
    }
}
