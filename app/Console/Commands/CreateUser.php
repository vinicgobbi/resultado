<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('usuarios:criar {--name= : Nome} {--email= : E-mail}')]
#[Description('Cria um usuário com acesso à área administrativa')]
class CreateUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?: text('Nome', required: true),
            'email' => $this->option('email') ?: text('E-mail', required: true),
            'password' => password('Senha', required: true, hint: 'Mínimo de 8 caracteres.'),
        ];

        $data['password_confirmation'] = password('Confirme a senha', required: true);

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'email.unique' => 'Já existe um usuário com este e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'email.lowercase' => 'O e-mail deve estar em letras minúsculas.',
            'password.confirmed' => 'As senhas não conferem.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create($validator->safe()->only(['name', 'email', 'password']));

        $this->components->info("Usuário {$user->email} criado com sucesso.");

        return self::SUCCESS;
    }
}
