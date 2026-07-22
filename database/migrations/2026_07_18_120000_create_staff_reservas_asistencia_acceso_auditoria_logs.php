<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS staff;
CREATE SCHEMA IF NOT EXISTS reservas;
CREATE SCHEMA IF NOT EXISTS asistencia;
CREATE SCHEMA IF NOT EXISTS acceso;
CREATE SCHEMA IF NOT EXISTS logs;

CREATE TABLE IF NOT EXISTS auditoria.eventos (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(80),
    usuario_id BIGINT REFERENCES seguridad.usuarios(id),
    persona_id_afectada BIGINT REFERENCES core.personas(id),
    sede_id BIGINT REFERENCES core.sedes(id),
    modulo VARCHAR(80) NOT NULL,
    entidad VARCHAR(120),
    entidad_id VARCHAR(80),
    accion VARCHAR(120) NOT NULL,
    descripcion TEXT,
    origen VARCHAR(30) NOT NULL DEFAULT 'WEB',
    ip VARCHAR(80),
    user_agent TEXT,
    metadata JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS auditoria.cambios (
    id BIGSERIAL PRIMARY KEY,
    evento_id BIGINT NOT NULL REFERENCES auditoria.eventos(id) ON DELETE CASCADE,
    campo VARCHAR(160) NOT NULL,
    valor_anterior TEXT,
    valor_nuevo TEXT,
    tipo_dato VARCHAR(40),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS auditoria.snapshots (
    id BIGSERIAL PRIMARY KEY,
    evento_id BIGINT NOT NULL REFERENCES auditoria.eventos(id) ON DELETE CASCADE,
    antes JSONB,
    despues JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS logs.eventos (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(80),
    nivel VARCHAR(20) NOT NULL DEFAULT 'INFO',
    canal VARCHAR(40) NOT NULL DEFAULT 'BACKEND',
    modulo VARCHAR(80),
    accion VARCHAR(120),
    mensaje TEXT NOT NULL,
    usuario_id BIGINT REFERENCES seguridad.usuarios(id),
    persona_id BIGINT REFERENCES core.personas(id),
    sede_id BIGINT REFERENCES core.sedes(id),
    ip VARCHAR(80),
    user_agent TEXT,
    contexto JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS logs.excepciones (
    id BIGSERIAL PRIMARY KEY,
    log_evento_id BIGINT REFERENCES logs.eventos(id) ON DELETE SET NULL,
    exception_class TEXT,
    exception_message TEXT,
    archivo TEXT,
    linea INTEGER,
    stack_trace TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS logs.integraciones (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(80),
    proveedor VARCHAR(120) NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    direccion VARCHAR(20) NOT NULL,
    endpoint TEXT,
    metodo VARCHAR(20),
    status_code INTEGER,
    request_payload JSONB,
    response_payload JSONB,
    error TEXT,
    duracion_ms INTEGER,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS logs.jobs (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(80),
    job_nombre VARCHAR(180) NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'INICIADO',
    intentos INTEGER NOT NULL DEFAULT 0,
    duracion_ms INTEGER,
    payload JSONB,
    error TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS staff.perfiles (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id) ON DELETE CASCADE,
    usuario_id BIGINT REFERENCES seguridad.usuarios(id) ON DELETE SET NULL,
    tipo_staff VARCHAR(30) NOT NULL,
    especialidad VARCHAR(160),
    estado VARCHAR(30) NOT NULL DEFAULT 'ACTIVO',
    fecha_inicio DATE NOT NULL DEFAULT CURRENT_DATE,
    fecha_fin DATE,
    observaciones TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT uq_staff_perfil_persona_tipo UNIQUE (persona_id, tipo_staff)
);

CREATE TABLE IF NOT EXISTS staff.coach_sedes (
    id BIGSERIAL PRIMARY KEY,
    coach_id BIGINT NOT NULL REFERENCES staff.perfiles(id) ON DELETE CASCADE,
    sede_id BIGINT NOT NULL REFERENCES core.sedes(id) ON DELETE CASCADE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT uq_staff_coach_sede UNIQUE (coach_id, sede_id)
);

CREATE TABLE IF NOT EXISTS staff.turnos_recurrentes (
    id BIGSERIAL PRIMARY KEY,
    coach_id BIGINT NOT NULL REFERENCES staff.perfiles(id) ON DELETE CASCADE,
    sede_id BIGINT NOT NULL REFERENCES core.sedes(id),
    dia_semana INTEGER NOT NULL CHECK (dia_semana BETWEEN 1 AND 7),
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    capacidad_atencion INTEGER NOT NULL DEFAULT 1,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT chk_staff_turno_recurrente_horas CHECK (hora_fin > hora_inicio)
);

CREATE TABLE IF NOT EXISTS staff.turnos_excepciones (
    id BIGSERIAL PRIMARY KEY,
    coach_id BIGINT NOT NULL REFERENCES staff.perfiles(id) ON DELETE CASCADE,
    sede_id BIGINT REFERENCES core.sedes(id),
    fecha DATE NOT NULL,
    hora_inicio TIME,
    hora_fin TIME,
    tipo VARCHAR(30) NOT NULL,
    coach_sustituto_id BIGINT REFERENCES staff.perfiles(id),
    motivo TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT chk_staff_turno_excepcion_horas CHECK (hora_inicio IS NULL OR hora_fin IS NULL OR hora_fin > hora_inicio)
);

CREATE TABLE IF NOT EXISTS reservas.reservas (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id),
    socio_membresia_id BIGINT REFERENCES socios.socio_membresias(id),
    sede_id BIGINT NOT NULL REFERENCES core.sedes(id),
    coach_usuario_id BIGINT REFERENCES seguridad.usuarios(id),
    servicio_id BIGINT,
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'RESERVADA',
    origen VARCHAR(30) NOT NULL DEFAULT 'APP',
    created_by_usuario_id BIGINT REFERENCES seguridad.usuarios(id),
    motivo_cancelacion TEXT,
    metadata JSONB,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT chk_reservas_horas CHECK (hora_fin > hora_inicio)
);

CREATE TABLE IF NOT EXISTS asistencia.registros (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id),
    sede_id BIGINT NOT NULL REFERENCES core.sedes(id),
    reserva_id BIGINT REFERENCES reservas.reservas(id) ON DELETE SET NULL,
    socio_membresia_id BIGINT REFERENCES socios.socio_membresias(id),
    fecha_hora TIMESTAMP NOT NULL DEFAULT NOW(),
    tipo VARCHAR(20) NOT NULL DEFAULT 'ENTRADA',
    metodo VARCHAR(30) NOT NULL DEFAULT 'MANUAL',
    origen VARCHAR(30) NOT NULL DEFAULT 'WEB',
    estado VARCHAR(30) NOT NULL DEFAULT 'PERMITIDO',
    registrado_por_usuario_id BIGINT REFERENCES seguridad.usuarios(id),
    motivo TEXT,
    request_id VARCHAR(80),
    metadata JSONB,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS acceso.credenciales (
    id BIGSERIAL PRIMARY KEY,
    persona_id BIGINT NOT NULL REFERENCES core.personas(id) ON DELETE CASCADE,
    tipo VARCHAR(30) NOT NULL DEFAULT 'QR',
    codigo_hash TEXT NOT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'ACTIVA',
    vigencia_inicio TIMESTAMP DEFAULT NOW(),
    vigencia_fin TIMESTAMP,
    ultimo_uso_at TIMESTAMP,
    metadata JSONB,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT uq_acceso_credencial_codigo UNIQUE (codigo_hash)
);

CREATE TABLE IF NOT EXISTS acceso.dispositivos (
    id BIGSERIAL PRIMARY KEY,
    sede_id BIGINT NOT NULL REFERENCES core.sedes(id),
    nombre VARCHAR(160) NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    proveedor VARCHAR(120),
    identificador_externo VARCHAR(180),
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    metadata JSONB,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS acceso.eventos (
    id BIGSERIAL PRIMARY KEY,
    dispositivo_id BIGINT REFERENCES acceso.dispositivos(id) ON DELETE SET NULL,
    persona_id BIGINT REFERENCES core.personas(id),
    fecha_hora TIMESTAMP NOT NULL DEFAULT NOW(),
    tipo_evento VARCHAR(40) NOT NULL,
    estado_procesamiento VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
    asistencia_registro_id BIGINT REFERENCES asistencia.registros(id),
    request_id VARCHAR(80),
    payload_raw JSONB,
    error TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_auditoria_eventos_fecha ON auditoria.eventos (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_auditoria_eventos_request ON auditoria.eventos (request_id);
CREATE INDEX IF NOT EXISTS idx_auditoria_eventos_modulo ON auditoria.eventos (modulo, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_auditoria_eventos_usuario ON auditoria.eventos (usuario_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_logs_eventos_fecha ON logs.eventos (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_logs_eventos_request ON logs.eventos (request_id);
CREATE INDEX IF NOT EXISTS idx_logs_eventos_nivel ON logs.eventos (nivel, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_logs_integraciones_request ON logs.integraciones (request_id);
CREATE INDEX IF NOT EXISTS idx_staff_turnos_coach_dia ON staff.turnos_recurrentes (coach_id, dia_semana, activo);
CREATE INDEX IF NOT EXISTS idx_reservas_persona_fecha ON reservas.reservas (persona_id, fecha DESC);
CREATE INDEX IF NOT EXISTS idx_reservas_sede_fecha ON reservas.reservas (sede_id, fecha, hora_inicio);
CREATE INDEX IF NOT EXISTS idx_asistencia_persona_fecha ON asistencia.registros (persona_id, fecha_hora DESC);
CREATE INDEX IF NOT EXISTS idx_asistencia_sede_fecha ON asistencia.registros (sede_id, fecha_hora DESC);
CREATE INDEX IF NOT EXISTS idx_acceso_eventos_fecha ON acceso.eventos (fecha_hora DESC);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS acceso.eventos;
DROP TABLE IF EXISTS acceso.dispositivos;
DROP TABLE IF EXISTS acceso.credenciales;
DROP TABLE IF EXISTS asistencia.registros;
DROP TABLE IF EXISTS reservas.reservas;
DROP TABLE IF EXISTS staff.turnos_excepciones;
DROP TABLE IF EXISTS staff.turnos_recurrentes;
DROP TABLE IF EXISTS staff.coach_sedes;
DROP TABLE IF EXISTS staff.perfiles;
DROP TABLE IF EXISTS logs.jobs;
DROP TABLE IF EXISTS logs.integraciones;
DROP TABLE IF EXISTS logs.excepciones;
DROP TABLE IF EXISTS logs.eventos;
DROP TABLE IF EXISTS auditoria.snapshots;
DROP TABLE IF EXISTS auditoria.cambios;
DROP TABLE IF EXISTS auditoria.eventos;
DROP SCHEMA IF EXISTS acceso;
DROP SCHEMA IF EXISTS asistencia;
DROP SCHEMA IF EXISTS reservas;
DROP SCHEMA IF EXISTS staff;
DROP SCHEMA IF EXISTS logs;
SQL);
    }
};
