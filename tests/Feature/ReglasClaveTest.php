<?php

namespace Tests\Feature;

use App\Support\ReglasClave;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReglasClaveTest extends TestCase
{
    public function test_acepta_una_clave_que_cumple_todos_los_requisitos(): void
    {
        $validador = Validator::make(
            ['password' => 'Sistema#2026'],
            ['password' => ['required', 'string', ReglasClave::segura()]],
        );

        $this->assertFalse($validador->fails());
    }

    #[DataProvider('clavesDebiles')]
    public function test_rechaza_claves_que_no_cumplen_la_politica(string $password): void
    {
        $validador = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', ReglasClave::segura()]],
        );

        $this->assertTrue($validador->fails());
    }

    public static function clavesDebiles(): array
    {
        return [
            'corta' => ['S#2a'],
            'sin mayúscula' => ['sistema#2026'],
            'sin minúscula' => ['SISTEMA#2026'],
            'sin número' => ['Sistema#Base'],
            'sin símbolo' => ['Sistema2026'],
        ];
    }
}
