BEGIN;

DO $$
DECLARE
    v_plan_id BIGINT := 4;
    v_dia_id BIGINT;
    v_bloque_id BIGINT;
    v_plan_ejercicio_id BIGINT;
    v_transferencia_id BIGINT;

    v_intercambios BIGINT;
    v_dos_pies BIGINT;
    v_pogos BIGINT;
    v_cargada BIGINT;
    v_salto_sentado BIGINT;
    v_sentadilla BIGINT;
    v_transferencia_cajon BIGINT;
    v_asistido BIGINT;
    v_tikitaka BIGINT;
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM entrenamiento.planes
        WHERE id = v_plan_id
          AND lower(btrim(nombre)) = lower(btrim('Plan Deportivo (Grupal)'))
    ) THEN
        RAISE EXCEPTION 'No existe el plan 4 como Plan Deportivo (Grupal). Revisa el id antes de ejecutar.';
    END IF;

    INSERT INTO entrenamiento.ejercicios (
        gimnasio_id,
        nombre,
        grupo_muscular,
        equipamiento,
        tipo_entrenamiento,
        instrucciones,
        activo,
        created_at,
        updated_at
    )
    SELECT
        1,
        'Tiki-Taka en step con simulacion de remate',
        'Agilidad',
        'Step',
        'Deportivo',
        'Trabajo coordinativo en step seguido de simulacion tecnica de remate.',
        TRUE,
        NOW(),
        NOW()
    WHERE NOT EXISTS (
        SELECT 1
        FROM entrenamiento.ejercicios
        WHERE lower(btrim(nombre)) = lower(btrim('Tiki-Taka en step con simulacion de remate'))
    );

    SELECT id INTO v_intercambios
    FROM entrenamiento.ejercicios
    WHERE lower(btrim(nombre)) = lower(btrim('Intercambios con mancuernas'))
    LIMIT 1;

    SELECT id INTO v_dos_pies
    FROM entrenamiento.ejercicios
    WHERE lower(btrim(nombre)) = lower(btrim('Dos pies a un pie'))
    LIMIT 1;

    SELECT id INTO v_pogos
    FROM entrenamiento.ejercicios
    WHERE lower(btrim(nombre)) = lower(btrim('Pogos'))
    LIMIT 1;

    SELECT id INTO v_cargada
    FROM entrenamiento.ejercicios
    WHERE lower(btrim(nombre)) = lower(btrim('Cargada deportiva'))
    LIMIT 1;

    SELECT id INTO v_salto_sentado
    FROM entrenamiento.ejercicios
    WHERE lower(btrim(nombre)) = lower(btrim('Salto al cajón desde Sent.'))
    LIMIT 1;

    SELECT id INTO v_sentadilla
    FROM entrenamiento.ejercicios
    WHERE lower(btrim(nombre)) = lower(btrim('Sentadilla'))
    LIMIT 1;

    SELECT id INTO v_transferencia_cajon
    FROM entrenamiento.ejercicios
    WHERE lower(btrim(nombre)) = lower(btrim('Transferencia cajón medio, cajón alto'))
    LIMIT 1;

    SELECT id INTO v_asistido
    FROM entrenamiento.ejercicios
    WHERE lower(btrim(nombre)) = lower(btrim('Asistido'))
    LIMIT 1;

    SELECT id INTO v_tikitaka
    FROM entrenamiento.ejercicios
    WHERE lower(btrim(nombre)) = lower(btrim('Tiki-Taka en step con simulacion de remate'))
    LIMIT 1;

    IF v_intercambios IS NULL
        OR v_dos_pies IS NULL
        OR v_pogos IS NULL
        OR v_cargada IS NULL
        OR v_salto_sentado IS NULL
        OR v_sentadilla IS NULL
        OR v_transferencia_cajon IS NULL
        OR v_asistido IS NULL
        OR v_tikitaka IS NULL
    THEN
        RAISE EXCEPTION 'Faltan ejercicios base en produccion. Revisa nombres en entrenamiento.ejercicios.';
    END IF;

    DELETE FROM entrenamiento.plan_dias
    WHERE plan_id = v_plan_id
      AND semana = 3
      AND dia = 'MARTES';

    INSERT INTO entrenamiento.plan_dias (
        plan_id,
        semana,
        dia,
        nombre_sesion,
        observaciones,
        created_at,
        updated_at
    )
    VALUES (
        v_plan_id,
        3,
        'MARTES',
        'Deportivo etapa 3 - Semana #3 - Martes',
        'Carga del martes 2026-07-21 desde pizarra: pliometria, fuerza reactiva, velocidad y transferencias.',
        NOW(),
        NOW()
    )
    RETURNING id INTO v_dia_id;

    INSERT INTO entrenamiento.plan_bloques (plan_dia_id, nombre, tipo_bloque, orden, created_at, updated_at)
    VALUES (v_dia_id, 'Pliometria y coordinacion', 'Pliometria y coordinacion', 1, NOW(), NOW())
    RETURNING id INTO v_bloque_id;

    INSERT INTO entrenamiento.plan_ejercicios (plan_bloque_id, ejercicio_id, orden, observaciones, usa_rm, created_at, updated_at)
    VALUES (v_bloque_id, v_intercambios, 1, 'Pizarra: Intercambio 4x20.', FALSE, NOW(), NOW())
    RETURNING id INTO v_plan_ejercicio_id;
    INSERT INTO entrenamiento.plan_ejercicio_series (plan_ejercicio_id, numero_serie, tipo_carga, repeticiones, created_at, updated_at)
    VALUES
        (v_plan_ejercicio_id, 1, 'LIBRE', '20', NOW(), NOW()),
        (v_plan_ejercicio_id, 2, 'LIBRE', '20', NOW(), NOW()),
        (v_plan_ejercicio_id, 3, 'LIBRE', '20', NOW(), NOW()),
        (v_plan_ejercicio_id, 4, 'LIBRE', '20', NOW(), NOW());

    INSERT INTO entrenamiento.plan_ejercicios (plan_bloque_id, ejercicio_id, orden, observaciones, usa_rm, created_at, updated_at)
    VALUES (v_bloque_id, v_dos_pies, 2, 'Pizarra: 2 pie a 1, 4x16.', FALSE, NOW(), NOW())
    RETURNING id INTO v_plan_ejercicio_id;
    INSERT INTO entrenamiento.plan_ejercicio_series (plan_ejercicio_id, numero_serie, tipo_carga, repeticiones, created_at, updated_at)
    VALUES
        (v_plan_ejercicio_id, 1, 'LIBRE', '16', NOW(), NOW()),
        (v_plan_ejercicio_id, 2, 'LIBRE', '16', NOW(), NOW()),
        (v_plan_ejercicio_id, 3, 'LIBRE', '16', NOW(), NOW()),
        (v_plan_ejercicio_id, 4, 'LIBRE', '16', NOW(), NOW());

    INSERT INTO entrenamiento.plan_ejercicios (plan_bloque_id, ejercicio_id, orden, observaciones, usa_rm, created_at, updated_at)
    VALUES (v_bloque_id, v_pogos, 3, 'Avanzando todo el cesped.', FALSE, NOW(), NOW())
    RETURNING id INTO v_plan_ejercicio_id;
    INSERT INTO entrenamiento.plan_ejercicio_series (plan_ejercicio_id, numero_serie, tipo_carga, repeticiones, created_at, updated_at)
    VALUES
        (v_plan_ejercicio_id, 1, 'LIBRE', 'Todo el cesped', NOW(), NOW()),
        (v_plan_ejercicio_id, 2, 'LIBRE', 'Todo el cesped', NOW(), NOW()),
        (v_plan_ejercicio_id, 3, 'LIBRE', 'Todo el cesped', NOW(), NOW()),
        (v_plan_ejercicio_id, 4, 'LIBRE', 'Todo el cesped', NOW(), NOW());

    INSERT INTO entrenamiento.plan_bloques (plan_dia_id, nombre, tipo_bloque, orden, created_at, updated_at)
    VALUES (v_dia_id, 'Potencia y transferencias', 'Potencia y transferencias', 2, NOW(), NOW())
    RETURNING id INTO v_bloque_id;

    INSERT INTO entrenamiento.plan_ejercicios (plan_bloque_id, ejercicio_id, orden, observaciones, usa_rm, created_at, updated_at)
    VALUES (v_bloque_id, v_cargada, 1, 'Pizarra: Clean power 8-6-4.', FALSE, NOW(), NOW())
    RETURNING id INTO v_plan_ejercicio_id;
    INSERT INTO entrenamiento.plan_ejercicio_series (plan_ejercicio_id, numero_serie, tipo_carga, repeticiones, created_at, updated_at)
    VALUES
        (v_plan_ejercicio_id, 1, 'LIBRE', '8', NOW(), NOW()),
        (v_plan_ejercicio_id, 2, 'LIBRE', '6', NOW(), NOW()),
        (v_plan_ejercicio_id, 3, 'LIBRE', '4', NOW(), NOW());

    INSERT INTO entrenamiento.plan_ejercicio_transferencias (plan_ejercicio_id, ejercicio_id, orden, observaciones, created_at, updated_at)
    VALUES (v_plan_ejercicio_id, v_salto_sentado, 1, 'Transferencia de sentado a cajon alto.', NOW(), NOW())
    RETURNING id INTO v_transferencia_id;
    INSERT INTO entrenamiento.plan_transferencia_series (transferencia_id, numero_serie, tipo_carga, repeticiones, created_at, updated_at)
    VALUES
        (v_transferencia_id, 1, 'LIBRE', '6', NOW(), NOW()),
        (v_transferencia_id, 2, 'LIBRE', '6', NOW(), NOW()),
        (v_transferencia_id, 3, 'LIBRE', '6', NOW(), NOW());

    INSERT INTO entrenamiento.plan_bloques (plan_dia_id, nombre, tipo_bloque, orden, created_at, updated_at)
    VALUES (v_dia_id, 'Contraste fuerza reactiva', 'Contraste fuerza reactiva', 3, NOW(), NOW())
    RETURNING id INTO v_bloque_id;

    INSERT INTO entrenamiento.plan_ejercicios (plan_bloque_id, ejercicio_id, orden, observaciones, usa_rm, created_at, updated_at)
    VALUES (v_bloque_id, v_sentadilla, 1, 'Contraste de fuerza.', TRUE, NOW(), NOW())
    RETURNING id INTO v_plan_ejercicio_id;
    INSERT INTO entrenamiento.plan_ejercicio_series (plan_ejercicio_id, numero_serie, tipo_carga, porcentaje_rm, repeticiones, created_at, updated_at)
    VALUES
        (v_plan_ejercicio_id, 1, 'PORCENTAJE_RM', 65, '7', NOW(), NOW()),
        (v_plan_ejercicio_id, 2, 'PORCENTAJE_RM', 75, '5', NOW(), NOW()),
        (v_plan_ejercicio_id, 3, 'PORCENTAJE_RM', 85, '3', NOW(), NOW()),
        (v_plan_ejercicio_id, 4, 'PORCENTAJE_RM', 75, '5', NOW(), NOW());

    INSERT INTO entrenamiento.plan_ejercicio_transferencias (plan_ejercicio_id, ejercicio_id, orden, observaciones, created_at, updated_at)
    VALUES (v_plan_ejercicio_id, v_transferencia_cajon, 1, 'Salto pliometrico de cajon bajo a cajon alto.', NOW(), NOW())
    RETURNING id INTO v_transferencia_id;
    INSERT INTO entrenamiento.plan_transferencia_series (transferencia_id, numero_serie, tipo_carga, repeticiones, created_at, updated_at)
    VALUES
        (v_transferencia_id, 1, 'LIBRE', '4', NOW(), NOW()),
        (v_transferencia_id, 2, 'LIBRE', '4', NOW(), NOW()),
        (v_transferencia_id, 3, 'LIBRE', '4', NOW(), NOW()),
        (v_transferencia_id, 4, 'LIBRE', '4', NOW(), NOW());

    INSERT INTO entrenamiento.plan_ejercicios (plan_bloque_id, ejercicio_id, orden, observaciones, usa_rm, created_at, updated_at)
    VALUES (v_bloque_id, v_cargada, 2, 'Pizarra: Cargada de potencia con salto.', FALSE, NOW(), NOW())
    RETURNING id INTO v_plan_ejercicio_id;
    INSERT INTO entrenamiento.plan_ejercicio_series (plan_ejercicio_id, numero_serie, tipo_carga, repeticiones, created_at, updated_at)
    VALUES
        (v_plan_ejercicio_id, 1, 'LIBRE', '5', NOW(), NOW()),
        (v_plan_ejercicio_id, 2, 'LIBRE', '5', NOW(), NOW()),
        (v_plan_ejercicio_id, 3, 'LIBRE', '5', NOW(), NOW()),
        (v_plan_ejercicio_id, 4, 'LIBRE', '5', NOW(), NOW());

    INSERT INTO entrenamiento.plan_ejercicio_transferencias (plan_ejercicio_id, ejercicio_id, orden, observaciones, created_at, updated_at)
    VALUES (v_plan_ejercicio_id, v_asistido, 1, 'Con ligas; lo mas alto posible.', NOW(), NOW())
    RETURNING id INTO v_transferencia_id;
    INSERT INTO entrenamiento.plan_transferencia_series (transferencia_id, numero_serie, tipo_carga, repeticiones, created_at, updated_at)
    VALUES
        (v_transferencia_id, 1, 'LIBRE', '10', NOW(), NOW()),
        (v_transferencia_id, 2, 'LIBRE', '10', NOW(), NOW()),
        (v_transferencia_id, 3, 'LIBRE', '10', NOW(), NOW()),
        (v_transferencia_id, 4, 'LIBRE', '10', NOW(), NOW());

    INSERT INTO entrenamiento.plan_bloques (plan_dia_id, nombre, tipo_bloque, orden, created_at, updated_at)
    VALUES (v_dia_id, 'Velocidad especifica', 'Velocidad especifica', 4, NOW(), NOW())
    RETURNING id INTO v_bloque_id;

    INSERT INTO entrenamiento.plan_ejercicios (plan_bloque_id, ejercicio_id, orden, observaciones, usa_rm, created_at, updated_at)
    VALUES (v_bloque_id, v_tikitaka, 1, 'Tiki-Taka en step 30 segundos + simulacion de remate x15.', FALSE, NOW(), NOW())
    RETURNING id INTO v_plan_ejercicio_id;
    INSERT INTO entrenamiento.plan_ejercicio_series (plan_ejercicio_id, numero_serie, tipo_carga, repeticiones, tiempo_segundos, created_at, updated_at)
    VALUES
        (v_plan_ejercicio_id, 1, 'LIBRE', '15 remates', 30, NOW(), NOW()),
        (v_plan_ejercicio_id, 2, 'LIBRE', '15 remates', 30, NOW(), NOW()),
        (v_plan_ejercicio_id, 3, 'LIBRE', '15 remates', 30, NOW(), NOW()),
        (v_plan_ejercicio_id, 4, 'LIBRE', '15 remates', 30, NOW(), NOW());
END $$;

COMMIT;

SELECT
    pd.id AS plan_dia_id,
    pd.plan_id,
    pd.semana,
    pd.dia,
    pd.nombre_sesion,
    COUNT(DISTINCT pb.id) AS bloques,
    COUNT(DISTINCT pe.id) AS ejercicios,
    COUNT(DISTINCT pes.id) AS series,
    COUNT(DISTINCT pet.id) AS transferencias
FROM entrenamiento.plan_dias pd
LEFT JOIN entrenamiento.plan_bloques pb ON pb.plan_dia_id = pd.id
LEFT JOIN entrenamiento.plan_ejercicios pe ON pe.plan_bloque_id = pb.id
LEFT JOIN entrenamiento.plan_ejercicio_series pes ON pes.plan_ejercicio_id = pe.id
LEFT JOIN entrenamiento.plan_ejercicio_transferencias pet ON pet.plan_ejercicio_id = pe.id
WHERE pd.plan_id = 4
  AND pd.semana = 3
  AND pd.dia = 'MARTES'
GROUP BY pd.id, pd.plan_id, pd.semana, pd.dia, pd.nombre_sesion;
