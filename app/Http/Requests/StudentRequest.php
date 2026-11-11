<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = $this->route('student');

        return $student instanceof Student
            ? $this->user()->can('update', $student)
            : $this->user()->can('create', Student::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => self::clean($this->input('first_name')),
            'last_name_paternal' => self::clean($this->input('last_name_paternal')),
            'last_name_maternal' => self::clean($this->input('last_name_maternal')) ?: null,
            'enrollment_number' => self::clean($this->input('enrollment_number')),
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name_paternal' => ['required', 'string', 'max:100'],
            'last_name_maternal' => ['nullable', 'string', 'max:100'],
            'enrollment_number' => ['required', 'string', 'max:32',
                Rule::unique('students')->ignore($this->route('student'))],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Escribe el nombre.',
            'last_name_paternal.required' => 'Escribe el apellido paterno.',
            'enrollment_number.required' => 'Escribe la matrícula.',
            'enrollment_number.unique' => 'Ya hay un alumno con esa matrícula (puede estar dado de baja).',
            'enrollment_number.max' => 'La matrícula admite hasta 32 caracteres.',
            '*.max' => 'Este campo admite hasta :max caracteres.',
            'group_id.exists' => 'Ese grupo no existe.',
        ];
    }

    public static function clean(?string $value): ?string
    {
        return $value === null ? null : trim(preg_replace('/\s+/u', ' ', $value));
    }
}
