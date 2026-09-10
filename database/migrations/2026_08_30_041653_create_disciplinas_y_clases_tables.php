<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        // Tablas
        Schema::create('gimnasio.disciplinas', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 100)->unique();
            $t->text('descripcion')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::create('gimnasio.clases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('disciplina_id')->constrained('gimnasio.disciplinas')->restrictOnDelete();
            $t->foreignId('entrenador_id')->constrained('seguridad.users')->restrictOnDelete();
            $t->unsignedBigInteger('sede_id');
            $t->string('salon', 100)->nullable();
            $t->integer('capacidad_maxima')->default(20);
            $t->time('hora_inicio');
            $t->time('hora_fin');
            $t->jsonb('dias_semana'); // ["LUN", "MIE", "VIE"]
            $t->boolean('activo')->default(true);
            $t->timestamps();

            $t->foreign('sede_id')->references('id_sede')->on('institucional.sedes')->restrictOnDelete();
        });

        // Menús y Permisos
        $now = Carbon::now();
        $menuId = DB::table('seguridad.cpu_usermenu')->where('menu', 'Operaciones')->value('id_usermenu');

        if ($menuId) {
            $submenus = [
                ['id_menu' => 'GIMNASIO-DISCIPLINAS', 'nombre' => 'Disciplinas', 'accion' => '/disciplinas', 'clave_pagina' => 'DisciplinasPage', 'icono' => 'sports_gymnastics'],
                ['id_menu' => 'GIMNASIO-CLASES', 'nombre' => 'Clases y Horarios', 'accion' => '/clases', 'clave_pagina' => 'ClasesPage', 'icono' => 'schedule'],
            ];

            $adminRoleId = DB::table('seguridad.cpu_userrole')->where('role', 'ADMINISTRADOR')->value('id_userrole');
            $recepcionistaRoleId = DB::table('seguridad.cpu_userrole')->where('role', 'RECEPCIONISTA')->value('id_userrole');
            $entrenadorRoleId = DB::table('seguridad.cpu_userrole')->where('role', 'ENTRENADOR')->value('id_userrole');

            $orden = 4; // Después de Deportistas(1), Planes(2), Membresias(3)
            foreach ($submenus as $sub) {
                DB::table('seguridad.cpu_pagina_sistema')->insertOrIgnore([
                    'id_menu' => $sub['id_menu'],
                    'clave_pagina' => $sub['clave_pagina'],
                    'created_at' => $now,
                    'updated_at' => $now
                ]);

                if ($adminRoleId) {
                    DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore([
                        'id_userrole' => $adminRoleId,
                        'id_usermenu' => $menuId,
                        'nombre' => $sub['nombre'],
                        'icono' => $sub['icono'],
                        'accion' => $sub['accion'],
                        'id_menu' => $sub['id_menu'],
                        'activo' => true,
                        'orden' => $orden,
                        'created_at' => $now,
                        'updated_at' => $now
                    ]);
                }

                if ($recepcionistaRoleId) {
                    DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore([
                        'id_userrole' => $recepcionistaRoleId,
                        'id_usermenu' => $menuId,
                        'nombre' => $sub['nombre'],
                        'icono' => $sub['icono'],
                        'accion' => $sub['accion'],
                        'id_menu' => $sub['id_menu'],
                        'activo' => true,
                        'orden' => $orden,
                        'created_at' => $now,
                        'updated_at' => $now
                    ]);
                }
                
                // Entrenador solo ve Clases
                if ($entrenadorRoleId && $sub['id_menu'] === 'GIMNASIO-CLASES') {
                    DB::table('seguridad.cpu_userrolefunction')->insertOrIgnore([
                        'id_userrole' => $entrenadorRoleId,
                        'id_usermenu' => $menuId,
                        'nombre' => $sub['nombre'],
                        'icono' => $sub['icono'],
                        'accion' => $sub['accion'],
                        'id_menu' => $sub['id_menu'],
                        'activo' => true,
                        'orden' => $orden,
                        'created_at' => $now,
                        'updated_at' => $now
                    ]);
                }

                $orden++;
            }
        }
    }

    public function down(): void
    {
        $menus = ['GIMNASIO-DISCIPLINAS', 'GIMNASIO-CLASES'];
        
        DB::table('seguridad.cpu_userfunction')->whereIn('id_menu', $menus)->delete();
        DB::table('seguridad.cpu_userrolefunction')->whereIn('id_menu', $menus)->delete();
        DB::table('seguridad.cpu_pagina_sistema')->whereIn('id_menu', $menus)->delete();
        
        Schema::dropIfExists('gimnasio.clases');
        Schema::dropIfExists('gimnasio.disciplinas');
    }
};
