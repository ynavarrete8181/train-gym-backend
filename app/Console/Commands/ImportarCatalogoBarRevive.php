<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportarCatalogoBarRevive extends Command
{
    protected $signature = 'revive:importar-bar {--aplicar : Confirma escritura; sin esta opción solo informa}';
    protected $description = 'Conciliación segura del catálogo de Bar Revive (12 artículos, sin alterar stocks existentes).';

    private const PRODUCTOS = [
        ['Agua con gas Splendor', '1.00', 'Bebidas'],
        ['Agua de coco', '1.00', 'Bebidas'],
        ['Agua Gar', '0.50', 'Bebidas'],
        ['Benefit Imperial Immune 330ml', '1.50', 'Bebidas'],
        ['Cielo', '1.00', 'Bebidas'],
        ['Imperial 560ml', '1.00', 'Bebidas'],
        ['Michelada', '2.00', 'Bebidas'],
        ['Postre', '2.50', 'Servicios complementarios'],
        ['Powerade azul 600ml', '1.00', 'Bebidas'],
        ['Powerade Grande 1000ml', '1.50', 'Bebidas'],
        ['Powerade pequeño 350ml', '0.75', 'Bebidas'],
        ['Sporade ponche de frutos 625ml', '0.75', 'Bebidas'],
    ];

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $this->warn($aplicar ? 'MODO ESCRITURA: solo productos nuevos y sus precios/stock cero por sede.' : 'SIMULACIÓN: ningún dato será modificado.');
        $sedes = DB::table('institucional.sedes')->where('activo',true)
            ->where('maneja_inventario',true)->pluck('id_sede');
        if ($sedes->isEmpty()) {
            $this->error('No hay sedes activas con inventario. No se hará ninguna importación.');
            return self::FAILURE;
        }

        $duplicados = [];
        foreach (self::PRODUCTOS as [$nombre, $precio, $categoria]) {
            $coincidencias = DB::table('inventario.productos')
                ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($nombre),'UTF-8')])->get(['id','codigo']);
            if ($coincidencias->count() > 1) {
                $duplicados[] = $nombre;
            }
            $this->line(($coincidencias->count() ? 'EXISTE' : 'CREAR')." | {$nombre} | $ {$precio} | coincidencias: ".$coincidencias->count());
        }
        if ($duplicados) {
            $this->error('Nombres duplicados en BD: '.implode(', ',$duplicados).'. Conciliar manualmente antes de ejecutar.');
            return self::FAILURE;
        }
        if (!$aplicar) {
            $this->info('Para importar se requiere respaldo verificado, conciliación y ejecutar --aplicar en el entorno correcto.');
            return self::SUCCESS;
        }
        if (!$this->confirm('¿Confirmas que ya verificaste el respaldo de producción y la lista de productos?')) {
            return self::FAILURE;
        }
        DB::transaction(function () use ($sedes) {
            foreach (self::PRODUCTOS as [$nombre,$precio,$categoria]) {
                $existente = DB::table('inventario.productos')
                    ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($nombre),'UTF-8')])
                    ->lockForUpdate()->first();
                if ($existente) {
                    $this->line('SIN CAMBIOS: '.$nombre.' (se conservan stock, precio e imagen existentes)');
                    continue;
                }
                $categoriaId = DB::table('inventario.categorias_producto')->where('nombre',$categoria)->value('id');
                if (!$categoriaId) {
                    $categoriaId = DB::table('inventario.categorias_producto')->insertGetId([
                        'nombre'=>$categoria, 'descripcion'=>'Productos Bar Revive',
                        'activo'=>true, 'created_at'=>now(), 'updated_at'=>now(),
                    ]);
                }
                $codigo = 'BAR-'.strtoupper(Str::slug($nombre,'-'));
                $codigo = substr($codigo,0,60);
                if (DB::table('inventario.productos')->where('codigo',$codigo)->exists()) {
                    throw new \RuntimeException('Colisión de código: '.$codigo);
                }
                $id = DB::table('inventario.productos')->insertGetId([
                    'categoria_id'=>$categoriaId, 'codigo'=>$codigo, 'nombre'=>$nombre,
                    'unidad_medida'=>'UNIDAD', 'precio_costo'=>0, 'precio_venta'=>$precio,
                    'stock_actual'=>0, 'stock_minimo'=>0,'controla_stock'=>true,
                    'maneja_lotes'=>false,'activo'=>true,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                foreach ($sedes as $sedeId) {
                    DB::table('inventario.producto_precios_sede')->insert([
                        'producto_id'=>$id,'sede_id'=>$sedeId,'precio'=>$precio,'activo'=>true,
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                    DB::table('inventario.producto_stock_sede')->insert([
                        'producto_id'=>$id,'sede_id'=>$sedeId,'stock_actual'=>0,
                        'stock_minimo'=>0,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
                $this->info('CREADO: '.$nombre.' ('.$sedes->count().' sedes)');
            }
        });
        $this->info('Carga terminada; ningún stock de productos anteriores fue modificado.');
        return self::SUCCESS;
    }
}
