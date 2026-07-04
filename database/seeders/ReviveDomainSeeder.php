<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReviveDomainSeeder extends Seeder
{
    public function run(): void
    {
        $estadoActivoId = DB::table('core.estados')->where('codigo', 'ACTIVO')->value('id');
        $tipoSocioId = DB::table('core.persona_tipos')->where('codigo', 'SOCIO')->value('id');
        $firstUserId = DB::table('seguridad.usuarios')->orderBy('id')->value('id') ?: 1;

        DB::table('core.personas')->upsert([
            [
                'numero_identificacion' => '1399999999',
                'tipo_identificacion' => 'CEDULA',
                'gimnasio_id' => 1,
                'nombres' => 'Melany',
                'apellidos' => 'Mendoza Zamora',
                'fecha_nacimiento' => '1998-08-14',
                'sexo' => 'F',
                'nacionalidad' => 'Ecuatoriana',
                'provincia' => 'Manabi',
                'ciudad' => 'Manta',
                'parroquia' => 'Tarqui',
                'direccion' => 'Ciudadela Revive, Manta',
                'telefono' => '0999999999',
                'email' => 'melany@revive.com',
                'foto_url' => null,
                'estado_id' => $estadoActivoId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'numero_identificacion' => '1300000010',
                'tipo_identificacion' => 'CEDULA',
                'gimnasio_id' => 1,
                'nombres' => 'Consumidor',
                'apellidos' => 'Final',
                'fecha_nacimiento' => null,
                'sexo' => null,
                'nacionalidad' => 'Ecuatoriana',
                'provincia' => 'Manabi',
                'ciudad' => 'Manta',
                'parroquia' => null,
                'direccion' => 'Cliente ocasional POS',
                'telefono' => null,
                'email' => null,
                'foto_url' => null,
                'estado_id' => $estadoActivoId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['tipo_identificacion', 'numero_identificacion'], [
            'nombres',
            'apellidos',
            'telefono',
            'email',
            'direccion',
            'estado_id',
            'updated_at',
        ]);

        $melanyId = DB::table('core.personas')
            ->where('numero_identificacion', '1399999999')
            ->value('id');

        $consumidorFinalId = DB::table('core.personas')
            ->where('numero_identificacion', '1300000010')
            ->value('id');

        if ($melanyId && $tipoSocioId) {
            DB::table('core.persona_tipo_detalle')->updateOrInsert(
                ['persona_id' => $melanyId, 'tipo_id' => $tipoSocioId],
                [
                    'activo' => true,
                    'fecha_inicio' => now()->toDateString(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        DB::table('socios.membresias')->upsert([
            [
                'id' => 1,
                'nombre' => 'Musculación',
                'descripcion' => 'Entrenamiento orientado a ganancia de masa muscular, salud articular y longevidad.',
                'duracion_dias' => 30,
                'precio' => 60,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'nombre' => 'Funcional',
                'descripcion' => 'Entrenamiento ideal para personas que buscan perder peso, mejorar su condición física y tonificar.',
                'duracion_dias' => 30,
                'precio' => 60,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'nombre' => 'Híbrido',
                'descripcion' => 'Entrenamiento para recomposición corporal, rendimiento atlético, estética y sensación de atleta.',
                'duracion_dias' => 30,
                'precio' => 80,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'nombre' => 'Deportivo',
                'descripcion' => 'Entrenamiento especializado para deportistas que buscan mejorar rendimiento, fuerza y capacidades específicas.',
                'duracion_dias' => 30,
                'precio' => 100,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 5,
                'nombre' => 'Fortalecimiento',
                'descripcion' => 'Entrenamiento ideal para personas que salen de una lesión o presentan molestias musculares o articulares.',
                'duracion_dias' => 30,
                'precio' => 100,
                'activa' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['id'], ['nombre', 'descripcion', 'duracion_dias', 'precio', 'activa', 'updated_at']);

        if ($melanyId) {
            DB::table('socios.socios')->updateOrInsert(
                ['persona_id' => $melanyId],
                [
                    'codigo_socio' => 'REV-SOC-1001',
                    'sede_id' => 1,
                    'fecha_alta' => now()->toDateString(),
                    'estado_id' => $estadoActivoId,
                    'observacion' => 'Socia demostrativa creada para punto de venta y ficha tecnica.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $socioId = DB::table('socios.socios')->where('persona_id', $melanyId)->value('id');

            if ($socioId) {
                DB::table('socios.socio_membresias')->updateOrInsert(
                    ['socio_id' => $socioId, 'membresia_id' => 1],
                    [
                        'fecha_inicio' => now()->toDateString(),
                        'fecha_fin' => now()->addDays(30)->toDateString(),
                        'estado_id' => $estadoActivoId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            DB::table('salud.catalogo_patologias')->upsert([
                ['nombre' => 'Dolor lumbar', 'descripcion' => 'Molestia lumbar leve', 'activa' => true, 'created_at' => now(), 'updated_at' => now()],
                ['nombre' => 'Rodilla sensible', 'descripcion' => 'Requiere seguimiento en tren inferior', 'activa' => true, 'created_at' => now(), 'updated_at' => now()],
            ], ['nombre'], ['descripcion', 'activa', 'updated_at']);

            DB::table('salud.fichas_tecnicas')->updateOrInsert(
                ['persona_id' => $melanyId, 'fecha_ficha' => now()->toDateString()],
                [
                    'actividad_fisica' => 'Entrenamiento funcional 4 veces por semana',
                    'objetivo' => 'Tonificacion, mejora cardiovascular y control de grasa corporal.',
                    'observaciones' => 'Cliente activa y con buena adherencia al plan de entrenamiento.',
                    'registrado_por' => DB::table('seguridad.usuarios')->where('email', 'trainer@revive.com')->value('id')
                        ?: DB::table('seguridad.usuarios')->orderBy('id')->value('id'),
                    'sede_id' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $fichaId = DB::table('salud.fichas_tecnicas')
                ->where('persona_id', $melanyId)
                ->orderByDesc('id')
                ->value('id');

            if ($fichaId) {
                DB::table('salud.ficha_mediciones')->insert([
                    'ficha_tecnica_id' => $fichaId,
                    'peso_kg' => 62.40,
                    'talla_cm' => 164,
                    'imc' => 23.20,
                    'cintura_cm' => 76,
                    'grasa_corporal_pct' => 24.50,
                    'masa_magra_kg' => 47.10,
                    'created_at' => now(),
                ]);

                $patologiaId = DB::table('salud.catalogo_patologias')->where('nombre', 'Dolor lumbar')->value('id');

                if ($patologiaId) {
                    DB::table('salud.ficha_patologias')->updateOrInsert(
                        ['ficha_tecnica_id' => $fichaId, 'patologia_id' => $patologiaId],
                        ['detalle' => 'Controlada con movilidad y fortalecimiento de core.', 'created_at' => now()]
                    );
                }
            }
        }

        if ($consumidorFinalId) {
            DB::table('core.persona_tipo_detalle')->updateOrInsert(
                ['persona_id' => $consumidorFinalId, 'tipo_id' => DB::table('core.persona_tipos')->where('codigo', 'CLIENTE')->value('id')],
                [
                    'activo' => true,
                    'fecha_inicio' => now()->toDateString(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $categoriasPos = [
            ['nombre' => 'Desayunos', 'descripcion' => 'Menu de desayunos Revive', 'estado' => 1],
            ['nombre' => 'Almuerzos', 'descripcion' => 'Menu ejecutivo del dia', 'estado' => 1],
            ['nombre' => 'Bebidas', 'descripcion' => 'Bebidas frias y calientes', 'estado' => 1],
            ['nombre' => 'Snacks', 'descripcion' => 'Acompanamientos y snacks', 'estado' => 1],
            ['nombre' => 'Postres', 'descripcion' => 'Postres de la casa', 'estado' => 1],
        ];

        foreach ($categoriasPos as $categoria) {
            DB::table('inventario.categorias_producto')->updateOrInsert(
                ['nombre' => $categoria['nombre']],
                [
                    'descripcion' => $categoria['descripcion'],
                    'estado' => $categoria['estado'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $categoriasByName = DB::table('inventario.categorias_producto')
            ->whereIn('nombre', array_column($categoriasPos, 'nombre'))
            ->pluck('id', 'nombre');

        $productosPos = [
            ['codigo' => 'POS-DES-001', 'nombre' => 'Tortilla de huevo con patacon', 'categoria' => 'Desayunos', 'descripcion' => 'Desayuno manabita tradicional', 'precio_venta' => 2.00, 'precio_costo' => 1.20, 'stock' => 25, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-DES-002', 'nombre' => 'Tigrillo mixto', 'categoria' => 'Desayunos', 'descripcion' => 'Verde, queso, huevo y chicharron', 'precio_venta' => 2.50, 'precio_costo' => 1.55, 'stock' => 20, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-DES-003', 'nombre' => 'Bolon queso/chicharron', 'categoria' => 'Desayunos', 'descripcion' => 'Bolon artesanal manabita', 'precio_venta' => 2.00, 'precio_costo' => 1.10, 'stock' => 18, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-DES-004', 'nombre' => 'Maria Pipona', 'categoria' => 'Desayunos', 'descripcion' => 'Especial de la casa', 'precio_venta' => 2.00, 'precio_costo' => 1.18, 'stock' => 16, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-ALM-001', 'nombre' => 'Almuerzo ejecutivo', 'categoria' => 'Almuerzos', 'descripcion' => 'Sopa, plato fuerte y bebida', 'precio_venta' => 3.50, 'precio_costo' => 2.20, 'stock' => 22, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-ALM-002', 'nombre' => 'Seco de pollo', 'categoria' => 'Almuerzos', 'descripcion' => 'Arroz, maduro y ensalada', 'precio_venta' => 3.25, 'precio_costo' => 2.05, 'stock' => 17, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-BEB-001', 'nombre' => 'Jugo natural', 'categoria' => 'Bebidas', 'descripcion' => 'Fruta de temporada', 'precio_venta' => 1.00, 'precio_costo' => 0.45, 'stock' => 40, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-BEB-002', 'nombre' => 'Cafe pasado', 'categoria' => 'Bebidas', 'descripcion' => 'Cafe caliente tradicional', 'precio_venta' => 0.75, 'precio_costo' => 0.28, 'stock' => 35, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-SNK-001', 'nombre' => 'Empanada de verde', 'categoria' => 'Snacks', 'descripcion' => 'Rellena de queso', 'precio_venta' => 1.25, 'precio_costo' => 0.70, 'stock' => 28, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-SNK-002', 'nombre' => 'Porcion de chicharron', 'categoria' => 'Snacks', 'descripcion' => 'Acompanante adicional', 'precio_venta' => 0.50, 'precio_costo' => 0.22, 'stock' => 26, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-POS-001', 'nombre' => 'Tres leches', 'categoria' => 'Postres', 'descripcion' => 'Porcion individual', 'precio_venta' => 1.75, 'precio_costo' => 0.92, 'stock' => 15, 'ubicacion' => 'CAFETERIA-HOME'],
            ['codigo' => 'POS-POS-002', 'nombre' => 'Flan de la casa', 'categoria' => 'Postres', 'descripcion' => 'Postre frio artesanal', 'precio_venta' => 1.50, 'precio_costo' => 0.80, 'stock' => 15, 'ubicacion' => 'CAFETERIA-HOME'],
        ];

        foreach ($productosPos as $producto) {
            $categoriaId = $categoriasByName[$producto['categoria']] ?? null;
            if (!$categoriaId) {
                continue;
            }

            $existingId = DB::table('inventario.productos')
                ->where('codigo', $producto['codigo'])
                ->value('id');

            if ($existingId) {
                DB::table('inventario.productos')
                    ->where('id', $existingId)
                    ->update([
                        'nombre' => $producto['nombre'],
                        'descripcion' => $producto['descripcion'],
                        'categoria_id' => $categoriaId,
                        'marca' => 'REVIVE CAFE',
                        'modelo' => 'POS',
                        'sku' => $producto['codigo'],
                        'unidad_medida' => 'unidad',
                        'controla_stock' => true,
                        'permite_decimales' => false,
                        'maneja_lotes' => false,
                        'maneja_vencimiento' => false,
                        'stock_minimo' => 5,
                        'stock_maximo' => 100,
                        'estado' => 1,
                        'updated_by' => $firstUserId,
                        'updated_at' => now(),
                    ]);

                $productoId = $existingId;
            } else {
                $productoId = DB::table('inventario.productos')->insertGetId([
                    'codigo' => $producto['codigo'],
                    'nombre' => $producto['nombre'],
                    'descripcion' => $producto['descripcion'],
                    'categoria_id' => $categoriaId,
                    'marca' => 'REVIVE CAFE',
                    'modelo' => 'POS',
                    'sku' => $producto['codigo'],
                    'codigo_barras' => null,
                    'unidad_medida' => 'unidad',
                    'controla_stock' => true,
                    'permite_decimales' => false,
                    'maneja_lotes' => false,
                    'maneja_vencimiento' => false,
                    'stock_minimo' => 5,
                    'stock_maximo' => 100,
                    'estado' => 1,
                    'imagen_url' => null,
                    'created_by' => $firstUserId,
                    'updated_by' => $firstUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('inventario.producto_stock_sede')->updateOrInsert(
                ['producto_id' => $productoId, 'sede_id' => 1],
                [
                    'stock_actual' => $producto['stock'],
                    'stock_reservado' => 0,
                    'stock_disponible' => $producto['stock'],
                    'stock_minimo' => 5,
                    'ubicacion' => $producto['ubicacion'],
                    'estado' => 1,
                    'created_by' => $firstUserId,
                    'updated_by' => $firstUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            foreach ([
                'COSTO' => $producto['precio_costo'],
                'VENTA' => $producto['precio_venta'],
            ] as $tipoPrecio => $monto) {
                $precioId = DB::table('inventario.producto_precios')
                    ->where('producto_id', $productoId)
                    ->where('tipo_precio', $tipoPrecio)
                    ->where('estado', 1)
                    ->orderByDesc('id')
                    ->value('id');

                if ($precioId) {
                    DB::table('inventario.producto_precios')
                        ->where('id', $precioId)
                        ->update([
                            'moneda' => 'USD',
                            'monto' => $monto,
                            'updated_by' => $firstUserId,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('inventario.producto_precios')->insert([
                        'producto_id' => $productoId,
                        'sede_id' => null,
                        'tipo_precio' => $tipoPrecio,
                        'moneda' => 'USD',
                        'monto' => $monto,
                        'vigencia_inicio' => now(),
                        'vigencia_fin' => null,
                        'estado' => 1,
                        'created_by' => $firstUserId,
                        'updated_by' => $firstUserId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
