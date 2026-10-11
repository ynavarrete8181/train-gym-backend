# Revive · Producción en Railway

Fecha de formalización: 2026-10-11

## Arquitectura activa

- `train-gym-web`: frontend React/Vite.
- `train-gym-backend`: API Laravel.
- `train-gym-worker`: procesa colas `default,correos,push`.
- `train-gym-scheduler`: ejecuta `php artisan schedule:work`.
- `train-gym-reverb`: Laravel Reverb/WebSockets.
- `Postgres-T2pc`: PostgreSQL productivo.
- `train-gym-backup`: respaldo lógico diario de PostgreSQL.

## Dominios

- Frontend: `https://revivesport.up.railway.app`
- API: `https://revivesportapi.up.railway.app`
- Reverb: `https://train-gym-reverb-production.up.railway.app`
- Healthcheck: `https://revivesportapi.up.railway.app/api/health`

## Rama de producción

- `main`: producción estable.
- `dev-revive`: desarrollo.
- Railway debe desplegar producción únicamente desde `main`.

El flujo normal es:

1. desarrollar y validar en `dev-revive`;
2. abrir PR hacia `main`;
3. revisar;
4. mergear;
5. Railway despliega `main`.

## Bootstrap de root

La base limpia se inicializó con un único usuario técnico `SUPERADMINISTRADOR`.

El seeder `ProductionRootSeeder` existe solo como mecanismo de bootstrap inicial. No debe ejecutarse automáticamente en cada despliegue.

El pre-deploy productivo del backend debe mantenerse como:

```bash
php artisan migrate --force
```

La contraseña bootstrap no debe persistir como secreto reutilizable del pipeline después de inicializar la cuenta.

## Worker

Comando:

```bash
php artisan queue:work database --queue=default,correos,push --tries=3 --timeout=120 --sleep=1
```

Política: reinicio permanente ante caída.

## Scheduler

Comando:

```bash
php artisan schedule:work
```

Se usa servicio persistente para respetar los horarios definidos por Laravel, incluidos los que requieren resolución inferior a cinco minutos.

## Reverb

Comando:

```bash
php artisan reverb:start --host=0.0.0.0 --port=$PORT
```

PHP de producción requiere `ext-pcntl` para el manejo correcto de señales POSIX.

## Backups lógicos

Servicio: `train-gym-backup`.

- Fuente: Alpine.
- Cliente: PostgreSQL 18.
- Volumen: `revive-backups-volume`.
- Formato: custom de `pg_dump` (`.dump`).
- Frecuencia: diaria a las 08:00 UTC / 03:00 America/Guayaquil.
- Retención: 14 días.
- Los respaldos de tamaño 0 se eliminan automáticamente.

El comando de respaldo usa `DATABASE_URL` como referencia interna al PostgreSQL productivo y nunca debe contener credenciales hardcodeadas en Git.

### Restauración

Ante una restauración:

1. detener temporalmente escrituras del sistema;
2. escoger el archivo `revive-YYYYMMDDTHHMMSSZ.dump`;
3. restaurar en una base aislada primero;
4. ejecutar pruebas de integridad;
5. solo después realizar el cambio sobre producción;
6. verificar `/api/health`, login, caja, ventas, membresías, worker, scheduler y Reverb.

No sobrescribir directamente producción sin validar el respaldo en una instancia separada.

## Base de datos limpia

Producción se creó desde migraciones. No deben entrar:

- usuarios demo;
- clientes/deportistas de prueba;
- ventas de prueba;
- pagos de prueba;
- membresías demo;
- inventario demo;
- cajas de prueba;
- alertas/logs históricos locales.

Las migraciones históricas de importación están protegidas para no poblar automáticamente `APP_ENV=production`.

## Verificación mínima después de cada release

1. `/api/health` responde correctamente.
2. Login funciona.
3. `train-gym-worker` permanece activo.
4. `train-gym-scheduler` ejecuta el ciclo por minuto.
5. Reverb inicia en el puerto asignado por Railway.
6. Frontend responde por el dominio oficial.
7. No existen errores 5xx nuevos.
8. Migraciones quedaron completas.
9. Auditoría y logs continúan registrando.
10. El backup diario mantiene archivos recientes.

## Seguridad

- `APP_ENV=production`.
- `APP_DEBUG=false`.
- CORS limitado al frontend oficial.
- Secretos únicamente en Railway.
- No guardar `.env` productivo en Git.
- No reutilizar credenciales bootstrap.
- Mantener permisos y alcance por sede validados en backend.
