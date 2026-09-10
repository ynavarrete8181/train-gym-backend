<?php

namespace Tests\Unit;

use App\Http\Middleware\AutorizarFuncionBase;
use App\Models\User;
use App\Services\Seguridad\PermisoService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AutorizarFuncionBaseTest extends TestCase
{
    public function test_permite_continuar_cuando_el_usuario_tiene_una_funcion_requerida(): void
    {
        $usuario = new User;
        $usuario->id = 10;

        $request = Request::create('/api/base/seguridad/usuarios');
        $request->setUserResolver(fn () => $usuario);

        $permisos = $this->createMock(PermisoService::class);
        $permisos->expects($this->once())
            ->method('usuarioTieneAlgunaFuncion')
            ->with($usuario, ['SEGURIDAD-USUARIOS'])
            ->willReturn(true);

        $respuesta = (new AutorizarFuncionBase($permisos))->handle(
            $request,
            fn (): Response => new Response('autorizado', 200),
            'SEGURIDAD-USUARIOS',
        );

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('autorizado', $respuesta->getContent());
    }

    public function test_responde_403_cuando_el_usuario_no_tiene_ninguna_funcion_requerida(): void
    {
        $usuario = new User;
        $usuario->id = 20;

        $request = Request::create('/api/base/seguridad/roles');
        $request->setUserResolver(fn () => $usuario);

        $permisos = $this->createMock(PermisoService::class);
        $permisos->expects($this->once())
            ->method('usuarioTieneAlgunaFuncion')
            ->with($usuario, ['SEGURIDAD-ROLES', 'SEGURIDAD-USUARIOS'])
            ->willReturn(false);

        $respuesta = (new AutorizarFuncionBase($permisos))->handle(
            $request,
            fn (): Response => new Response('no debe ejecutarse', 200),
            'SEGURIDAD-ROLES',
            'SEGURIDAD-USUARIOS',
        );

        $this->assertSame(403, $respuesta->getStatusCode());
        $this->assertStringContainsString('No tienes permiso', (string) $respuesta->getContent());
    }
}
