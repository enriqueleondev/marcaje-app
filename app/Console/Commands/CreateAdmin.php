<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Crea la cuenta root/directivo inicial de una instalación nueva.
 * Uso: php artisan marcaje:crear-admin
 */
class CreateAdmin extends Command
{
    protected $signature = 'marcaje:crear-admin
        {--nombre= : Nombre(s)}
        {--paterno= : Apellido paterno}
        {--materno= : Apellido materno}
        {--email= : Correo para entrar}
        {--password= : Contraseña (si se omite, se pide sin mostrarla)}';

    protected $description = 'Crea una cuenta de dirección (root/directivo)';

    public function handle(): int
    {
        $data = [
            'first_name' => $this->option('nombre') ?? text('Nombre(s)', required: true),
            'last_name_paternal' => $this->option('paterno') ?? text('Apellido paterno', required: true),
            'last_name_maternal' => $this->option('materno') ?? (text('Apellido materno (opcional)') ?: null),
            'email' => $this->option('email') ?? text('Correo', required: true),
            'password' => $this->option('password') ?? password('Contraseña (mínimo 10, con letras y números)', required: true),
        ];

        $validator = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name_paternal' => ['required', 'string', 'max:255'],
            'last_name_maternal' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([...$data, 'role' => Role::Admin, 'is_active' => true]);
        AuditLog::record('user.created', $user, ['via' => 'console']);

        $this->info("Cuenta de dirección creada para {$user->email}.");

        return self::SUCCESS;
    }
}
