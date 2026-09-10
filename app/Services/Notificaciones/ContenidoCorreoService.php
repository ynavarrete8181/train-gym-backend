<?php

namespace App\Services\Notificaciones;

class ContenidoCorreoService
{
    public const VARIABLES_ACCESO = ['nombre_sistema', 'nombre_usuario', 'email_usuario', 'cedula_usuario', 'roles_usuario', 'sede_usuario', 'facultad_direccion_usuario', 'carrera_area_usuario', 'url_activacion', 'codigo_activacion', 'fecha_expiracion', 'fecha_creacion', 'carrera_usuario', 'url_sistema', 'password_temporal'];

    public const VARIABLES_COMUNICADO = ['nombre_sistema', 'nombre_destinatario', 'nombre_usuario', 'correo_destino'];

    public function variablesEn(string ...$contenidos): array
    {
        preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', implode("\n", $contenidos), $coincidencias);

        return array_values(array_unique($coincidencias[1] ?? []));
    }

    public function variablesPermitidas(string $tipo): array
    {
        return in_array($tipo, ['ACCESO', 'RESTABLECIMIENTO'], true) ? self::VARIABLES_ACCESO : self::VARIABLES_COMUNICADO;
    }

    public function validarEstructura(string $tipo, string $asunto, string $html, string $texto): array
    {
        $errores = [];
        if (trim(strip_tags($html)) === '' || trim($texto) === '') {
            $errores[] = 'La plantilla debe tener contenido HTML y texto plano.';
        }
        if (mb_strlen(trim(strip_tags($html))) < 30) {
            $errores[] = 'El contenido es demasiado corto para una comunicación institucional.';
        }
        if (in_array($tipo, ['ACCESO', 'RESTABLECIMIENTO'], true) && ! str_contains($asunto.$html.$texto, '{{url_activacion}}')) {
            $errores[] = 'La plantilla de acceso debe incluir {{url_activacion}}.';
        }
        if (in_array($tipo, ['ACCESO', 'RESTABLECIMIENTO'], true) && ! str_contains($asunto.$html.$texto, '{{nombre_usuario}}')) {
            $errores[] = 'La plantilla de acceso debe incluir {{nombre_usuario}}.';
        }
        if (in_array($tipo, ['ACCESO', 'RESTABLECIMIENTO'], true) && ! str_contains($asunto.$html.$texto, '{{codigo_activacion}}')) {
            $errores[] = 'La plantilla de acceso debe incluir {{codigo_activacion}}.';
        }
        if (! preg_match('/<(p|div|h[1-6]|ul|ol)\b/i', $html)) {
            $errores[] = 'El contenido HTML debe estar organizado en párrafos, títulos o listas.';
        }

        return $errores;
    }

    public function sanitizarHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link)\b[^>]*/?>#is', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1="#"', $html) ?? '';

        return trim($html);
    }

    public function generarTexto(string $html): string
    {
        $conSaltos = preg_replace('#<(br\s*/?|/p|/div|/li|/h[1-6])>#i', "\n", $html) ?? $html;
        $texto = html_entity_decode(strip_tags($conSaltos), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace("/[ \t]+/", ' ', $texto) ?? $texto;
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto) ?? $texto;

        return trim($texto);
    }
}
