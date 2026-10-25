<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('admins can create users with a hashed password', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.users.store'), [
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'maria@example.com')->sole();

    expect($user->password)->not->toBe('senha-segura')
        ->and(Hash::check('senha-segura', $user->password))->toBeTrue();
});

test('user emails must be unique', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Outro',
            'email' => $admin->email,
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])
        ->assertSessionHasErrors(['email' => 'Já existe um usuário com este e-mail.']);
});

test('admins can delete other users but not themselves', function () {
    $admin = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))->assertSessionHas('error');
    $this->actingAs($admin)->delete(route('admin.users.destroy', $other))->assertSessionHas('success');

    expect(User::query()->pluck('id')->all())->toBe([$admin->id]);
});

test('guests cannot manage users', function () {
    $this->post(route('admin.users.store'), [])->assertRedirect(route('login'));
});
