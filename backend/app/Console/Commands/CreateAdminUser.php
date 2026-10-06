<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('admin:create')]
#[Description('Create an administrator account interactively (hidden password input).')]
class CreateAdminUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->ask('Nome do administrador');

        $email = $this->askValidEmail();

        $password = $this->askMatchingPassword();

        // The User model's "hashed" cast hashes this on write (and is a
        // no-op if the value is already hashed), so the plain password is
        // passed through here untouched.
        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Administrador \"{$email}\" criado com sucesso.");

        return self::SUCCESS;
    }

    private function askValidEmail(): string
    {
        while (true) {
            $email = Str::lower(trim((string) $this->ask('E-mail')));

            $validator = Validator::make(
                ['email' => $email],
                ['email' => ['required', 'email', 'unique:users,email']],
            );

            if ($validator->fails()) {
                $this->error($validator->errors()->first('email'));

                continue;
            }

            return $email;
        }
    }

    private function askMatchingPassword(): string
    {
        while (true) {
            $password = $this->secret('Senha (mínimo 8 caracteres)');
            $confirmation = $this->secret('Confirme a senha');

            if ($password === null || strlen($password) < 8) {
                $this->error('A senha precisa ter ao menos 8 caracteres.');

                continue;
            }

            if ($password !== $confirmation) {
                $this->error('As senhas não coincidem.');

                continue;
            }

            return $password;
        }
    }
}
