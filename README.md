# Revive Backend

API Laravel del Revive institucional.

## Propósito

Proporciona el núcleo reutilizable para autenticación, usuarios, roles, menús, funciones, permisos y preferencias. Los módulos de negocio futuros deben extender esta base sin duplicar la seguridad transversal.

## Stack

- PHP 8.3+
- Laravel 13
- PostgreSQL
- PHPUnit 12

## Convenciones principales

- Endpoints administrativos bajo `/api/base`.
- Rutas por módulo en `routes/base`.
- Reglas de negocio en `app/Services`.
- Controladores HTTP en `app/Http/Controllers/Api`.
- Respuestas JSON mediante `App\Support\ApiResponse`.
- Esquema transversal PostgreSQL: `seguridad`.
- Las rutas administrativas protegidas deben aplicar `base.auth` y `base.permiso`.

## Seguridad

El flujo de autorización usa dos capas:

1. `base.auth`: valida token, vigencia, usuario activo y rol activo.
2. `base.permiso`: valida que el usuario tenga activa al menos una de las funciones requeridas por la ruta.

Ocultar una opción en el frontend no reemplaza la autorización del backend.

## Instalación local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Configura PostgreSQL en `.env` antes de ejecutar migraciones. Si partes de una base restaurada, revisa primero:

```bash
php artisan migrate:status
```

## Pruebas

```bash
composer test
```

## Documentación

La arquitectura, convenciones, decisiones de esquema y estado de módulos se mantienen en el repositorio `train-gym-docs`.
