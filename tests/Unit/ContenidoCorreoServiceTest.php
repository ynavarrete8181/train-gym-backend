<?php

namespace Tests\Unit;

use App\Services\Notificaciones\ContenidoCorreoService;
use PHPUnit\Framework\TestCase;

class ContenidoCorreoServiceTest extends TestCase
{
    public function test_elimina_contenido_ejecutable_y_conserva_formato_seguro(): void
    {
        $servicio = new ContenidoCorreoService;
        $html = '<p style="text-align:center" onclick="alert(1)"><strong>Hola</strong></p><script>alert(2)</script><a href="javascript:alert(3)">Abrir</a>';
        $limpio = $servicio->sanitizarHtml($html);

        $this->assertStringContainsString('<strong>Hola</strong>', $limpio);
        $this->assertStringContainsString('text-align:center', $limpio);
        $this->assertStringNotContainsString('onclick', $limpio);
        $this->assertStringNotContainsString('<script', $limpio);
        $this->assertStringNotContainsString('javascript:', $limpio);
    }

    public function test_genera_texto_plano_legible_desde_html(): void
    {
        $servicio = new ContenidoCorreoService;
        $texto = $servicio->generarTexto('<h2>Comunicado</h2><p>Hola <strong>{{nombre_usuario}}</strong>.</p>');

        $this->assertSame("Comunicado\nHola {{nombre_usuario}}.", $texto);
    }

    public function test_valida_la_estructura_minima_de_acceso(): void
    {
        $servicio = new ContenidoCorreoService;
        $errores = $servicio->validarEstructura('ACCESO', 'Activa tu cuenta', '<p>Hola {{nombre_usuario}}, establece tu clave aquí: {{url_activacion}} con el código {{codigo_activacion}}</p>', 'Hola {{nombre_usuario}}: {{url_activacion}} código {{codigo_activacion}}');
        $this->assertSame([], $errores);
        $this->assertNotEmpty($servicio->validarEstructura('ACCESO', 'Cuenta', '<p>Contenido sin enlace suficientemente largo para validar.</p>', 'Contenido sin enlace.'));
    }
}
