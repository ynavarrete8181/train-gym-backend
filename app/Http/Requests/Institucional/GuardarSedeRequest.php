<?php

namespace App\Http\Requests\Institucional;

use Illuminate\Foundation\Http\FormRequest;

class GuardarSedeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo' => ['nullable', 'string', 'max:200'],
            'nombre' => ['required', 'string', 'max:180'],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:180', 'distinct:ignore_case'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la sede es obligatorio.',
            'aliases.max' => 'Puede registrar hasta 20 alias.',
            'aliases.*.distinct' => 'No repita el mismo alias.',
            'activo.required' => 'Debe indicar el estado de la sede.',
        ];
    }
}
