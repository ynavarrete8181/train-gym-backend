<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS core;
CREATE SCHEMA IF NOT EXISTS seguridad;
CREATE SCHEMA IF NOT EXISTS socios;
CREATE SCHEMA IF NOT EXISTS salud;
CREATE SCHEMA IF NOT EXISTS ventas;
CREATE SCHEMA IF NOT EXISTS auditoria;
CREATE SCHEMA IF NOT EXISTS inventario;

CREATE TABLE IF NOT EXISTS core.estados (
    id BIGSERIAL PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS core.sedes (
    id BIGSERIAL PRIMARY KEY,
    gimnasio_id BIGINT,
    nombre VARCHAR(150) NOT NULL,
    direccion TEXT,
    telefono VARCHAR(30),
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS core.personas (
    id BIGSERIAL PRIMARY KEY,
    gimnasio_id BIGINT,
    tipo_identificacion VARCHAR(20) NOT NULL DEFAULT 'CEDULA',
    numero_identificacion VARCHAR(30) NOT NULL,
    nombres VARCHAR(120) NOT NULL,
    apellidos VARCHAR(120),
    fecha_nacimiento DATE,
    sexo VARCHAR(20),
    nacionalidad VARCHAR(120),
    provincia VARCHAR(120),
    ciudad VARCHAR(120),
    parroquia VARCHAR(120),
    direccion TEXT,
    telefono VARCHAR(30),
    email VARCHAR(150),
    foto_url TEXT,
    estado_id BIGINT REFERENCES core.estados(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT uq_core_personas_identificacion UNIQUE (tipo_identificacion, numero_identificacion)
);

CREATE TABLE IF NOT EXISTS core.persona_tipos (
    id BIGSERIAL PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS core.persona_tipo_detalle (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id) ON DELETE CASCADE,
    tipo_id BIGINT NOT NULL REFERENCES core.persona_tipos(id) ON DELETE CASCADE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    fecha_inicio DATE DEFAULT CURRENT_DATE,
    fecha_fin DATE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT uq_core_persona_tipo UNIQUE (persona_id, tipo_id)
);

CREATE TABLE IF NOT EXISTS seguridad.usuarios (
    id BIGSERIAL PRIMARY KEY,
    gimnasio_id BIGINT,
    persona_id BIGINT REFERENCES core.personas(id),
    cedula VARCHAR(30),
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'ACTIVO',
    fecha_baja TIMESTAMPTZ,
    foto_perfil_url TEXT,
    created_id_user BIGINT,
    updated_id_user BIGINT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT uq_seguridad_usuarios_cedula UNIQUE (cedula)
);

CREATE TABLE IF NOT EXISTS seguridad.roles (
    id BIGSERIAL PRIMARY KEY,
    gimnasio_id BIGINT,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS seguridad.usuario_roles (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES seguridad.usuarios(id) ON DELETE CASCADE,
    rol_id BIGINT NOT NULL REFERENCES seguridad.roles(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT uq_seguridad_usuario_rol UNIQUE (usuario_id, rol_id)
);

CREATE TABLE IF NOT EXISTS socios.socios (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL UNIQUE REFERENCES core.personas(id) ON DELETE CASCADE,
    codigo_socio VARCHAR(30) NOT NULL UNIQUE,
    sede_id BIGINT REFERENCES core.sedes(id),
    fecha_alta DATE NOT NULL DEFAULT CURRENT_DATE,
    estado_id BIGINT REFERENCES core.estados(id),
    observacion TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS socios.membresias (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    descripcion TEXT,
    duracion_dias INTEGER NOT NULL,
    precio NUMERIC(12, 2) NOT NULL,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS socios.socio_membresias (
    id BIGSERIAL PRIMARY KEY,
    socio_id BIGINT NOT NULL REFERENCES socios.socios(id) ON DELETE CASCADE,
    membresia_id BIGINT NOT NULL REFERENCES socios.membresias(id),
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    estado_id BIGINT REFERENCES core.estados(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS salud.fichas_tecnicas (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id) ON DELETE CASCADE,
    fecha_ficha DATE NOT NULL DEFAULT CURRENT_DATE,
    actividad_fisica TEXT,
    objetivo TEXT,
    observaciones TEXT,
    registrado_por BIGINT REFERENCES seguridad.usuarios(id),
    sede_id BIGINT REFERENCES core.sedes(id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS salud.ficha_mediciones (
    id BIGSERIAL PRIMARY KEY,
    ficha_tecnica_id BIGINT NOT NULL REFERENCES salud.fichas_tecnicas(id) ON DELETE CASCADE,
    peso_kg NUMERIC(10, 2),
    talla_cm NUMERIC(10, 2),
    imc NUMERIC(10, 2),
    cintura_cm NUMERIC(10, 2),
    grasa_corporal_pct NUMERIC(10, 2),
    masa_magra_kg NUMERIC(10, 2),
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS salud.catalogo_patologias (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL UNIQUE,
    descripcion TEXT,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS salud.ficha_patologias (
    id BIGSERIAL PRIMARY KEY,
    ficha_tecnica_id BIGINT NOT NULL REFERENCES salud.fichas_tecnicas(id) ON DELETE CASCADE,
    patologia_id BIGINT NOT NULL REFERENCES salud.catalogo_patologias(id),
    detalle TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ventas.venta_pagos (
    id BIGSERIAL PRIMARY KEY,
    venta_id BIGINT NOT NULL REFERENCES ventas.ventas(id) ON DELETE CASCADE,
    forma_pago VARCHAR(30) NOT NULL,
    monto NUMERIC(12, 2) NOT NULL,
    referencia_pago VARCHAR(120),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE ventas.ventas
    ADD COLUMN IF NOT EXISTS persona_id BIGINT,
    ADD COLUMN IF NOT EXISTS vendedor_usuario_id BIGINT,
    ADD COLUMN IF NOT EXISTS referencia VARCHAR(100),
    ADD COLUMN IF NOT EXISTS forma_pago VARCHAR(30),
    ADD COLUMN IF NOT EXISTS observacion TEXT,
    ADD COLUMN IF NOT EXISTS subtotal NUMERIC(12, 2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS iva NUMERIC(12, 2) DEFAULT 0;

ALTER TABLE ventas.venta_detalles
    ALTER COLUMN cantidad TYPE NUMERIC(12, 2) USING cantidad::NUMERIC;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ventas_persona_id'
    ) THEN
        ALTER TABLE ventas.ventas
            ADD CONSTRAINT fk_ventas_persona_id
            FOREIGN KEY (persona_id) REFERENCES core.personas(id);
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_ventas_vendedor_usuario_id'
    ) THEN
        ALTER TABLE ventas.ventas
            ADD CONSTRAINT fk_ventas_vendedor_usuario_id
            FOREIGN KEY (vendedor_usuario_id) REFERENCES seguridad.usuarios(id);
    END IF;
END $$;

INSERT INTO core.estados (codigo, nombre, descripcion, activo, created_at, updated_at)
VALUES
    ('ACTIVO', 'Activo', 'Registro activo', TRUE, NOW(), NOW()),
    ('INACTIVO', 'Inactivo', 'Registro inactivo', TRUE, NOW(), NOW()),
    ('SUSPENDIDO', 'Suspendido', 'Registro suspendido', TRUE, NOW(), NOW())
ON CONFLICT (codigo) DO UPDATE
SET
    nombre = EXCLUDED.nombre,
    descripcion = EXCLUDED.descripcion,
    activo = EXCLUDED.activo,
    updated_at = NOW();

INSERT INTO core.persona_tipos (codigo, nombre, descripcion, activo)
VALUES
    ('CLIENTE', 'Cliente', 'Persona que compra en punto de venta', TRUE),
    ('SOCIO', 'Socio', 'Persona con membresia en Revive', TRUE),
    ('FUNCIONARIO', 'Funcionario', 'Personal operativo o administrativo', TRUE),
    ('ENTRENADOR', 'Entrenador', 'Coach o trainer', TRUE),
    ('ADMIN', 'Administrador', 'Usuario administrador del sistema', TRUE)
ON CONFLICT (codigo) DO UPDATE
SET
    nombre = EXCLUDED.nombre,
    descripcion = EXCLUDED.descripcion,
    activo = EXCLUDED.activo;

DO $$
BEGIN
    IF to_regclass('train_gimnasio.sedes') IS NOT NULL THEN
        INSERT INTO core.sedes (id, gimnasio_id, nombre, direccion, telefono, activa, created_at, updated_at)
        SELECT
            s.id,
            s.gimnasio_id,
            s.nombre,
            s.direccion,
            s.telefono,
            COALESCE(s.activa, TRUE),
            COALESCE(s.created_at, NOW()),
            COALESCE(s.updated_at, NOW())
        FROM train_gimnasio.sedes s
        ON CONFLICT (id) DO UPDATE
        SET
            gimnasio_id = EXCLUDED.gimnasio_id,
            nombre = EXCLUDED.nombre,
            direccion = EXCLUDED.direccion,
            telefono = EXCLUDED.telefono,
            activa = EXCLUDED.activa,
            updated_at = NOW();
    END IF;
END $$;

DO $$
BEGIN
    IF to_regclass('train_gimnasio.personas') IS NOT NULL THEN
        INSERT INTO core.personas (
            id,
            gimnasio_id,
            tipo_identificacion,
            numero_identificacion,
            nombres,
            apellidos,
            fecha_nacimiento,
            sexo,
            nacionalidad,
            provincia,
            ciudad,
            parroquia,
            direccion,
            telefono,
            email,
            foto_url,
            estado_id,
            created_at,
            updated_at
        )
        SELECT
            p.id,
            p.gimnasio_id,
            'CEDULA',
            COALESCE(NULLIF(TRIM(p.cedula), ''), 'SIN-DOC-' || p.id),
            p.nombres,
            p.apellidos,
            p.fecha_nacimiento,
            p.sexo,
            p.nacionalidad,
            p.provincia,
            p.ciudad,
            p.parroquia,
            p.direccion,
            p.celular,
            p.email_contacto,
            p.imagen_url,
            (SELECT id FROM core.estados WHERE codigo = 'ACTIVO' LIMIT 1),
            COALESCE(p.created_at, NOW()),
            COALESCE(p.updated_at, NOW())
        FROM train_gimnasio.personas p
        ON CONFLICT (id) DO UPDATE
        SET
            gimnasio_id = EXCLUDED.gimnasio_id,
            numero_identificacion = EXCLUDED.numero_identificacion,
            nombres = EXCLUDED.nombres,
            apellidos = EXCLUDED.apellidos,
            fecha_nacimiento = EXCLUDED.fecha_nacimiento,
            sexo = EXCLUDED.sexo,
            nacionalidad = EXCLUDED.nacionalidad,
            provincia = EXCLUDED.provincia,
            ciudad = EXCLUDED.ciudad,
            parroquia = EXCLUDED.parroquia,
            direccion = EXCLUDED.direccion,
            telefono = EXCLUDED.telefono,
            email = EXCLUDED.email,
            foto_url = EXCLUDED.foto_url,
            estado_id = EXCLUDED.estado_id,
            updated_at = NOW();
    END IF;
END $$;

DO $$
BEGIN
    IF to_regclass('train_gimnasio.auth_usuarios') IS NOT NULL THEN
        INSERT INTO seguridad.usuarios (
            id,
            gimnasio_id,
            persona_id,
            cedula,
            email,
            password_hash,
            estado,
            fecha_baja,
            foto_perfil_url,
            created_id_user,
            updated_id_user,
            created_at,
            updated_at
        )
        SELECT
            u.id,
            u.gimnasio_id,
            u.persona_id,
            COALESCE(NULLIF(TRIM(p.numero_identificacion), ''), NULL),
            u.email,
            u.password_hash,
            COALESCE(u.estado, 'ACTIVO'),
            u.fecha_baja,
            u.foto_perfil_url,
            u.created_id_user,
            u.updated_id_user,
            COALESCE(u.created_at, NOW()),
            COALESCE(u.updated_at, NOW())
        FROM train_gimnasio.auth_usuarios u
        LEFT JOIN core.personas p ON p.id = u.persona_id
        ON CONFLICT (id) DO UPDATE
        SET
            gimnasio_id = EXCLUDED.gimnasio_id,
            persona_id = EXCLUDED.persona_id,
            cedula = EXCLUDED.cedula,
            email = EXCLUDED.email,
            password_hash = EXCLUDED.password_hash,
            estado = EXCLUDED.estado,
            fecha_baja = EXCLUDED.fecha_baja,
            foto_perfil_url = EXCLUDED.foto_perfil_url,
            created_id_user = EXCLUDED.created_id_user,
            updated_id_user = EXCLUDED.updated_id_user,
            updated_at = NOW();
    END IF;
END $$;

DO $$
BEGIN
    IF to_regclass('train_gimnasio.auth_roles') IS NOT NULL THEN
        INSERT INTO seguridad.roles (id, gimnasio_id, codigo, nombre, descripcion, activo, created_at, updated_at)
        SELECT
            r.id,
            r.gimnasio_id,
            r.codigo,
            r.nombre,
            r.descripcion,
            COALESCE(r.activo, TRUE),
            COALESCE(r.created_at, NOW()),
            COALESCE(r.updated_at, NOW())
        FROM train_gimnasio.auth_roles r
        ON CONFLICT (id) DO UPDATE
        SET
            gimnasio_id = EXCLUDED.gimnasio_id,
            codigo = EXCLUDED.codigo,
            nombre = EXCLUDED.nombre,
            descripcion = EXCLUDED.descripcion,
            activo = EXCLUDED.activo,
            updated_at = NOW();
    END IF;
END $$;

DO $$
BEGIN
    IF to_regclass('train_gimnasio.auth_usuario_roles') IS NOT NULL THEN
        INSERT INTO seguridad.usuario_roles (id, usuario_id, rol_id, created_at)
        SELECT
            ur.id,
            ur.usuario_id,
            ur.rol_id,
            COALESCE(ur.created_at, NOW())
        FROM train_gimnasio.auth_usuario_roles ur
        ON CONFLICT (id) DO UPDATE
        SET
            usuario_id = EXCLUDED.usuario_id,
            rol_id = EXCLUDED.rol_id;
    END IF;
END $$;

INSERT INTO core.persona_tipo_detalle (persona_id, tipo_id, activo, fecha_inicio, created_at, updated_at)
SELECT
    p.id,
    t.id,
    TRUE,
    CURRENT_DATE,
    NOW(),
    NOW()
FROM core.personas p
CROSS JOIN core.persona_tipos t
WHERE t.codigo = 'CLIENTE'
ON CONFLICT (persona_id, tipo_id) DO NOTHING;

INSERT INTO core.persona_tipo_detalle (persona_id, tipo_id, activo, fecha_inicio, created_at, updated_at)
SELECT DISTINCT
    su.persona_id,
    t.id,
    TRUE,
    CURRENT_DATE,
    NOW(),
    NOW()
FROM seguridad.usuarios su
JOIN seguridad.usuario_roles sur ON sur.usuario_id = su.id
JOIN seguridad.roles sr ON sr.id = sur.rol_id
JOIN core.persona_tipos t ON t.codigo = CASE
    WHEN sr.codigo = 'ADMIN' THEN 'ADMIN'
    WHEN sr.codigo = 'ENTRENADOR' THEN 'ENTRENADOR'
    ELSE 'FUNCIONARIO'
END
WHERE su.persona_id IS NOT NULL
  AND sr.codigo IN ('ADMIN', 'CAJERO', 'ENTRENADOR')
ON CONFLICT (persona_id, tipo_id) DO NOTHING;

UPDATE ventas.ventas
SET
    persona_id = COALESCE(persona_id, cliente_id),
    vendedor_usuario_id = COALESCE(vendedor_usuario_id, vendedor_id),
    referencia = COALESCE(referencia, 'LEGACY-' || id),
    forma_pago = COALESCE(forma_pago, 'EFECTIVO'),
    subtotal = COALESCE(subtotal, total, 0),
    iva = COALESCE(iva, 0)
WHERE TRUE;

SELECT setval(pg_get_serial_sequence('core.estados', 'id'), COALESCE((SELECT MAX(id) FROM core.estados), 1), TRUE);
SELECT setval(pg_get_serial_sequence('core.sedes', 'id'), COALESCE((SELECT MAX(id) FROM core.sedes), 1), TRUE);
SELECT setval(pg_get_serial_sequence('core.personas', 'id'), COALESCE((SELECT MAX(id) FROM core.personas), 1), TRUE);
SELECT setval(pg_get_serial_sequence('seguridad.usuarios', 'id'), COALESCE((SELECT MAX(id) FROM seguridad.usuarios), 1), TRUE);
SELECT setval(pg_get_serial_sequence('seguridad.roles', 'id'), COALESCE((SELECT MAX(id) FROM seguridad.roles), 1), TRUE);
SELECT setval(pg_get_serial_sequence('seguridad.usuario_roles', 'id'), COALESCE((SELECT MAX(id) FROM seguridad.usuario_roles), 1), TRUE);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS ventas.venta_pagos;
DROP TABLE IF EXISTS salud.ficha_patologias;
DROP TABLE IF EXISTS salud.ficha_mediciones;
DROP TABLE IF EXISTS salud.fichas_tecnicas;
DROP TABLE IF EXISTS salud.catalogo_patologias;
DROP TABLE IF EXISTS socios.socio_membresias;
DROP TABLE IF EXISTS socios.membresias;
DROP TABLE IF EXISTS socios.socios;
DROP TABLE IF EXISTS seguridad.usuario_roles;
DROP TABLE IF EXISTS seguridad.roles;
DROP TABLE IF EXISTS seguridad.usuarios;
DROP TABLE IF EXISTS core.persona_tipo_detalle;
DROP TABLE IF EXISTS core.persona_tipos;
DROP TABLE IF EXISTS core.personas;
DROP TABLE IF EXISTS core.sedes;
DROP TABLE IF EXISTS core.estados;
SQL);
    }
};
