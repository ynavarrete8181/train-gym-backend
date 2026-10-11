<?php

namespace Tests\Feature;

use App\Services\Institucional\EstructuraInstitucionalService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstructuraInstitucionalUsuarioServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("ATTACH DATABASE ':memory:' AS institucional");
        DB::statement('CREATE TABLE institucional.usuario_contexto (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_usuario INTEGER,
            id_contexto INTEGER,
            principal INTEGER,
            activo INTEGER,
            created_at TEXT,
            updated_at TEXT
        )');
    }

    public function test_asigna_varios_contextos_y_conserva_el_primero_como_principal(): void
    {
        app(EstructuraInstitucionalService::class)->asignarUsuario(15, [8, 3, 5]);

        $asignaciones = DB::table('institucional.usuario_contexto')
            ->where('id_usuario', 15)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $asignaciones);
        $this->assertSame(8, (int) $asignaciones[0]->id_contexto);
        $this->assertSame(1, (int) $asignaciones[0]->principal);
        $this->assertSame(0, (int) $asignaciones[1]->principal);
        $this->assertSame(0, (int) $asignaciones[2]->principal);
    }

    public function test_reemplaza_asignaciones_y_elimina_contextos_repetidos(): void
    {
        $servicio = app(EstructuraInstitucionalService::class);
        $servicio->asignarUsuario(15, [8, 3]);
        $servicio->asignarUsuario(15, [5, 5, 9]);

        $asignaciones = DB::table('institucional.usuario_contexto')
            ->where('id_usuario', 15)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $asignaciones);
        $this->assertSame([5, 9], $asignaciones->pluck('id_contexto')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, (int) $asignaciones[0]->principal);
    }
}
