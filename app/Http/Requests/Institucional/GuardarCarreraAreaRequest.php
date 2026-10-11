<?php

namespace App\Http\Requests\Institucional;

use App\Models\Institucional\CampoAmplio;
use App\Models\Institucional\SedeUnidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarCarreraAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:180'],
            'tipo' => ['required', Rule::in(['CARRERA', 'AREA'])],
            'id_sede_unidad' => ['required', 'integer', Rule::exists(SedeUnidad::class, 'id')],
            'codigo_ces' => ['nullable', 'required_if:tipo,CARRERA', 'string', 'max:100'],
            'id_campo_amplio' => ['nullable', 'required_if:tipo,CARRERA', 'integer', Rule::exists(CampoAmplio::class, 'id_campo_amplio')],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:180', 'distinct:ignore_case'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'id_sede_unidad.required' => 'Seleccione la sede y la estructura.',
            'codigo_ces.required_if' => 'El código CES es obligatorio para una carrera.',
            'id_campo_amplio.required_if' => 'Seleccione el campo amplio de la carrera.',
            'aliases.max' => 'Puede registrar hasta 20 alias.',
            'aliases.*.distinct' => 'No repita el mismo alias.',
        ];
    }
}
