<?php

namespace App\Services\Seguridad;

use App\Services\Institucional\EstructuraInstitucionalService;
use App\Services\Notificaciones\NotificacionUsuarioService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class CargaMasivaUsuarioService
{
    public function __construct(
        private readonly UsuarioService $usuarioService,
        private readonly EstructuraInstitucionalService $estructuraService,
        private readonly NotificacionUsuarioService $notificacionService,
    ) {}

    public function escribirPlantilla(): void
    {
        $roles = DB::table('seguridad.cpu_userrole')->where('activo', true)->orderBy('role')->pluck('role')->all();
        if ($roles === []) {
            throw new \RuntimeException('No existen roles activos para generar la plantilla.');
        }

        $libro = new Spreadsheet;
        $usuarios = $libro->getActiveSheet();
        $usuarios->setTitle('Usuarios');
        $contextos = $this->estructuraService->contextos();
        if ($contextos === []) {
            throw new \RuntimeException('No existen contextos institucionales activos.');
        }
        $usuarios->fromArray(['nombres', 'apellidos', 'cedula', 'email', 'rol', 'contexto', 'estado'], null, 'A1');
        $usuarios->fromArray(['Ana', 'Pérez López', '1300000001', 'ana.perez@ejemplo.com', $roles[0] ?? '', $contextos[0]['nombre'], 'ACTIVO'], null, 'A2');
        $usuarios->getStyle('A1:G1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $usuarios->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF004987');
        foreach (range('A', 'G') as $columna) {
            $usuarios->getColumnDimension($columna)->setWidth($columna === 'D' ? 34 : 22);
        }
        $usuarios->freezePane('A2');
        $usuarios->setAutoFilter('A1:G1001');
        $usuarios->getStyle('C2:C1001')->getNumberFormat()->setFormatCode('@');

        $hojaRoles = $libro->createSheet()->setTitle('Roles');
        $hojaRoles->setCellValue('A1', 'Roles activos');
        foreach ($roles as $indice => $rol) {
            $hojaRoles->setCellValue('A'.($indice + 2), $rol);
        }
        $hojaRoles->getStyle('A1')->getFont()->setBold(true);
        $hojaRoles->getColumnDimension('A')->setWidth(35);
        $hojaRoles->getProtection()->setSheet(true);
        $hojaRoles->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $libro->addNamedRange(new NamedRange('RolesActivos', $hojaRoles, '$A$2:$A$'.(count($roles) + 1)));
        $hojaContextos = $libro->createSheet()->setTitle('Contextos');
        $hojaContextos->setCellValue('A1', 'Contextos activos');
        foreach ($contextos as $i => $c) {
            $hojaContextos->setCellValue('A'.($i + 2), $c['nombre']);
        }$hojaContextos->getProtection()->setSheet(true);
        $hojaContextos->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $libro->addNamedRange(new NamedRange('ContextosActivos', $hojaContextos, '$A$2:$A$'.(count($contextos) + 1)));

        $instrucciones = $libro->createSheet()->setTitle('Instrucciones');
        $instrucciones->fromArray([
            ['Campo', 'Obligatorio', 'Descripción'],
            ['nombres', 'Sí', 'Nombres del usuario.'], ['apellidos', 'Sí', 'Apellidos del usuario.'],
            ['cedula', 'Sí', 'Cédula única; la columna está configurada como texto.'], ['email', 'Sí', 'Correo válido y único.'],
            ['rol', 'Sí', 'Seleccione un rol activo de la lista.'],
            ['contexto', 'Sí', 'Seleccione un contexto institucional activo.'], ['estado', 'No', 'ACTIVO o INACTIVO; por defecto ACTIVO.'],
            ['activación de cuenta', 'Automática', 'El sistema registra una invitación segura para que el usuario establezca su propia contraseña.'],
        ], null, 'A1');
        $instrucciones->getStyle('A1:C1')->getFont()->setBold(true);
        $instrucciones->getColumnDimension('A')->setWidth(18);
        $instrucciones->getColumnDimension('B')->setWidth(14);
        $instrucciones->getColumnDimension('C')->setWidth(70);
        $instrucciones->getStyle('C1:C9')->getAlignment()->setWrapText(true);

        $validacionRol = new DataValidation;
        $validacionRol->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)->setAllowBlank(false)->setShowDropDown(true)->setShowErrorMessage(true)->setShowInputMessage(true)->setPromptTitle('Rol del usuario')->setPrompt('Seleccione un rol de la lista.')->setErrorTitle('Rol inválido')->setError('Seleccione un rol de la lista.')->setFormula1('RolesActivos');
        $validacionEstado = new DataValidation;
        $validacionContexto = (new DataValidation)->setType(DataValidation::TYPE_LIST)->setAllowBlank(false)->setShowDropDown(true)->setShowErrorMessage(true)->setFormula1('ContextosActivos');
        $validacionEstado->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setFormula1('"ACTIVO,INACTIVO"');
        $usuarios->getCell('E2')->setDataValidation($validacionRol);
        $usuarios->getCell('F2')->setDataValidation($validacionContexto);
        $usuarios->getCell('G2')->setDataValidation($validacionEstado);
        $usuarios->getCell('E2')->getDataValidation()->setSqref('E2:E1001');
        $usuarios->getCell('F2')->getDataValidation()->setSqref('F2:F1001');
        $usuarios->getCell('G2')->getDataValidation()->setSqref('G2:G1001');
        $libro->setActiveSheetIndex(0);

        (new Xlsx($libro))->save('php://output');
        $libro->disconnectWorksheets();
    }

    public function leerArchivo(UploadedFile $archivo): array
    {
        $libro = IOFactory::load($archivo->getRealPath());
        $hoja = $libro->getSheetByName('Usuarios') ?? $libro->getActiveSheet();
        $matriz = $hoja->toArray(null, true, true, false);
        $encabezados = array_map(fn ($valor) => trim((string) $valor), array_shift($matriz) ?? []);
        $encabezadosNormalizados = array_map(fn ($valor) => Str::of($valor)->ascii()->lower()->toString(), $encabezados);
        $obligatorios = ['nombres', 'apellidos', 'cedula', 'email', 'rol', 'contexto'];
        if (array_diff($obligatorios, $encabezadosNormalizados) !== []) {
            throw new \RuntimeException('La hoja Usuarios no contiene todas las columnas obligatorias de la plantilla.');
        }

        $matriz = array_values(array_filter($matriz, fn (array $valores) => array_filter($valores, fn ($valor) => trim((string) $valor) !== '') !== []));
        if (count($matriz) > 1000) {
            throw new \RuntimeException('El archivo supera el máximo permitido de 1000 usuarios.');
        }

        $filas = [];
        foreach ($matriz as $valores) {
            $filas[] = array_combine($encabezados, array_pad(array_slice($valores, 0, count($encabezados)), count($encabezados), null));
        }
        $libro->disconnectWorksheets();
        if (! $filas) {
            throw new \RuntimeException('La hoja Usuarios no contiene registros.');
        }

        return $filas;
    }

    public function validar(array $filas): array
    {
        $emailsArchivo = [];
        $cedulasArchivo = [];

        return collect($filas)->map(function (array $fila, int $indice) use (&$emailsArchivo, &$cedulasArchivo): array {
            $datos = $this->normalizar($fila);
            $errores = [];
            $numeroFila = $indice + 2;

            if ($datos['nombres'] === '') {
                $errores[] = 'Los nombres son obligatorios.';
            }
            if ($datos['apellidos'] === '') {
                $errores[] = 'Los apellidos son obligatorios.';
            }
            if ($datos['cedula'] === '') {
                $errores[] = 'La cédula es obligatoria.';
            }
            if ($datos['email'] === '' || ! filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo no es válido.';
            }
            if (! in_array($datos['estado'], ['ACTIVO', 'INACTIVO'], true)) {
                $errores[] = 'El estado debe ser ACTIVO o INACTIVO.';
            }

            $rol = $this->resolverRol($datos['rol']);
            if (! $rol) {
                $errores[] = 'El rol no existe o está inactivo.';
            }
            $contexto = $this->resolverContexto($datos['contexto']);
            if (! $contexto) {
                $errores[] = 'El contexto institucional no existe o está inactivo.';
            }

            if ($datos['email'] !== '') {
                if (isset($emailsArchivo[$datos['email']])) {
                    $errores[] = "El correo está repetido en la fila {$emailsArchivo[$datos['email']]}.";
                }
                $emailsArchivo[$datos['email']] = $numeroFila;
                if (DB::table('seguridad.users')->whereRaw('LOWER(email) = ?', [$datos['email']])->exists()) {
                    $errores[] = 'El correo ya pertenece a un usuario existente.';
                }
            }

            if ($datos['cedula'] !== '') {
                if (isset($cedulasArchivo[$datos['cedula']])) {
                    $errores[] = "La cédula está repetida en la fila {$cedulasArchivo[$datos['cedula']]}.";
                }
                $cedulasArchivo[$datos['cedula']] = $numeroFila;
                if (DB::table('seguridad.users')->where('cedula', $datos['cedula'])->exists()) {
                    $errores[] = 'La cédula ya pertenece a un usuario existente.';
                }
            }

            return [
                'fila' => $numeroFila,
                'estado' => $errores ? 'error' : 'valido',
                'errores' => $errores,
                'datos' => $datos + ['usr_tipo' => $rol?->id_userrole, 'rol_nombre' => $rol?->role, 'id_contexto' => $contexto?->id_contexto],
            ];
        })->all();
    }

    public function procesar(array $filas, bool $notificar = false, ?int $solicitadoPor = null): array
    {
        $validadas = $this->validar($filas);
        $creados = [];
        $errores = [];

        foreach ($validadas as $resultado) {
            if ($resultado['estado'] !== 'valido') {
                $errores[] = $resultado;

                continue;
            }

            $datos = $resultado['datos'];
            // Clave interna aleatoria no comunicada; se reemplaza mediante el enlace de activación.
            $password = Str::password(32);

            try {
                $usuario = DB::transaction(function () use ($datos, $password, $solicitadoPor) {
                    $usuario = $this->usuarioService->crear([
                        'nombres' => $datos['nombres'],
                        'apellidos' => $datos['apellidos'] ?: null,
                        'cedula' => $datos['cedula'] ?: null,
                        'email' => $datos['email'],
                        'password' => $password,
                        'usr_tipo' => (int) $datos['usr_tipo'],
                        'usr_estado' => $datos['estado'] === 'ACTIVO' ? 1 : 0,
                    ]);
                    $this->estructuraService->asignarUsuario($usuario->id, [(int) $datos['id_contexto']]);
                    $this->notificacionService->registrarPendiente($usuario, $solicitadoPor);

                    return $usuario;
                });

                $estadoNotificacion = 'PENDIENTE';
                $errorNotificacion = null;
                if ($notificar) {
                    try {
                        $this->notificacionService->solicitar($usuario, $solicitadoPor);
                        $estadoNotificacion = 'EN_COLA';
                    } catch (Throwable $e) {
                        $estadoNotificacion = 'ERROR';
                        $errorNotificacion = $e->getMessage();
                    }
                }

                $creados[] = [
                    'fila' => $resultado['fila'],
                    'id' => $usuario->id,
                    'nombre' => $usuario->name,
                    'email' => $usuario->email,
                    'estado_notificacion' => $estadoNotificacion,
                    'error_notificacion' => $errorNotificacion,
                ];
            } catch (Throwable $e) {
                $errores[] = $resultado + ['estado' => 'error', 'errores' => [$e->getMessage()]];
            }
        }

        return [
            'resumen' => [
                'total' => count($filas),
                'creados' => count($creados),
                'errores' => count($errores),
                'notificaciones_encoladas' => collect($creados)->where('estado_notificacion', 'EN_COLA')->count(),
                'notificaciones_error' => collect($creados)->where('estado_notificacion', 'ERROR')->count(),
            ],
            'creados' => $creados,
            'errores' => $errores,
        ];
    }

    private function normalizar(array $fila): array
    {
        $normalizada = [];
        foreach ($fila as $clave => $valor) {
            $clave = Str::of((string) $clave)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
            $normalizada[$clave] = trim((string) ($valor ?? ''));
        }

        return [
            'nombres' => $normalizada['nombres'] ?? $normalizada['nombre'] ?? '',
            'apellidos' => $normalizada['apellidos'] ?? '',
            'cedula' => $normalizada['cedula'] ?? $normalizada['identificacion'] ?? '',
            'email' => mb_strtolower($normalizada['email'] ?? $normalizada['correo'] ?? ''),
            'rol' => $normalizada['rol'] ?? '',
            'contexto' => $normalizada['contexto'] ?? '',
            'estado' => mb_strtoupper($normalizada['estado'] ?? 'ACTIVO'),
        ];
    }

    private function resolverRol(string $valor): ?object
    {
        return DB::table('seguridad.cpu_userrole')
            ->where('activo', true)
            ->where(function ($query) use ($valor): void {
                if (ctype_digit($valor)) {
                    $query->where('id_userrole', (int) $valor);
                } else {
                    $query->whereRaw('UPPER(TRIM(role)) = ?', [mb_strtoupper(trim($valor))]);
                }
            })
            ->first();
    }

    private function resolverContexto(string $valor): ?object
    {
        $contexto = collect($this->estructuraService->contextos())->first(fn ($c) => mb_strtoupper(trim($c['nombre'])) === mb_strtoupper(trim($valor)));

        return $contexto ? (object) $contexto : null;
    }
}
