<?php

namespace App\Http\Requests\Institucional;

use App\Models\Institucional\Sede;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarUnidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:180'],
            'tipo' => ['required', Rule::in(['FACULTAD', 'DIRECCION'])],
            'id_sede' => ['required', 'integer', Rule::exists(Sede::class, 'id_sede')],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:180', 'distinct:ignore_case'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'id_sede.required' => 'Seleccione la sede donde funciona la facultad o dirección.',
            'aliases.max' => 'Puede registrar hasta 20 alias.',
            'aliases.*.distinct' => 'No repita el mismo alias.',
        ];
    }
}
