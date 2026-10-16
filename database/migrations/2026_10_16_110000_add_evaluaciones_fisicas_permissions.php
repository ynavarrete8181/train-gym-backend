<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private array $funciones = [
        'ENTRENAMIENTO-EVALUACIONES' => ['Evaluaciones físicas', '/evaluaciones-fisicas', 'EvaluacionesFisicasPage', 'fitness_center', 5],
        'ENTRENAMIENTO-EVALUACIONES-CREAR' => ['Registrar evaluación física', '/evaluaciones-fisicas', 'EvaluacionesFisicasPage', 'add_circle', 51],
        'ENTRENAMIENTO-EVALUACIONES-EDITAR' => ['Editar evaluación física', '/evaluaciones-fisicas', 'EvaluacionesFisicasPage', 'edit', 52],
        'ENTRENAMIENTO-EVALUACIONES-ANULAR' => ['Anular evaluación física', '/evaluaciones-fisicas', 'EvaluacionesFisicasPage', 'cancel', 53],
    ];
    public function up(): void {
        $menu = DB::table('seguridad.cpu_usermenu')->where('menu', 'Entrenamiento')->value('id_usermenu');
        $roles = DB::table('seguridad.cpu_userrole')->whereIn('role',['ADMINISTRADOR','ENTRENADOR'])->pluck('id_userrole');
        foreach($this->funciones as $codigo => [$nombre,$accion,$pagina,$icono,$orden]) {
            DB::table('seguridad.cpu_pagina_sistema')->updateOrInsert(['id_menu'=>$codigo],
                ['clave_pagina'=>$pagina,'created_at'=>now(),'updated_at'=>now()]);
            foreach($roles as $rol) {
                $atributos = [
                    'id_usermenu'=>$codigo==='ENTRENAMIENTO-EVALUACIONES' ? $menu : null,
                    'nombre'=>$nombre,'icono'=>$icono,'accion'=>$accion,
                    'activo'=>true,'orden'=>$orden,'updated_at'=>now(),
                ];
                DB::table('seguridad.cpu_userrolefunction')->updateOrInsert(
                    ['id_userrole'=>$rol,'id_menu'=>$codigo], $atributos+['created_at'=>now()]);
                $usuarios=DB::table('seguridad.users')->where('usr_tipo',$rol)->pluck('id');
                foreach($usuarios as $usuario) DB::table('seguridad.cpu_userfunction')->updateOrInsert(
                    ['id_users'=>$usuario,'id_menu'=>$codigo],
                    $atributos+['id_userrole'=>$rol,'created_at'=>now()]);
            }
        }
    }
    public function down(): void {
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu',array_keys($this->funciones))->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu',array_keys($this->funciones))->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu',array_keys($this->funciones))->delete();
    }
};
