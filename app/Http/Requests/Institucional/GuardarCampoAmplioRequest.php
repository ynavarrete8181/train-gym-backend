<?php

namespace App\Http\Requests\Institucional;

use Illuminate\Foundation\Http\FormRequest;

class GuardarCampoAmplioRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:180'],
            'activo' => ['required', 'boolean'],
        ];
    }
}
