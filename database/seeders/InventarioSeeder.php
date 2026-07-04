<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Services\Inventarios\ProductoMovimientoService;
use Illuminate\Http\Request;

class InventarioSeeder extends Seeder
{
    public function run()
    {
        // 1. Insertar Categorías si no existen
        $categorias = [
            ['nombre' => 'Suplementos Deportivos', 'descripcion' => 'Proteínas, creatinas, pre-entrenos, etc.'],
            ['nombre' => 'Bebidas e Hidratación', 'descripcion' => 'Aguas, isotónicos, energizantes.'],
            ['nombre' => 'Accesorios', 'descripcion' => 'Toallas, shakers, guantes, straps.'],
        ];

        foreach ($categorias as $cat) {
            $exists = DB::table('inventario.categorias_producto')->where('nombre', $cat['nombre'])->first();
            if (!$exists) {
                DB::table('inventario.categorias_producto')->insert([
                    'nombre' => $cat['nombre'],
                    'descripcion' => $cat['descripcion'],
                    'estado' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $catSuplementos = DB::table('inventario.categorias_producto')->where('nombre', 'Suplementos Deportivos')->value('id');
        $catBebidas = DB::table('inventario.categorias_producto')->where('nombre', 'Bebidas e Hidratación')->value('id');
        $catAccesorios = DB::table('inventario.categorias_producto')->where('nombre', 'Accesorios')->value('id');

        // 2. Insertar Productos
        $productos = [
            [
                'codigo' => 'PROT-001', 'nombre' => '100% Whey Protein Gold Standard - 5 lbs', 'categoria_id' => $catSuplementos,
                'marca' => 'Optimum Nutrition', 'unidad_medida' => 'UND', 'precio_venta' => 85.00,
                'imagen_url' => 'https://images.unsplash.com/photo-1593095948071-474c5cc2989d?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'PREW-001', 'nombre' => 'C4 Original Pre-Workout - 30 serv', 'categoria_id' => $catSuplementos,
                'marca' => 'Cellucor', 'unidad_medida' => 'UND', 'precio_venta' => 35.00,
                'imagen_url' => 'https://images.unsplash.com/photo-1579722820308-d74e571900a9?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'BCAA-001', 'nombre' => 'BCAA Amino X - 30 serv', 'categoria_id' => $catSuplementos,
                'marca' => 'BSN', 'unidad_medida' => 'UND', 'precio_venta' => 30.00,
                'imagen_url' => 'https://images.unsplash.com/photo-1726842348600-c66c2e2797b4?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'CREA-001', 'nombre' => 'Creatina Monohidratada Platinum - 400g', 'categoria_id' => $catSuplementos,
                'marca' => 'Muscletech', 'unidad_medida' => 'UND', 'precio_venta' => 25.00,
                'imagen_url' => 'https://images.unsplash.com/photo-1732563290993-602bb95108d2?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'AGUA-001', 'nombre' => 'Agua Mineral Vital - 500ml', 'categoria_id' => $catBebidas,
                'marca' => 'Vital', 'unidad_medida' => 'UND', 'precio_venta' => 1.00,
                'imagen_url' => 'https://images.unsplash.com/photo-1664527305901-db3d4e724d15?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'GATO-001', 'nombre' => 'Gatorade - 750ml', 'categoria_id' => $catBebidas,
                'marca' => 'Gatorade', 'unidad_medida' => 'UND', 'precio_venta' => 1.50,
                'imagen_url' => 'https://images.unsplash.com/photo-1622543925917-763c34d1a86e?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'QBAR-001', 'nombre' => 'Barra de Proteína Quest Bar', 'categoria_id' => $catSuplementos,
                'marca' => 'Quest', 'unidad_medida' => 'UND', 'precio_venta' => 3.00,
                'imagen_url' => 'https://images.unsplash.com/photo-1726676075271-d08aef815d79?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'TOAL-001', 'nombre' => 'Toalla Deportiva Microfibra', 'categoria_id' => $catAccesorios,
                'marca' => 'Revive Gym', 'unidad_medida' => 'UND', 'precio_venta' => 10.00,
                'imagen_url' => 'https://images.unsplash.com/photo-1679430887821-ddbcff722424?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'SHAK-001', 'nombre' => 'Shaker / Mezclador SmartShake - 600ml', 'categoria_id' => $catAccesorios,
                'marca' => 'SmartShake', 'unidad_medida' => 'UND', 'precio_venta' => 12.00,
                'imagen_url' => 'https://images.unsplash.com/photo-1594882645126-14020914d58d?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'BEB-001', 'nombre' => 'Imperial Cola', 'categoria_id' => $catBebidas,
                'marca' => 'Imperial', 'unidad_medida' => 'UND', 'precio_venta' => 1.50,
                'imagen_url' => 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'BEB-002', 'nombre' => 'Jugo Natural', 'categoria_id' => $catBebidas,
                'marca' => 'Genérico', 'unidad_medida' => 'UND', 'precio_venta' => 2.00,
                'imagen_url' => 'https://images.unsplash.com/photo-1600271886742-f049cd451bba?q=80&w=600&auto=format&fit=crop'
            ],
            [
                'codigo' => 'BEB-003', 'nombre' => 'Michelada Light', 'categoria_id' => $catBebidas,
                'marca' => 'Genérico', 'unidad_medida' => 'UND', 'precio_venta' => 3.50,
                'imagen_url' => 'https://images.unsplash.com/photo-1695285406073-0424d330c075?q=80&w=600&auto=format&fit=crop'
            ],
        ];

        // Ensure user exists for created_by
        $user_id = 1;

        // Ensure sede exists for stock
        $sede_id = DB::table('core.sedes')->value('id');
        if (!$sede_id) {
            $sede_id = DB::table('core.sedes')->insertGetId([
                'empresa_id' => 1, 'nombre' => 'Sede Principal', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()
            ]);
        }

        // Service
        $movimientoService = app(ProductoMovimientoService::class);

        foreach ($productos as $prod) {
            $p = DB::table('inventario.productos')->where('codigo', $prod['codigo'])->first();
            if (!$p) {
                // Insert Product
                $productoId = DB::table('inventario.productos')->insertGetId([
                    'codigo' => $prod['codigo'],
                    'nombre' => $prod['nombre'],
                    'categoria_id' => $prod['categoria_id'],
                    'marca' => $prod['marca'],
                    'unidad_medida' => $prod['unidad_medida'],
                    'controla_stock' => true,
                    'permite_decimales' => false,
                    'maneja_lotes' => false,
                    'maneja_vencimiento' => false,
                    'stock_minimo' => 5,
                    'stock_maximo' => 100,
                    'estado' => 1,
                    'imagen_url' => $prod['imagen_url'],
                    'created_by' => $user_id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Insert Price
                DB::table('inventario.producto_precios')->insert([
                    'producto_id' => $productoId,
                    'sede_id' => null, // Precio global
                    'tipo_precio' => 'VENTA',
                    'monto' => $prod['precio_venta'],
                    'moneda' => 'USD',
                    'estado' => 1,
                    'created_by' => $user_id,
                    'vigencia_inicio' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Inject initial stock (50)
                $req = new Request();
                $req->setUserResolver(function () use ($user_id) {
                    return (object) ['id' => $user_id];
                });
                
                try {
                    $movimientoService->registrarInventarioInicial([
                        'producto_id' => $productoId,
                        'sede_id' => $sede_id,
                        'cantidad' => 50,
                        'costo_unitario' => round($prod['precio_venta'] * 0.5, 2),
                    ], $req);
                } catch (\Exception $e) {
                    // Fallback to manual insert if validation fails
                    DB::table('inventario.producto_stock_sede')->insert([
                        'producto_id' => $productoId,
                        'sede_id' => $sede_id,
                        'stock_actual' => 50,
                        'updated_at' => now()
                    ]);
                    DB::table('inventario.movimientos_inventario')->insert([
                        'sede_id' => $sede_id,
                        'producto_id' => $productoId,
                        'tipo_movimiento' => 'ENTRADA',
                        'subtipo_movimiento' => 'INVENTARIO_INICIAL',
                        'cantidad' => 50,
                        'stock_resultante' => 50,
                        'motivo' => 'Inventario Inicial',
                        'fecha_movimiento' => now(),
                        'creado_por' => $user_id,
                        'created_at' => now()
                    ]);
                }
            }
        }
    }
}
