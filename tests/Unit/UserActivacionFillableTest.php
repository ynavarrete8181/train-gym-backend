<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserActivacionFillableTest extends TestCase
{
    public function test_activacion_permite_guardar_password_y_fecha_verificacion(): void
    {
        $usuario = new User;

        $this->assertTrue($usuario->isFillable('password'));
        $this->assertTrue($usuario->isFillable('email_verified_at'));
        $this->assertSame('datetime', $usuario->getCasts()['email_verified_at']);
    }
}
