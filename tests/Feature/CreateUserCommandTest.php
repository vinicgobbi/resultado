<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the command creates a user with a hashed password', function () {
    $this->artisan('usuarios:criar', ['--name' => 'Admin', '--email' => 'admin@example.com'])
        ->expectsQuestion('Senha', 'senha-segura')
        ->expectsQuestion('Confirme a senha', 'senha-segura')
        ->assertSuccessful();

    $user = User::query()->where('email', 'admin@example.com')->sole();

    expect(Hash::check('senha-segura', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('senha-segura');
});

test('the command rejects mismatched passwords', function () {
    $this->artisan('usuarios:criar', ['--name' => 'Admin', '--email' => 'admin@example.com'])
        ->expectsQuestion('Senha', 'senha-segura')
        ->expectsQuestion('Confirme a senha', 'outra-senha')
        ->expectsOutputToContain('As senhas não conferem.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});
