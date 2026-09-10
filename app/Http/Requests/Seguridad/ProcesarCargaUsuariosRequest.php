<?php

namespace App\Http\Requests\Seguridad;

use Illuminate\Foundation\Http\FormRequest;

class ProcesarCargaUsuariosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->routeIs('base.seguridad.usuarios.carga.validar')) {
            return ['archivo' => ['required', 'file', 'mimes:xlsx', 'max:5120']];
        }

        return [
            'filas' => ['required', 'array', 'min:1', 'max:1000'],
            'filas.*' => ['array'],
            'notificar_credenciales' => ['nullable', 'boolean'],
        ];
    }
}
