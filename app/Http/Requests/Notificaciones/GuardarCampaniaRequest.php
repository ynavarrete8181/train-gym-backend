<?php

namespace App\Http\Requests\Notificaciones;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarCampaniaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'plantilla_id' => ['nullable', 'integer'],
            'canales' => ['required', 'array'],
            'canales.interno' => ['required', 'boolean'],
            'canales.correo' => ['required', 'boolean'],
            'canales.push' => ['required', 'boolean'],
            'canales.app' => ['sometimes', 'boolean'],
            'publicacion_app' => ['nullable', 'array'],
            'publicacion_app.visible' => ['sometimes', 'boolean'],
            'publicacion_app.imagen_url' => ['nullable', 'url:https', 'max:1000'],
            'publicacion_app.imagen_archivo' => ['nullable', 'string', 'max:500', 'starts_with:comunicaciones-app/'],
            'publicacion_app.accion_url' => ['nullable', 'url:https', 'max:1000'],
            'publicacion_app.accion_etiqueta' => ['nullable', 'string', 'max:40'],
            'publicacion_app.titulo' => ['nullable', 'string', 'max:255'],
            'publicacion_app.descripcion' => ['nullable', 'string', 'max:1000'],
            'correos_cc' => ['nullable', 'array', 'max:20'],
            'correos_cc.*' => ['email', 'max:255'],
            'correos_cco' => ['nullable', 'array', 'max:20'],
            'correos_cco.*' => ['email', 'max:255'],
            'criterios' => ['required', 'array'],
            'criterios.todos' => ['nullable', 'boolean'],
            'criterios.usuarios' => ['nullable', 'array'],
            'criterios.usuarios.*' => ['integer'],
            'criterios.roles' => ['nullable', 'array'],
            'criterios.roles.*' => ['integer'],
            'criterios.sedes' => ['nullable', 'array'],
            'criterios.sedes.*' => ['integer'],
            'criterios.unidades' => ['nullable', 'array'],
            'criterios.unidades.*' => ['integer'],
            'criterios.carreras_areas' => ['nullable', 'array'],
            'criterios.carreras_areas.*' => ['integer'],
            'criterios.externos' => ['nullable', 'array'],
            'criterios.externos.*.nombre' => ['nullable', 'string', 'max:180'],
            'criterios.externos.*.correo' => ['required_with:criterios.externos', 'email', 'max:255'],
            'criterios.vinculaciones' => ['nullable', 'array'],
            'criterios.vinculaciones.*' => [Rule::in(['ESTUDIANTE', 'DOCENTE', 'ADMINISTRATIVO', 'SIN_CLASIFICAR'])],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $canales = $this->input('canales', []);
            $otrosCanales = (bool) (($canales['interno'] ?? false) || ($canales['correo'] ?? false) || ($canales['push'] ?? false));
            if (($canales['app'] ?? false) && $otrosCanales) {
                $validator->errors()->add('canales.app', 'Publicación app no puede combinarse con otros canales.');
            }
            $requierePlantilla = $otrosCanales;
            if ($requierePlantilla && ! $this->input('plantilla_id')) {
                $validator->errors()->add('plantilla_id', 'Selecciona una plantilla para los canales elegidos.');
            }
            if (($canales['app'] ?? false) && ! trim((string) $this->input('publicacion_app.titulo'))) {
                $validator->errors()->add('publicacion_app.titulo', 'Ingresa el título de la publicación.');
            }
            if (($canales['app'] ?? false) && ! trim((string) $this->input('publicacion_app.descripcion'))) {
                $validator->errors()->add('publicacion_app.descripcion', 'Ingresa la descripción de la publicación.');
            }
        }];
    }
}
