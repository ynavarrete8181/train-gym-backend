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
            'direccion' => ['nullable', 'string', 'max:250'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'provincia' => ['nullable', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:180'],
            'hora_apertura' => ['nullable', 'required_with:hora_cierre', 'date_format:H:i'],
            'hora_cierre' => ['nullable', 'required_with:hora_apertura', 'date_format:H:i', 'after:hora_apertura'],
            'maneja_caja' => ['required', 'boolean'],
            'maneja_inventario' => ['required', 'boolean'],
            'permite_reservas' => ['required', 'boolean'],
            'permite_entrenamiento' => ['required', 'boolean'],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:180', 'distinct:ignore_case'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la sede es obligatorio.',
            'email.email' => 'Ingrese un correo electrónico válido.',
            'hora_apertura.required_with' => 'Indique también la hora de apertura.',
            'hora_cierre.required_with' => 'Indique también la hora de cierre.',
            'hora_cierre.after' => 'La hora de cierre debe ser posterior a la hora de apertura.',
            'aliases.max' => 'Puede registrar hasta 20 alias.',
            'aliases.*.distinct' => 'No repita el mismo alias.',
            'activo.required' => 'Debe indicar el estado de la sede.',
        ];
    }
}
