<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS train_gimnasio;

SELECT setval(
    pg_get_serial_sequence('train_gimnasio.categoria_servicios', 'id'),
    COALESCE((SELECT MAX(id) FROM train_gimnasio.categoria_servicios), 1),
    TRUE
);

SELECT setval(
    pg_get_serial_sequence('train_gimnasio.tipos_servicios', 'id'),
    COALESCE((SELECT MAX(id) FROM train_gimnasio.tipos_servicios), 1),
    TRUE
);

SELECT setval(
    pg_get_serial_sequence('train_gimnasio.horarios_gym', 'id'),
    COALESCE((SELECT MAX(id) FROM train_gimnasio.horarios_gym), 1),
    TRUE
);

CREATE TEMP TABLE tmp_revive_categorias (
    nombre TEXT PRIMARY KEY,
    descripcion TEXT NOT NULL
) ON COMMIT DROP;

INSERT INTO tmp_revive_categorias (nombre, descripcion)
VALUES
    ('Entrenamiento Revive', 'Servicios principales del centro de entrenamiento físico Revive.'),
    ('Entrenamiento Personalizado', 'Atención individual o semi personalizada con coach asignado.'),
    ('Evaluación y Seguimiento', 'Evaluaciones físicas, control técnico y seguimiento del deportista.'),
    ('Clases Grupales', 'Sesiones grupales dirigidas por horario y cupo.'),
    ('Movilidad y Recuperación', 'Movilidad, prevención, recuperación y trabajo correctivo.'),
    ('Revive Xpadel', 'Servicios físicos y agenda operativa asociados a la sede Xpadel.');

UPDATE train_gimnasio.categoria_servicios c
SET
    descripcion = tc.descripcion,
    estado_id = 8,
    updated_at = NOW()
FROM tmp_revive_categorias tc
WHERE LOWER(c.nombre) = LOWER(tc.nombre);

INSERT INTO train_gimnasio.categoria_servicios (nombre, descripcion, estado_id, user_id, created_at, updated_at)
SELECT tc.nombre, tc.descripcion, 8, COALESCE((SELECT id FROM seguridad.usuarios ORDER BY id LIMIT 1), 1), NOW(), NOW()
FROM tmp_revive_categorias tc
WHERE NOT EXISTS (
    SELECT 1
    FROM train_gimnasio.categoria_servicios c
    WHERE LOWER(c.nombre) = LOWER(tc.nombre)
);

UPDATE train_gimnasio.categoria_servicios c
SET estado_id = 9, updated_at = NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM tmp_revive_categorias tc
    WHERE LOWER(tc.nombre) = LOWER(c.nombre)
);

CREATE TEMP TABLE tmp_revive_servicios (
    nombre TEXT PRIMARY KEY,
    categoria_nombre TEXT NOT NULL,
    descripcion TEXT NOT NULL,
    breve_desc VARCHAR(120) NOT NULL
) ON COMMIT DROP;

INSERT INTO tmp_revive_servicios (nombre, categoria_nombre, descripcion, breve_desc)
VALUES
    ('Acceso general con pizarra', 'Entrenamiento Revive', 'Ingreso general al área de entrenamiento con guía de pizarra. Ideal para pase diario o membresías sin seguimiento individual.', 'Acceso general guiado por pizarra'),
    ('Fuerza e hipertrofia', 'Entrenamiento Revive', 'Sesiones de fuerza, técnica básica, musculación e hipertrofia dentro del piso de entrenamiento.', 'Fuerza, técnica y musculación'),
    ('Entrenamiento funcional', 'Entrenamiento Revive', 'Trabajo funcional por estaciones, coordinación, resistencia y acondicionamiento físico.', 'Funcional por estaciones'),
    ('HIIT metabólico', 'Clases Grupales', 'Clase grupal de alta intensidad con control de cupos y horarios definidos.', 'Clase HIIT grupal'),
    ('Clase grupal funcional', 'Clases Grupales', 'Sesión grupal dirigida para acondicionamiento general y trabajo técnico.', 'Funcional grupal'),
    ('Personalizado 1:1', 'Entrenamiento Personalizado', 'Entrenamiento individual con coach asignado, seguimiento y control de asistencia.', 'Coach individual'),
    ('Semi personalizado', 'Entrenamiento Personalizado', 'Atención por cupo reducido para deportistas con seguimiento técnico.', 'Cupo reducido con coach'),
    ('Evaluación física inicial', 'Evaluación y Seguimiento', 'Valoración inicial del deportista: medidas, objetivo, condición física y recomendaciones.', 'Valoración inicial'),
    ('Control y seguimiento', 'Evaluación y Seguimiento', 'Revisión periódica de progreso, adherencia, asistencia y ajustes de entrenamiento.', 'Seguimiento mensual'),
    ('Movilidad y recuperación', 'Movilidad y Recuperación', 'Sesión orientada a movilidad, descarga, prevención y corrección técnica.', 'Movilidad y descarga'),
    ('Preparación física Xpadel', 'Revive Xpadel', 'Preparación física orientada a jugadores y deportistas de la sede Xpadel.', 'Físico para Xpadel'),
    ('Acceso general Xpadel', 'Revive Xpadel', 'Acceso general a entrenamiento físico en sede Xpadel según membresía o pase diario.', 'Acceso sede Xpadel');

UPDATE train_gimnasio.tipos_servicios ts
SET
    categoria_id = c.id,
    descripcion = srv.descripcion,
    breve_desc = srv.breve_desc,
    estado_id = 1,
    updated_at = NOW()
FROM tmp_revive_servicios srv
JOIN train_gimnasio.categoria_servicios c ON LOWER(c.nombre) = LOWER(srv.categoria_nombre)
WHERE LOWER(ts.nombre) = LOWER(srv.nombre);

INSERT INTO train_gimnasio.tipos_servicios (nombre, descripcion, breve_desc, categoria_id, estado_id, user_id, created_at, updated_at)
SELECT srv.nombre, srv.descripcion, srv.breve_desc, c.id, 1, COALESCE((SELECT id FROM seguridad.usuarios ORDER BY id LIMIT 1), 1), NOW(), NOW()
FROM tmp_revive_servicios srv
JOIN train_gimnasio.categoria_servicios c ON LOWER(c.nombre) = LOWER(srv.categoria_nombre)
WHERE NOT EXISTS (
    SELECT 1
    FROM train_gimnasio.tipos_servicios ts
    WHERE LOWER(ts.nombre) = LOWER(srv.nombre)
);

UPDATE train_gimnasio.tipos_servicios ts
SET estado_id = 0, updated_at = NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM tmp_revive_servicios srv
    WHERE LOWER(srv.nombre) = LOWER(ts.nombre)
);

UPDATE train_gimnasio.horarios_gym
SET activo = FALSE, updated_at = NOW();

CREATE TEMP TABLE tmp_revive_horarios (
    sede_nombre TEXT NOT NULL,
    servicio_nombre TEXT NOT NULL,
    hora_apertura TIME NOT NULL,
    hora_cierre TIME NOT NULL,
    capacidad_maxima INTEGER NOT NULL,
    tiempo_turno_min INTEGER NOT NULL,
    tipo_usuario BIGINT NOT NULL,
    dias SMALLINT[] NOT NULL
) ON COMMIT DROP;

INSERT INTO tmp_revive_horarios (
    sede_nombre,
    servicio_nombre,
    hora_apertura,
    hora_cierre,
    capacidad_maxima,
    tiempo_turno_min,
    tipo_usuario,
    dias
)
VALUES
    ('Revive Home', 'Acceso general con pizarra', '05:00', '22:00', 45, 60, 1, ARRAY[1,2,3,4,5]::SMALLINT[]),
    ('Revive Home', 'Acceso general con pizarra', '07:00', '15:00', 35, 60, 1, ARRAY[6]::SMALLINT[]),
    ('Revive Home', 'Fuerza e hipertrofia', '06:00', '21:00', 25, 60, 1, ARRAY[1,2,3,4,5,6]::SMALLINT[]),
    ('Revive Home', 'Entrenamiento funcional', '06:00', '21:00', 22, 60, 1, ARRAY[1,2,3,4,5,6]::SMALLINT[]),
    ('Revive Home', 'HIIT metabólico', '06:00', '20:00', 18, 45, 1, ARRAY[1,2,3,4,5]::SMALLINT[]),
    ('Revive Home', 'Clase grupal funcional', '07:00', '20:00', 20, 60, 1, ARRAY[1,2,3,4,5]::SMALLINT[]),
    ('Revive Home', 'Personalizado 1:1', '06:00', '20:00', 1, 60, 1, ARRAY[1,2,3,4,5,6]::SMALLINT[]),
    ('Revive Home', 'Semi personalizado', '06:00', '20:00', 4, 60, 1, ARRAY[1,2,3,4,5,6]::SMALLINT[]),
    ('Revive Home', 'Evaluación física inicial', '07:00', '19:00', 1, 60, 1, ARRAY[1,2,3,4,5]::SMALLINT[]),
    ('Revive Home', 'Control y seguimiento', '07:00', '19:00', 1, 45, 1, ARRAY[1,2,3,4,5]::SMALLINT[]),
    ('Revive Home', 'Movilidad y recuperación', '07:00', '20:00', 12, 45, 1, ARRAY[1,2,3,4,5]::SMALLINT[]),
    ('Revive Xpadel', 'Acceso general Xpadel', '06:00', '22:00', 30, 60, 1, ARRAY[1,2,3,4,5,6]::SMALLINT[]),
    ('Revive Xpadel', 'Preparación física Xpadel', '06:00', '21:00', 16, 60, 1, ARRAY[1,2,3,4,5,6]::SMALLINT[]),
    ('Revive Xpadel', 'Personalizado 1:1', '06:00', '20:00', 1, 60, 1, ARRAY[1,2,3,4,5,6]::SMALLINT[]),
    ('Revive Xpadel', 'Evaluación física inicial', '07:00', '19:00', 1, 60, 1, ARRAY[1,2,3,4,5]::SMALLINT[]);

DO $$
DECLARE
    row_horario RECORD;
    v_horario_id BIGINT;
BEGIN
    FOR row_horario IN
        SELECT
            s.id AS sede_id,
            ts.id AS tipo_servicio_id,
            th.hora_apertura,
            th.hora_cierre,
            th.capacidad_maxima,
            th.tiempo_turno_min,
            th.tipo_usuario,
            th.dias
        FROM tmp_revive_horarios th
        JOIN core.sedes s ON LOWER(s.nombre) = LOWER(th.sede_nombre)
        JOIN train_gimnasio.tipos_servicios ts ON LOWER(ts.nombre) = LOWER(th.servicio_nombre)
    LOOP
        SELECT h.id
        INTO v_horario_id
        FROM train_gimnasio.horarios_gym h
        WHERE h.sede_id = row_horario.sede_id
          AND h.tipo_servicio_id = row_horario.tipo_servicio_id
          AND h.hora_apertura = row_horario.hora_apertura
          AND h.hora_cierre = row_horario.hora_cierre
        LIMIT 1;

        IF v_horario_id IS NULL THEN
            INSERT INTO train_gimnasio.horarios_gym (
                sede_id,
                tipo_servicio_id,
                hora_apertura,
                hora_cierre,
                capacidad_maxima,
                tiempo_turno_min,
                tipo_usuario,
                activo,
                created_at,
                updated_at
            )
            VALUES (
                row_horario.sede_id,
                row_horario.tipo_servicio_id,
                row_horario.hora_apertura,
                row_horario.hora_cierre,
                row_horario.capacidad_maxima,
                row_horario.tiempo_turno_min,
                row_horario.tipo_usuario,
                TRUE,
                NOW(),
                NOW()
            )
            RETURNING id INTO v_horario_id;
        ELSE
            UPDATE train_gimnasio.horarios_gym
            SET
                capacidad_maxima = row_horario.capacidad_maxima,
                tiempo_turno_min = row_horario.tiempo_turno_min,
                tipo_usuario = row_horario.tipo_usuario,
                activo = TRUE,
                updated_at = NOW()
            WHERE id = v_horario_id;
        END IF;

        DELETE FROM train_gimnasio.horarios_gym_dias
        WHERE horario_id = v_horario_id;

        INSERT INTO train_gimnasio.horarios_gym_dias (horario_id, dia_semana)
        SELECT v_horario_id, unnest(row_horario.dias);

        v_horario_id := NULL;
    END LOOP;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
UPDATE train_gimnasio.horarios_gym
SET activo = FALSE, updated_at = NOW()
WHERE tipo_servicio_id IN (
    SELECT id
    FROM train_gimnasio.tipos_servicios
    WHERE LOWER(nombre) IN (
        'acceso general con pizarra',
        'fuerza e hipertrofia',
        'entrenamiento funcional',
        'hiit metabólico',
        'clase grupal funcional',
        'personalizado 1:1',
        'semi personalizado',
        'evaluación física inicial',
        'control y seguimiento',
        'movilidad y recuperación',
        'preparación física xpadel',
        'acceso general xpadel'
    )
);
SQL);
    }
};
