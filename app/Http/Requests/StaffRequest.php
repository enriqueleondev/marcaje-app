<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            ? $this->user()->can('update', $user)
            : $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => StudentRequest::clean($this->input('first_name')),
            'last_name_paternal' => StudentRequest::clean($this->input('last_name_paternal')),
            'last_name_maternal' => StudentRequest::clean($this->input('last_name_maternal')) ?: null,
            'email' => mb_strtolower((string) StudentRequest::clean($this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name_paternal' => ['required', 'string', 'max:100'],
            'last_name_maternal' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Escribe el nombre.',
            'last_name_paternal.required' => 'Escribe el apellido paterno.',
            'email.required' => 'Escribe el correo.',
            'email.email' => 'Escribe un correo válido.',
            'email.unique' => 'Ya hay una cuenta con ese correo (puede estar dada de baja).',
            'role.required' => 'Elige un rol.',
            '*.max' => 'Este campo admite hasta :max caracteres.',
        ];
    }
}
