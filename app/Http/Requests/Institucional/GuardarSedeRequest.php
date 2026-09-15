<?php

namespace App\Http\Requests\Institucional;

use Illuminate\Foundation\Http\FormRequest;

class GuardarSedeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $camposOpcionales = [
            'codigo',
            'direccion',
            'ciudad',
            'provincia',
            'telefono',
            'whatsapp',
            'email',
            'hora_apertura',
            'hora_cierre',
        ];

        $normalizados = [];
        foreach ($camposOpcionales as $campo) {
            if (! $this->exists($campo)) {
                continue;
            }

            $valor = $this->input($campo);
            $normalizados[$campo] = is_string($valor) && trim($valor) === '' ? null : $valor;
        }

        $this->merge($normalizados);
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
            'hora_apertura' => ['nullable', 'date_format:H:i'],
            'hora_cierre' => ['nullable', 'date_format:H:i', 'after:hora_apertura'],
            'maneja_caja' => ['sometimes', 'boolean'],
            'maneja_inventario' => ['sometimes', 'boolean'],
            'permite_reservas' => ['sometimes', 'boolean'],
            'permite_entrenamiento' => ['sometimes', 'boolean'],
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
            'hora_apertura.date_format' => 'La hora de apertura no tiene un formato válido.',
            'hora_cierre.date_format' => 'La hora de cierre no tiene un formato válido.',
            'hora_cierre.after' => 'La hora de cierre debe ser posterior a la hora de apertura.',
            'aliases.max' => 'Puede registrar hasta 20 alias.',
            'aliases.*.distinct' => 'No repita el mismo alias.',
            'activo.required' => 'Debe indicar el estado de la sede.',
        ];
    }
}
