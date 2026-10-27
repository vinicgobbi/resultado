<?php

use App\Models\User;

test('users can log in to the admin area', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('admin.index'));

    $this->assertAuthenticatedAs($user);
});

test('wrong credentials are rejected', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'errada'])
        ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);

    $this->assertGuest();
});

test('users can log out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});
