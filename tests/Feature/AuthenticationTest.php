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

test('guessing the password of an account is slowed down even from many addresses', function () {
    $user = User::factory()->create();
    $attempt = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'errada']);

    foreach (range(1, 10) as $index) {
        $attempt("10.0.0.{$index}")->assertSessionHasErrors('email');
    }

    $attempt('10.0.1.1')->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
        ->post(route('login.store'), ['email' => 'outra@example.com', 'password' => 'errada'])
        ->assertSessionHasErrors('email');
});
