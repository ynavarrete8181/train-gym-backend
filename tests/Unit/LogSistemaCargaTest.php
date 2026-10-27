<?php

namespace Tests\Unit;

use App\Services\Logs\LogSistemaService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LogSistemaCargaTest extends TestCase
{
    public function test_servicio_de_logs_carga_y_expone_registro_de_excepciones(): void
    {
        // class_exists hace que PHP analice todo el archivo antes de usarlo.
        $this->assertTrue(class_exists(LogSistemaService::class));
        $reflexion = new ReflectionClass(LogSistemaService::class);
        $this->assertTrue($reflexion->hasMethod('excepcion'));
        $this->assertTrue($reflexion->hasMethod('listarEventos'));
        $this->assertTrue($reflexion->hasMethod('listarIntegraciones'));
    }
}
