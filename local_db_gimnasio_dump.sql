--
-- PostgreSQL database dump
--

\restrict H4WrxUOelGEInyZerDrefVeJQRmhGyN0BxctdJkUYqMUceurN5xgme9gYaVKc8y

-- Dumped from database version 18.1
-- Dumped by pg_dump version 18.3

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: auditoria; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA auditoria;


--
-- Name: core; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA core;


--
-- Name: entrenamiento; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA entrenamiento;


--
-- Name: inventario; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA inventario;


--
-- Name: salud; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA salud;


--
-- Name: seguridad; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA seguridad;


--
-- Name: socios; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA socios;


--
-- Name: train_gimnasio; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA train_gimnasio;


--
-- Name: ventas; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA ventas;


--
-- Name: fn_evento_auto_auditar(); Type: FUNCTION; Schema: train_gimnasio; Owner: -
--

CREATE FUNCTION train_gimnasio.fn_evento_auto_auditar() RETURNS event_trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  obj RECORD;
  tbl TEXT;
  trg TEXT;
BEGIN
  FOR obj IN SELECT * FROM pg_event_trigger_ddl_commands()
  LOOP
    IF obj.object_type = 'table' AND obj.schema_name = 'gym' THEN
      tbl := obj.object_name;

      IF tbl IN ('aud_cambios') THEN
        CONTINUE;
      END IF;

      trg := substr('trg_aud_' || tbl, 1, 63);

      EXECUTE format('DROP TRIGGER IF EXISTS %I ON gym.%I;', trg, tbl);
      EXECUTE format(
        'CREATE TRIGGER %I
         AFTER INSERT OR UPDATE OR DELETE ON gym.%I
         FOR EACH ROW
         EXECUTE FUNCTION gym.fn_auditar_cambios();',
        trg, tbl
      );
    END IF;
  END LOOP;
END;
$$;


--
-- Name: fn_set_updated_at(); Type: FUNCTION; Schema: train_gimnasio; Owner: -
--

CREATE FUNCTION train_gimnasio.fn_set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;


--
-- Name: generar_turnos_disponibles(date, bigint, bigint, bigint, bigint); Type: FUNCTION; Schema: train_gimnasio; Owner: -
--

CREATE FUNCTION train_gimnasio.generar_turnos_disponibles(p_fecha date, p_sede_id bigint, p_tipo_servicio_id bigint, p_tipo_usuario bigint, p_estado_cancelado bigint DEFAULT 32) RETURNS TABLE(horario_id bigint, tipo_servicio_id bigint, servicio_nombre text, servicio_descripcion text, fecha date, hora_inicio time without time zone, hora_fin time without time zone, capacidad_maxima bigint, reservado bigint, turnos_disponibles bigint, disponible boolean)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_dia_iso smallint;
BEGIN
    v_dia_iso := extract(isodow FROM p_fecha)::smallint;

    RETURN QUERY
    SELECT
        h.id AS horario_id,
        h.tipo_servicio_id,
        ts.nombre,
        ts.descripcion,
        p_fecha,
        hs.hora_inicio,
        (hs.hora_inicio + (h.tiempo_turno_min || ' minutes')::interval)::time AS hora_fin,
        h.capacidad_maxima::bigint,
        r.total_reservados::bigint,
        GREATEST(h.capacidad_maxima - r.total_reservados, 0)::bigint,
        (r.total_reservados < h.capacidad_maxima) AS disponible
    FROM train_gimnasio.horarios_gym h
    JOIN train_gimnasio.horarios_gym_dias d
      ON d.horario_id = h.id
     AND d.dia_semana = v_dia_iso
    JOIN train_gimnasio.tipos_servicios ts
      ON ts.id = h.tipo_servicio_id

    CROSS JOIN LATERAL (
        SELECT generate_series(
            (p_fecha::timestamp + h.hora_apertura),
            (p_fecha::timestamp + h.hora_cierre - (h.tiempo_turno_min || ' minutes')::interval),
            (h.tiempo_turno_min || ' minutes')::interval
        )::time AS hora_inicio
    ) hs

    JOIN LATERAL (
        SELECT COUNT(*) AS total_reservados
        FROM train_gimnasio.reservas_gym r
        WHERE r.fecha = p_fecha
          AND r.hora = hs.hora_inicio
          AND r.horario_id = h.id
          AND r.tipo_servicio_id = p_tipo_servicio_id
          AND r.sede_id = p_sede_id
          AND r.estado_id <> p_estado_cancelado
    ) r ON TRUE

    WHERE
        h.sede_id = p_sede_id
        AND h.tipo_servicio_id = p_tipo_servicio_id
        AND h.tipo_usuario = p_tipo_usuario
        AND h.activo = true
        AND r.total_reservados < h.capacidad_maxima
        AND (
            p_fecha > CURRENT_DATE
            OR (p_fecha = CURRENT_DATE AND hs.hora_inicio > CURRENT_TIME)
        )
    ORDER BY hs.hora_inicio;
END;
$$;


--
-- Name: obtener_turnos_futuros_hoy(bigint, bigint, bigint, integer); Type: FUNCTION; Schema: train_gimnasio; Owner: -
--

CREATE FUNCTION train_gimnasio.obtener_turnos_futuros_hoy(p_sede_id bigint, p_tipo_servicio_id bigint, p_tipo_usuario bigint, p_dias_adelante integer DEFAULT 7) RETURNS TABLE(fecha date, hora_inicio time without time zone, hora_fin time without time zone, horario_id bigint, tipo_servicio_id bigint, servicio_nombre text, cupos_restantes bigint, disponible boolean)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    WITH fechas AS (
        SELECT (CURRENT_DATE + gs)::date AS f
        FROM generate_series(0, GREATEST(p_dias_adelante,0)) gs
    )
    SELECT
        x.fecha,
        x.hora_inicio,
        x.hora_fin,
        x.horario_id,
        x.tipo_servicio_id,
        x.servicio_nombre,
        x.turnos_disponibles AS cupos_restantes,
        x.disponible
    FROM fechas f
    CROSS JOIN LATERAL train_gimnasio.generar_turnos_disponibles(
        f.f, p_sede_id, p_tipo_servicio_id, p_tipo_usuario
    ) x;
END;
$$;


--
-- Name: reservar_turno(date, time without time zone, bigint, bigint, bigint, bigint, bigint, bigint); Type: FUNCTION; Schema: train_gimnasio; Owner: -
--

CREATE FUNCTION train_gimnasio.reservar_turno(p_fecha date, p_hora time without time zone, p_horario_id bigint, p_sede_id bigint, p_servicio_id bigint, p_user_id bigint, p_estado_reservado bigint DEFAULT 8, p_estado_cancelado bigint DEFAULT 32) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_capacidad integer;
    v_reservados integer;
    v_reserva_id bigint;
BEGIN
    SELECT capacidad_maxima
    INTO v_capacidad
    FROM train_gimnasio.horarios_gym
    WHERE id = p_horario_id
      AND sede_id = p_sede_id
      AND servicio_id = p_servicio_id
      AND activo = true;

    IF v_capacidad IS NULL THEN
        RAISE EXCEPTION 'Horario no válido o no activo';
    END IF;

    SELECT COUNT(*)
    INTO v_reservados
    FROM train_gimnasio.reservas_gym
    WHERE fecha = p_fecha
      AND hora = p_hora
      AND horario_id = p_horario_id
      AND sede_id = p_sede_id
      AND servicio_id = p_servicio_id
      AND estado_id <> p_estado_cancelado
    FOR UPDATE;

    IF v_reservados >= v_capacidad THEN
        RAISE EXCEPTION 'Cupo lleno';
    END IF;

    INSERT INTO train_gimnasio.reservas_gym
      (fecha, hora, horario_id, sede_id, servicio_id, user_id, estado_id)
    VALUES
      (p_fecha, p_hora, p_horario_id, p_sede_id, p_servicio_id, p_user_id, p_estado_reservado)
    RETURNING id INTO v_reserva_id;

    RETURN v_reserva_id;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: aud_cambios; Type: TABLE; Schema: auditoria; Owner: -
--

CREATE TABLE auditoria.aud_cambios (
    id bigint NOT NULL,
    gimnasio_id bigint,
    sede_id bigint,
    actor_usuario_id bigint,
    actor_rol_id bigint,
    operacion character(1) NOT NULL,
    esquema character varying(63) NOT NULL,
    tabla character varying(63) NOT NULL,
    registro_id bigint,
    datos_antes jsonb,
    datos_despues jsonb,
    campos_cambiados jsonb,
    request_id character varying(80),
    ip character varying(60),
    user_agent text,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT aud_cambios_creado_en_not_null NOT NULL,
    registro_pk jsonb,
    ip_publica inet,
    ip_forwarded_for text,
    proxy_headers jsonb,
    tipo_dispositivo text,
    sistema_operativo text,
    navegador text,
    equipo_nombre text,
    equipo_usuario text,
    ip_bd inet,
    actor_persona_id bigint,
    modulo character varying(80),
    accion character varying(120)
);


--
-- Name: aud_cambios_id_seq; Type: SEQUENCE; Schema: auditoria; Owner: -
--

CREATE SEQUENCE auditoria.aud_cambios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: aud_cambios_id_seq; Type: SEQUENCE OWNED BY; Schema: auditoria; Owner: -
--

ALTER SEQUENCE auditoria.aud_cambios_id_seq OWNED BY auditoria.aud_cambios.id;


--
-- Name: estados; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.estados (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: estados_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.estados_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: estados_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.estados_id_seq OWNED BY core.estados.id;


--
-- Name: persona_tipo_detalle; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.persona_tipo_detalle (
    id bigint NOT NULL,
    persona_id bigint NOT NULL,
    tipo_id bigint NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    fecha_inicio date DEFAULT CURRENT_DATE,
    fecha_fin date,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: persona_tipo_detalle_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.persona_tipo_detalle_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: persona_tipo_detalle_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.persona_tipo_detalle_id_seq OWNED BY core.persona_tipo_detalle.id;


--
-- Name: persona_tipos; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.persona_tipos (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    activo boolean DEFAULT true NOT NULL
);


--
-- Name: persona_tipos_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.persona_tipos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: persona_tipos_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.persona_tipos_id_seq OWNED BY core.persona_tipos.id;


--
-- Name: personas; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.personas (
    id bigint NOT NULL,
    gimnasio_id bigint,
    tipo_identificacion character varying(20) DEFAULT 'CEDULA'::character varying NOT NULL,
    numero_identificacion character varying(30) NOT NULL,
    nombres character varying(120) NOT NULL,
    apellidos character varying(120),
    fecha_nacimiento date,
    sexo character varying(20),
    nacionalidad character varying(120),
    provincia character varying(120),
    ciudad character varying(120),
    parroquia character varying(120),
    direccion text,
    telefono character varying(30),
    email character varying(150),
    foto_url text,
    estado_id bigint,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: personas_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.personas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personas_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.personas_id_seq OWNED BY core.personas.id;


--
-- Name: sedes; Type: TABLE; Schema: core; Owner: -
--

CREATE TABLE core.sedes (
    id bigint NOT NULL,
    gimnasio_id bigint,
    nombre character varying(150) NOT NULL,
    direccion text,
    telefono character varying(30),
    activa boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: sedes_id_seq; Type: SEQUENCE; Schema: core; Owner: -
--

CREATE SEQUENCE core.sedes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sedes_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: -
--

ALTER SEQUENCE core.sedes_id_seq OWNED BY core.sedes.id;


--
-- Name: ejecuciones; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.ejecuciones (
    id bigint NOT NULL,
    plan_id bigint NOT NULL,
    rutina_id bigint NOT NULL,
    fecha_ejecucion date NOT NULL,
    estado character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    series_completadas integer,
    repeticiones_reales character varying(50),
    carga_real numeric(10,2),
    unidad_carga_real character varying(20),
    rpe_real numeric(4,1),
    dolor_nivel integer,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: ejecuciones_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.ejecuciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ejecuciones_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.ejecuciones_id_seq OWNED BY entrenamiento.ejecuciones.id;


--
-- Name: ejercicios; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.ejercicios (
    id bigint NOT NULL,
    gimnasio_id bigint,
    nombre character varying(150) NOT NULL,
    grupo_muscular character varying(50) NOT NULL,
    equipamiento character varying(50) NOT NULL,
    instrucciones text,
    url_recurso text,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    tipo_entrenamiento character varying(50) DEFAULT 'GENERAL'::character varying
);


--
-- Name: ejercicios_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.ejercicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ejercicios_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.ejercicios_id_seq OWNED BY entrenamiento.ejercicios.id;


--
-- Name: evaluaciones; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.evaluaciones (
    id bigint NOT NULL,
    persona_id bigint NOT NULL,
    tipo_evaluacion character varying(50) NOT NULL,
    fecha_evaluacion date DEFAULT CURRENT_DATE NOT NULL,
    resultado_resumen text,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    nivel_resultado character varying(30) DEFAULT 'MEDIO'::character varying,
    fecha_proxima_evaluacion date
);


--
-- Name: evaluaciones_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.evaluaciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: evaluaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.evaluaciones_id_seq OWNED BY entrenamiento.evaluaciones.id;


--
-- Name: plan_asignaciones; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plan_asignaciones (
    id bigint NOT NULL,
    plan_id bigint NOT NULL,
    alcance character varying(20) DEFAULT 'GRUPAL'::character varying NOT NULL,
    persona_id bigint,
    nombre_grupo character varying(120),
    fecha_inicio date,
    fecha_fin date,
    estado character varying(30) DEFAULT 'ACTIVO'::character varying NOT NULL,
    observaciones text,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: plan_asignaciones_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plan_asignaciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_asignaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plan_asignaciones_id_seq OWNED BY entrenamiento.plan_asignaciones.id;


--
-- Name: plan_bloques; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plan_bloques (
    id bigint NOT NULL,
    plan_dia_id bigint NOT NULL,
    nombre character varying(120) NOT NULL,
    tipo_bloque character varying(60),
    orden integer DEFAULT 1 NOT NULL,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plan_bloques_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plan_bloques_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_bloques_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plan_bloques_id_seq OWNED BY entrenamiento.plan_bloques.id;


--
-- Name: plan_dias; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plan_dias (
    id bigint NOT NULL,
    plan_id bigint NOT NULL,
    semana integer NOT NULL,
    dia character varying(30) NOT NULL,
    nombre_sesion character varying(150),
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plan_dias_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plan_dias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_dias_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plan_dias_id_seq OWNED BY entrenamiento.plan_dias.id;


--
-- Name: plan_ejecuciones; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plan_ejecuciones (
    id bigint NOT NULL,
    plan_id bigint NOT NULL,
    plan_ejercicio_id bigint NOT NULL,
    fecha_ejecucion date NOT NULL,
    estado character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    series_completadas integer,
    repeticiones_reales text,
    carga_real numeric(10,2),
    unidad_carga_real character varying(20),
    rpe_real numeric(4,1),
    dolor_nivel integer,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plan_ejecuciones_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plan_ejecuciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_ejecuciones_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plan_ejecuciones_id_seq OWNED BY entrenamiento.plan_ejecuciones.id;


--
-- Name: plan_ejercicio_series; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plan_ejercicio_series (
    id bigint NOT NULL,
    plan_ejercicio_id bigint NOT NULL,
    numero_serie integer NOT NULL,
    tipo_carga character varying(30) DEFAULT 'LIBRE'::character varying NOT NULL,
    porcentaje_rm numeric(5,2),
    carga_fija numeric(10,2),
    unidad_carga character varying(20),
    repeticiones character varying(50),
    tiempo_segundos integer,
    distancia_metros numeric(10,2),
    rpe numeric(4,2),
    descanso_segundos integer,
    tempo character varying(30),
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plan_ejercicio_series_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plan_ejercicio_series_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_ejercicio_series_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plan_ejercicio_series_id_seq OWNED BY entrenamiento.plan_ejercicio_series.id;


--
-- Name: plan_ejercicio_transferencias; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plan_ejercicio_transferencias (
    id bigint NOT NULL,
    plan_ejercicio_id bigint NOT NULL,
    ejercicio_id bigint,
    orden integer DEFAULT 1 NOT NULL,
    modo_aplicacion character varying(30) DEFAULT 'POR_CADA_SERIE'::character varying NOT NULL,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    nombre_libre character varying(150)
);


--
-- Name: plan_ejercicio_transferencias_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plan_ejercicio_transferencias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_ejercicio_transferencias_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plan_ejercicio_transferencias_id_seq OWNED BY entrenamiento.plan_ejercicio_transferencias.id;


--
-- Name: plan_ejercicios; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plan_ejercicios (
    id bigint NOT NULL,
    plan_bloque_id bigint NOT NULL,
    ejercicio_id bigint,
    orden integer DEFAULT 1 NOT NULL,
    lado character varying(20),
    observaciones text,
    usa_rm boolean DEFAULT false NOT NULL,
    rm_referencia numeric(10,2),
    rm_registro_id bigint,
    modo_prescripcion character varying(30) DEFAULT 'POR_SERIE'::character varying NOT NULL,
    descanso_segundos integer,
    tempo character varying(30),
    rpe_objetivo numeric(4,2),
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    nombre_libre character varying(150)
);


--
-- Name: plan_ejercicios_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plan_ejercicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_ejercicios_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plan_ejercicios_id_seq OWNED BY entrenamiento.plan_ejercicios.id;


--
-- Name: plan_transferencia_series; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plan_transferencia_series (
    id bigint NOT NULL,
    transferencia_id bigint NOT NULL,
    numero_serie integer NOT NULL,
    tipo_carga character varying(30) DEFAULT 'LIBRE'::character varying NOT NULL,
    porcentaje_rm numeric(5,2),
    carga_fija numeric(10,2),
    unidad_carga character varying(20),
    repeticiones character varying(50),
    tiempo_segundos integer,
    distancia_metros numeric(10,2),
    rpe numeric(4,2),
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plan_transferencia_series_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plan_transferencia_series_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_transferencia_series_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plan_transferencia_series_id_seq OWNED BY entrenamiento.plan_transferencia_series.id;


--
-- Name: planes; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.planes (
    id bigint NOT NULL,
    persona_id bigint,
    nombre character varying(150) NOT NULL,
    objetivo text,
    fecha_inicio date NOT NULL,
    fecha_fin date,
    estado character varying(30) DEFAULT 'BORRADOR'::character varying NOT NULL,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    tipo character varying(50) DEFAULT 'HIBRIDO'::character varying,
    estructura character varying(30) DEFAULT 'SEMANAL'::character varying NOT NULL,
    alcance character varying(20) DEFAULT 'GRUPAL'::character varying
);


--
-- Name: planes_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.planes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: planes_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.planes_id_seq OWNED BY entrenamiento.planes.id;


--
-- Name: plantilla_semana_bloques; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plantilla_semana_bloques (
    id bigint NOT NULL,
    plantilla_dia_id bigint NOT NULL,
    nombre character varying(120) NOT NULL,
    tipo_bloque character varying(60),
    orden integer DEFAULT 1 NOT NULL,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plantilla_semana_bloques_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plantilla_semana_bloques_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plantilla_semana_bloques_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plantilla_semana_bloques_id_seq OWNED BY entrenamiento.plantilla_semana_bloques.id;


--
-- Name: plantilla_semana_dias; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plantilla_semana_dias (
    id bigint NOT NULL,
    plantilla_id bigint NOT NULL,
    orden_dia integer NOT NULL,
    dia character varying(30) NOT NULL,
    nombre_sesion character varying(150),
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plantilla_semana_dias_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plantilla_semana_dias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plantilla_semana_dias_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plantilla_semana_dias_id_seq OWNED BY entrenamiento.plantilla_semana_dias.id;


--
-- Name: plantilla_semana_ejercicio_series; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plantilla_semana_ejercicio_series (
    id bigint NOT NULL,
    plantilla_ejercicio_id bigint CONSTRAINT plantilla_semana_ejercicio_seri_plantilla_ejercicio_id_not_null NOT NULL,
    numero_serie integer NOT NULL,
    tipo_carga character varying(30) DEFAULT 'LIBRE'::character varying NOT NULL,
    porcentaje_rm numeric(5,2),
    carga_fija numeric(10,2),
    unidad_carga character varying(20),
    repeticiones character varying(50),
    tiempo_segundos integer,
    distancia_metros numeric(10,2),
    rpe numeric(4,2),
    descanso_segundos integer,
    tempo character varying(30),
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plantilla_semana_ejercicio_series_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plantilla_semana_ejercicio_series_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plantilla_semana_ejercicio_series_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plantilla_semana_ejercicio_series_id_seq OWNED BY entrenamiento.plantilla_semana_ejercicio_series.id;


--
-- Name: plantilla_semana_ejercicio_transferencias; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plantilla_semana_ejercicio_transferencias (
    id bigint NOT NULL,
    plantilla_ejercicio_id bigint CONSTRAINT plantilla_semana_ejercicio_tran_plantilla_ejercicio_id_not_null NOT NULL,
    ejercicio_id bigint,
    nombre_libre character varying(150),
    orden integer DEFAULT 1 NOT NULL,
    modo_aplicacion character varying(30) DEFAULT 'POR_CADA_SERIE'::character varying CONSTRAINT plantilla_semana_ejercicio_transferenc_modo_aplicacion_not_null NOT NULL,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plantilla_semana_ejercicio_transferencias_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plantilla_semana_ejercicio_transferencias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plantilla_semana_ejercicio_transferencias_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plantilla_semana_ejercicio_transferencias_id_seq OWNED BY entrenamiento.plantilla_semana_ejercicio_transferencias.id;


--
-- Name: plantilla_semana_ejercicios; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plantilla_semana_ejercicios (
    id bigint NOT NULL,
    plantilla_bloque_id bigint NOT NULL,
    ejercicio_id bigint,
    nombre_libre character varying(150),
    orden integer DEFAULT 1 NOT NULL,
    lado character varying(20),
    observaciones text,
    usa_rm boolean DEFAULT false NOT NULL,
    modo_prescripcion character varying(30) DEFAULT 'POR_SERIE'::character varying NOT NULL,
    descanso_segundos integer,
    tempo character varying(30),
    rpe_objetivo numeric(4,2),
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plantilla_semana_ejercicios_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plantilla_semana_ejercicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plantilla_semana_ejercicios_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plantilla_semana_ejercicios_id_seq OWNED BY entrenamiento.plantilla_semana_ejercicios.id;


--
-- Name: plantilla_semana_transferencia_series; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plantilla_semana_transferencia_series (
    id bigint NOT NULL,
    transferencia_id bigint NOT NULL,
    numero_serie integer NOT NULL,
    tipo_carga character varying(30) DEFAULT 'LIBRE'::character varying NOT NULL,
    porcentaje_rm numeric(5,2),
    carga_fija numeric(10,2),
    unidad_carga character varying(20),
    repeticiones character varying(50),
    tiempo_segundos integer,
    distancia_metros numeric(10,2),
    rpe numeric(4,2),
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plantilla_semana_transferencia_series_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plantilla_semana_transferencia_series_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plantilla_semana_transferencia_series_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plantilla_semana_transferencia_series_id_seq OWNED BY entrenamiento.plantilla_semana_transferencia_series.id;


--
-- Name: plantillas_semanales; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.plantillas_semanales (
    id bigint NOT NULL,
    nombre character varying(150) NOT NULL,
    objetivo text,
    disciplina character varying(80),
    total_dias integer DEFAULT 5 NOT NULL,
    activa boolean DEFAULT true NOT NULL,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: plantillas_semanales_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.plantillas_semanales_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plantillas_semanales_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.plantillas_semanales_id_seq OWNED BY entrenamiento.plantillas_semanales.id;


--
-- Name: rm_registros; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.rm_registros (
    id bigint NOT NULL,
    persona_id bigint NOT NULL,
    ejercicio_id bigint NOT NULL,
    tipo_registro character varying(20) DEFAULT 'ESTIMADO'::character varying NOT NULL,
    peso numeric(10,2) NOT NULL,
    repeticiones integer,
    rm_estimado numeric(10,2) NOT NULL,
    fecha_registro date DEFAULT CURRENT_DATE NOT NULL,
    observaciones text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    fecha_proximo_control date
);


--
-- Name: rm_registros_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.rm_registros_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rm_registros_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.rm_registros_id_seq OWNED BY entrenamiento.rm_registros.id;


--
-- Name: rutina_plantilla_detalles; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.rutina_plantilla_detalles (
    id bigint NOT NULL,
    plantilla_id bigint NOT NULL,
    dia character varying(30) NOT NULL,
    bloque character varying(120),
    ejercicio_id bigint NOT NULL,
    series integer DEFAULT 1 NOT NULL,
    repeticiones character varying(50),
    carga_objetivo numeric(10,2),
    tipo_carga character varying(30) DEFAULT 'LIBRE'::character varying,
    unidad_objetivo character varying(20),
    tempo character varying(30),
    rpe numeric(4,1),
    descanso_segundos integer,
    orden integer DEFAULT 1,
    notas text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    bloque_orden integer DEFAULT 1,
    ejercicio_transferencia_id bigint,
    repeticiones_transferencia integer,
    series_detalles jsonb
);


--
-- Name: rutina_plantilla_detalles_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.rutina_plantilla_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rutina_plantilla_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.rutina_plantilla_detalles_id_seq OWNED BY entrenamiento.rutina_plantilla_detalles.id;


--
-- Name: rutina_plantillas; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.rutina_plantillas (
    id bigint NOT NULL,
    nombre character varying(150) NOT NULL,
    objetivo text,
    descripcion text,
    activa boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: rutina_plantillas_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.rutina_plantillas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rutina_plantillas_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.rutina_plantillas_id_seq OWNED BY entrenamiento.rutina_plantillas.id;


--
-- Name: rutinas; Type: TABLE; Schema: entrenamiento; Owner: -
--

CREATE TABLE entrenamiento.rutinas (
    id bigint NOT NULL,
    plan_id bigint NOT NULL,
    semana integer NOT NULL,
    dia character varying(30) NOT NULL,
    bloque character varying(120),
    ejercicio_id bigint NOT NULL,
    series integer DEFAULT 1 NOT NULL,
    repeticiones character varying(50),
    carga_objetivo numeric(10,2),
    tipo_carga character varying(30) DEFAULT 'LIBRE'::character varying,
    descanso_segundos integer,
    notas text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    unidad_objetivo character varying(20),
    tempo character varying(30),
    rpe numeric(4,1),
    orden integer DEFAULT 1,
    bloque_orden integer DEFAULT 1,
    ejercicio_transferencia_id bigint,
    repeticiones_transferencia integer,
    series_detalles jsonb
);


--
-- Name: rutinas_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: -
--

CREATE SEQUENCE entrenamiento.rutinas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rutinas_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: -
--

ALTER SEQUENCE entrenamiento.rutinas_id_seq OWNED BY entrenamiento.rutinas.id;


--
-- Name: categorias_producto; Type: TABLE; Schema: inventario; Owner: -
--

CREATE TABLE inventario.categorias_producto (
    id bigint NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    estado smallint DEFAULT 1 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: categorias_producto_id_seq; Type: SEQUENCE; Schema: inventario; Owner: -
--

ALTER TABLE inventario.categorias_producto ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME inventario.categorias_producto_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: movimientos_inventario; Type: TABLE; Schema: inventario; Owner: -
--

CREATE TABLE inventario.movimientos_inventario (
    id bigint NOT NULL,
    producto_id bigint NOT NULL,
    sede_id bigint NOT NULL,
    lote_id bigint,
    tipo_movimiento character varying(30) NOT NULL,
    motivo character varying(100) NOT NULL,
    cantidad numeric(12,2) NOT NULL,
    stock_anterior numeric(12,2) NOT NULL,
    stock_nuevo numeric(12,2) NOT NULL,
    costo_unitario numeric(12,2),
    precio_unitario numeric(12,2),
    referencia_tipo character varying(50),
    referencia_id bigint,
    observacion text,
    created_by bigint NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_movimientos_inventario_cantidad CHECK ((cantidad > (0)::numeric)),
    CONSTRAINT ck_movimientos_inventario_tipo CHECK (((tipo_movimiento)::text = ANY ((ARRAY['ENTRADA'::character varying, 'SALIDA'::character varying, 'AJUSTE'::character varying, 'TRANSFERENCIA_SALIDA'::character varying, 'TRANSFERENCIA_ENTRADA'::character varying])::text[])))
);


--
-- Name: movimientos_inventario_id_seq; Type: SEQUENCE; Schema: inventario; Owner: -
--

ALTER TABLE inventario.movimientos_inventario ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME inventario.movimientos_inventario_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: producto_lotes; Type: TABLE; Schema: inventario; Owner: -
--

CREATE TABLE inventario.producto_lotes (
    id bigint NOT NULL,
    producto_id bigint NOT NULL,
    sede_id bigint NOT NULL,
    codigo_lote character varying(80) NOT NULL,
    fecha_elaboracion date,
    fecha_vencimiento date,
    stock_actual numeric(12,2) DEFAULT 0 NOT NULL,
    estado smallint DEFAULT 1 NOT NULL,
    created_by bigint NOT NULL,
    updated_by bigint,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_producto_lotes_stock_actual CHECK ((stock_actual >= (0)::numeric))
);


--
-- Name: producto_lotes_id_seq; Type: SEQUENCE; Schema: inventario; Owner: -
--

ALTER TABLE inventario.producto_lotes ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME inventario.producto_lotes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: producto_precios; Type: TABLE; Schema: inventario; Owner: -
--

CREATE TABLE inventario.producto_precios (
    id bigint NOT NULL,
    producto_id bigint NOT NULL,
    sede_id bigint,
    tipo_precio character varying(20) NOT NULL,
    moneda character varying(10) DEFAULT 'PEN'::character varying NOT NULL,
    monto numeric(12,2) NOT NULL,
    vigencia_inicio timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    vigencia_fin timestamp without time zone,
    estado smallint DEFAULT 1 NOT NULL,
    created_by bigint NOT NULL,
    updated_by bigint,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_producto_precios_monto CHECK ((monto >= (0)::numeric)),
    CONSTRAINT ck_producto_precios_tipo CHECK (((tipo_precio)::text = ANY (ARRAY[('COSTO'::character varying)::text, ('VENTA'::character varying)::text, ('SOCIO'::character varying)::text, ('PROMOCION'::character varying)::text])))
);


--
-- Name: producto_precios_id_seq; Type: SEQUENCE; Schema: inventario; Owner: -
--

ALTER TABLE inventario.producto_precios ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME inventario.producto_precios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: producto_stock_sede; Type: TABLE; Schema: inventario; Owner: -
--

CREATE TABLE inventario.producto_stock_sede (
    id bigint NOT NULL,
    producto_id bigint NOT NULL,
    sede_id bigint NOT NULL,
    stock_actual numeric(12,2) DEFAULT 0 NOT NULL,
    stock_reservado numeric(12,2) DEFAULT 0 NOT NULL,
    stock_disponible numeric(12,2) DEFAULT 0 NOT NULL,
    stock_minimo numeric(12,2) DEFAULT 0 NOT NULL,
    ubicacion character varying(120),
    estado smallint DEFAULT 1 NOT NULL,
    created_by bigint NOT NULL,
    updated_by bigint,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_producto_stock_sede_stock_actual CHECK ((stock_actual >= (0)::numeric)),
    CONSTRAINT ck_producto_stock_sede_stock_disponible CHECK ((stock_disponible >= (0)::numeric)),
    CONSTRAINT ck_producto_stock_sede_stock_minimo CHECK ((stock_minimo >= (0)::numeric)),
    CONSTRAINT ck_producto_stock_sede_stock_reservado CHECK ((stock_reservado >= (0)::numeric))
);


--
-- Name: producto_stock_sede_id_seq; Type: SEQUENCE; Schema: inventario; Owner: -
--

ALTER TABLE inventario.producto_stock_sede ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME inventario.producto_stock_sede_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: productos; Type: TABLE; Schema: inventario; Owner: -
--

CREATE TABLE inventario.productos (
    id bigint NOT NULL,
    codigo character varying(50) NOT NULL,
    nombre character varying(150) NOT NULL,
    descripcion text,
    categoria_id bigint NOT NULL,
    marca character varying(100),
    modelo character varying(100),
    sku character varying(80),
    codigo_barras character varying(80),
    unidad_medida character varying(30) DEFAULT 'unidad'::character varying NOT NULL,
    controla_stock boolean DEFAULT true NOT NULL,
    permite_decimales boolean DEFAULT false NOT NULL,
    maneja_lotes boolean DEFAULT false NOT NULL,
    maneja_vencimiento boolean DEFAULT false NOT NULL,
    stock_minimo numeric(12,2) DEFAULT 0 NOT NULL,
    stock_maximo numeric(12,2),
    estado smallint DEFAULT 1 NOT NULL,
    imagen_url text,
    created_by bigint NOT NULL,
    updated_by bigint,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: productos_id_seq; Type: SEQUENCE; Schema: inventario; Owner: -
--

ALTER TABLE inventario.productos ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME inventario.productos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: proveedores; Type: TABLE; Schema: inventario; Owner: -
--

CREATE TABLE inventario.proveedores (
    prov_id integer NOT NULL,
    prov_ruc text,
    prov_nombre text,
    prov_direccion text,
    prov_telefono text,
    prov_correo text,
    prov_id_usuario integer,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    prov_estado integer DEFAULT 1
);


--
-- Name: proveedores_prov_id_seq; Type: SEQUENCE; Schema: inventario; Owner: -
--

CREATE SEQUENCE inventario.proveedores_prov_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: proveedores_prov_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: -
--

ALTER SEQUENCE inventario.proveedores_prov_id_seq OWNED BY inventario.proveedores.prov_id;


--
-- Name: transferencia_detalle; Type: TABLE; Schema: inventario; Owner: -
--

CREATE TABLE inventario.transferencia_detalle (
    id bigint NOT NULL,
    transferencia_id bigint NOT NULL,
    producto_id bigint NOT NULL,
    cantidad numeric(12,2) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_transferencia_detalle_cantidad CHECK ((cantidad > (0)::numeric))
);


--
-- Name: transferencia_detalle_id_seq; Type: SEQUENCE; Schema: inventario; Owner: -
--

ALTER TABLE inventario.transferencia_detalle ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME inventario.transferencia_detalle_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: transferencias_inventario; Type: TABLE; Schema: inventario; Owner: -
--

CREATE TABLE inventario.transferencias_inventario (
    id bigint NOT NULL,
    sede_origen_id bigint NOT NULL,
    sede_destino_id bigint NOT NULL,
    estado character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    fecha_solicitud timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_envio timestamp without time zone,
    fecha_recepcion timestamp without time zone,
    observacion text,
    created_by bigint NOT NULL,
    updated_by bigint,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_transferencias_inventario_estado CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'EN_TRANSITO'::character varying, 'RECIBIDA'::character varying, 'CANCELADA'::character varying])::text[]))),
    CONSTRAINT ck_transferencias_inventario_sedes_diferentes CHECK ((sede_origen_id <> sede_destino_id))
);


--
-- Name: transferencias_inventario_id_seq; Type: SEQUENCE; Schema: inventario; Owner: -
--

ALTER TABLE inventario.transferencias_inventario ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME inventario.transferencias_inventario_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: catalogos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.catalogos (
    id bigint NOT NULL,
    grupo character varying(50) NOT NULL,
    codigo character varying(50) NOT NULL,
    nombre character varying(100) NOT NULL,
    valor_adicional character varying(255),
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: catalogos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.catalogos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: catalogos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.catalogos_id_seq OWNED BY public.catalogos.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: catalogo_patologias; Type: TABLE; Schema: salud; Owner: -
--

CREATE TABLE salud.catalogo_patologias (
    id bigint NOT NULL,
    nombre character varying(150) NOT NULL,
    descripcion text,
    activa boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: catalogo_patologias_id_seq; Type: SEQUENCE; Schema: salud; Owner: -
--

CREATE SEQUENCE salud.catalogo_patologias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: catalogo_patologias_id_seq; Type: SEQUENCE OWNED BY; Schema: salud; Owner: -
--

ALTER SEQUENCE salud.catalogo_patologias_id_seq OWNED BY salud.catalogo_patologias.id;


--
-- Name: ficha_mediciones; Type: TABLE; Schema: salud; Owner: -
--

CREATE TABLE salud.ficha_mediciones (
    id bigint NOT NULL,
    ficha_tecnica_id bigint NOT NULL,
    peso_kg numeric(10,2),
    talla_cm numeric(10,2),
    imc numeric(10,2),
    cintura_cm numeric(10,2),
    grasa_corporal_pct numeric(10,2),
    masa_magra_kg numeric(10,2),
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: ficha_mediciones_id_seq; Type: SEQUENCE; Schema: salud; Owner: -
--

CREATE SEQUENCE salud.ficha_mediciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ficha_mediciones_id_seq; Type: SEQUENCE OWNED BY; Schema: salud; Owner: -
--

ALTER SEQUENCE salud.ficha_mediciones_id_seq OWNED BY salud.ficha_mediciones.id;


--
-- Name: ficha_patologias; Type: TABLE; Schema: salud; Owner: -
--

CREATE TABLE salud.ficha_patologias (
    id bigint NOT NULL,
    ficha_tecnica_id bigint NOT NULL,
    patologia_id bigint NOT NULL,
    detalle text,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: ficha_patologias_id_seq; Type: SEQUENCE; Schema: salud; Owner: -
--

CREATE SEQUENCE salud.ficha_patologias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ficha_patologias_id_seq; Type: SEQUENCE OWNED BY; Schema: salud; Owner: -
--

ALTER SEQUENCE salud.ficha_patologias_id_seq OWNED BY salud.ficha_patologias.id;


--
-- Name: fichas_tecnicas; Type: TABLE; Schema: salud; Owner: -
--

CREATE TABLE salud.fichas_tecnicas (
    id bigint NOT NULL,
    persona_id bigint NOT NULL,
    fecha_ficha date DEFAULT CURRENT_DATE NOT NULL,
    actividad_fisica text,
    objetivo text,
    observaciones text,
    registrado_por bigint,
    sede_id bigint,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: fichas_tecnicas_id_seq; Type: SEQUENCE; Schema: salud; Owner: -
--

CREATE SEQUENCE salud.fichas_tecnicas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fichas_tecnicas_id_seq; Type: SEQUENCE OWNED BY; Schema: salud; Owner: -
--

ALTER SEQUENCE salud.fichas_tecnicas_id_seq OWNED BY salud.fichas_tecnicas.id;


--
-- Name: roles; Type: TABLE; Schema: seguridad; Owner: -
--

CREATE TABLE seguridad.roles (
    id bigint NOT NULL,
    gimnasio_id bigint,
    codigo character varying(50) NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: seguridad; Owner: -
--

CREATE SEQUENCE seguridad.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: seguridad; Owner: -
--

ALTER SEQUENCE seguridad.roles_id_seq OWNED BY seguridad.roles.id;


--
-- Name: usuario_roles; Type: TABLE; Schema: seguridad; Owner: -
--

CREATE TABLE seguridad.usuario_roles (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    rol_id bigint NOT NULL,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: usuario_roles_id_seq; Type: SEQUENCE; Schema: seguridad; Owner: -
--

CREATE SEQUENCE seguridad.usuario_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usuario_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: seguridad; Owner: -
--

ALTER SEQUENCE seguridad.usuario_roles_id_seq OWNED BY seguridad.usuario_roles.id;


--
-- Name: usuario_sedes; Type: TABLE; Schema: seguridad; Owner: -
--

CREATE TABLE seguridad.usuario_sedes (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    sede_id bigint NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE; Schema: seguridad; Owner: -
--

CREATE SEQUENCE seguridad.usuario_sedes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE OWNED BY; Schema: seguridad; Owner: -
--

ALTER SEQUENCE seguridad.usuario_sedes_id_seq OWNED BY seguridad.usuario_sedes.id;


--
-- Name: usuarios; Type: TABLE; Schema: seguridad; Owner: -
--

CREATE TABLE seguridad.usuarios (
    id bigint NOT NULL,
    gimnasio_id bigint,
    persona_id bigint,
    email character varying(150) NOT NULL,
    password_hash text NOT NULL,
    estado character varying(30) DEFAULT 'ACTIVO'::character varying NOT NULL,
    fecha_baja timestamp with time zone,
    foto_perfil_url text,
    created_id_user bigint,
    updated_id_user bigint,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    cedula character varying(30)
);


--
-- Name: usuarios_id_seq; Type: SEQUENCE; Schema: seguridad; Owner: -
--

CREATE SEQUENCE seguridad.usuarios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usuarios_id_seq; Type: SEQUENCE OWNED BY; Schema: seguridad; Owner: -
--

ALTER SEQUENCE seguridad.usuarios_id_seq OWNED BY seguridad.usuarios.id;


--
-- Name: membresia_precios_sede; Type: TABLE; Schema: socios; Owner: -
--

CREATE TABLE socios.membresia_precios_sede (
    id bigint NOT NULL,
    membresia_id bigint NOT NULL,
    sede_id bigint NOT NULL,
    precio numeric(12,2) NOT NULL,
    vigencia_inicio date DEFAULT CURRENT_DATE,
    vigencia_fin date,
    activa boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    CONSTRAINT membresia_precios_sede_precio_check CHECK ((precio >= (0)::numeric))
);


--
-- Name: membresia_precios_sede_id_seq; Type: SEQUENCE; Schema: socios; Owner: -
--

CREATE SEQUENCE socios.membresia_precios_sede_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: membresia_precios_sede_id_seq; Type: SEQUENCE OWNED BY; Schema: socios; Owner: -
--

ALTER SEQUENCE socios.membresia_precios_sede_id_seq OWNED BY socios.membresia_precios_sede.id;


--
-- Name: membresias; Type: TABLE; Schema: socios; Owner: -
--

CREATE TABLE socios.membresias (
    id bigint NOT NULL,
    nombre character varying(120) NOT NULL,
    descripcion text,
    duracion_dias integer NOT NULL,
    precio numeric(12,2) NOT NULL,
    activa boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: membresias_id_seq; Type: SEQUENCE; Schema: socios; Owner: -
--

CREATE SEQUENCE socios.membresias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: membresias_id_seq; Type: SEQUENCE OWNED BY; Schema: socios; Owner: -
--

ALTER SEQUENCE socios.membresias_id_seq OWNED BY socios.membresias.id;


--
-- Name: socio_membresias; Type: TABLE; Schema: socios; Owner: -
--

CREATE TABLE socios.socio_membresias (
    id bigint NOT NULL,
    socio_id bigint NOT NULL,
    membresia_id bigint NOT NULL,
    fecha_inicio date NOT NULL,
    fecha_fin date NOT NULL,
    estado_id bigint,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    cedula character varying(20),
    sede_id bigint,
    precio_aplicado numeric(12,2)
);


--
-- Name: socio_membresias_id_seq; Type: SEQUENCE; Schema: socios; Owner: -
--

CREATE SEQUENCE socios.socio_membresias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: socio_membresias_id_seq; Type: SEQUENCE OWNED BY; Schema: socios; Owner: -
--

ALTER SEQUENCE socios.socio_membresias_id_seq OWNED BY socios.socio_membresias.id;


--
-- Name: socios; Type: TABLE; Schema: socios; Owner: -
--

CREATE TABLE socios.socios (
    id bigint NOT NULL,
    persona_id bigint NOT NULL,
    codigo_socio character varying(30) NOT NULL,
    sede_id bigint,
    fecha_alta date DEFAULT CURRENT_DATE NOT NULL,
    estado_id bigint,
    observacion text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: socios_id_seq; Type: SEQUENCE; Schema: socios; Owner: -
--

CREATE SEQUENCE socios.socios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: socios_id_seq; Type: SEQUENCE OWNED BY; Schema: socios; Owner: -
--

ALTER SEQUENCE socios.socios_id_seq OWNED BY socios.socios.id;


--
-- Name: auth_menu_items; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.auth_menu_items (
    id bigint NOT NULL,
    gimnasio_id bigint NOT NULL,
    parent_id bigint,
    tipo character varying(20) DEFAULT 'ITEM'::character varying NOT NULL,
    titulo character varying(120) NOT NULL,
    icono character varying(80),
    ruta character varying(200),
    orden integer DEFAULT 0 NOT NULL,
    visible boolean DEFAULT true NOT NULL,
    permiso_requerido_id bigint,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT auth_menu_items_creado_en_not_null NOT NULL,
    updated_at timestamp without time zone DEFAULT now() CONSTRAINT auth_menu_items_actualizado_en_not_null NOT NULL
);


--
-- Name: auth_menu_items_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.auth_menu_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_menu_items_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.auth_menu_items_id_seq OWNED BY train_gimnasio.auth_menu_items.id;


--
-- Name: auth_permisos; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.auth_permisos (
    id bigint NOT NULL,
    gimnasio_id bigint NOT NULL,
    codigo character varying(120) NOT NULL,
    nombre character varying(140) NOT NULL,
    modulo character varying(80),
    descripcion text,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT auth_permisos_creado_en_not_null NOT NULL
);


--
-- Name: auth_permisos_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.auth_permisos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_permisos_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.auth_permisos_id_seq OWNED BY train_gimnasio.auth_permisos.id;


--
-- Name: auth_rol_permisos; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.auth_rol_permisos (
    id bigint NOT NULL,
    rol_id bigint NOT NULL,
    permiso_id bigint NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT auth_rol_permisos_creado_en_not_null NOT NULL
);


--
-- Name: auth_rol_permisos_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.auth_rol_permisos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_rol_permisos_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.auth_rol_permisos_id_seq OWNED BY train_gimnasio.auth_rol_permisos.id;


--
-- Name: auth_roles; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.auth_roles (
    id bigint NOT NULL,
    gimnasio_id bigint NOT NULL,
    codigo character varying(60) NOT NULL,
    nombre character varying(120) NOT NULL,
    descripcion text,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT auth_roles_creado_en_not_null NOT NULL,
    updated_at timestamp without time zone DEFAULT now() CONSTRAINT auth_roles_actualizado_en_not_null NOT NULL
);


--
-- Name: auth_roles_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.auth_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.auth_roles_id_seq OWNED BY train_gimnasio.auth_roles.id;


--
-- Name: auth_tokens_acceso; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.auth_tokens_acceso (
    id bigint NOT NULL,
    gimnasio_id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    nombre character varying(120) NOT NULL,
    token_hash character varying(255) NOT NULL,
    habilidades jsonb,
    ultimo_uso_en timestamp without time zone,
    expira_en timestamp without time zone,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT auth_tokens_acceso_creado_en_not_null NOT NULL,
    updated_at timestamp without time zone DEFAULT now() CONSTRAINT auth_tokens_acceso_actualizado_en_not_null NOT NULL
);


--
-- Name: auth_tokens_acceso_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.auth_tokens_acceso_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_tokens_acceso_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.auth_tokens_acceso_id_seq OWNED BY train_gimnasio.auth_tokens_acceso.id;


--
-- Name: auth_usuario_roles; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.auth_usuario_roles (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    rol_id bigint NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT auth_usuario_roles_creado_en_not_null NOT NULL
);


--
-- Name: auth_usuario_roles_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.auth_usuario_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_usuario_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.auth_usuario_roles_id_seq OWNED BY train_gimnasio.auth_usuario_roles.id;


--
-- Name: auth_usuarios; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.auth_usuarios (
    id bigint NOT NULL,
    gimnasio_id bigint NOT NULL,
    persona_id bigint,
    email character varying(160),
    password_hash text,
    estado character varying(20) DEFAULT 'ACTIVO'::character varying NOT NULL,
    fecha_baja timestamp with time zone,
    foto_perfil_url text,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT auth_usuarios_creado_en_not_null NOT NULL,
    updated_at timestamp without time zone DEFAULT now() CONSTRAINT auth_usuarios_actualizado_en_not_null NOT NULL,
    created_id_user bigint,
    updated_id_user bigint,
    cedula character varying
);


--
-- Name: auth_usuarios_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.auth_usuarios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_usuarios_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.auth_usuarios_id_seq OWNED BY train_gimnasio.auth_usuarios.id;


--
-- Name: cache; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: categoria_servicios; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.categoria_servicios (
    id bigint NOT NULL,
    nombre character varying(255) NOT NULL,
    descripcion text,
    estado_id bigint,
    user_id bigint,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: categoria_servicios_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.categoria_servicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: categoria_servicios_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.categoria_servicios_id_seq OWNED BY train_gimnasio.categoria_servicios.id;


--
-- Name: estados; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.estados (
    id bigint NOT NULL,
    gimnasio_id bigint NOT NULL,
    tipo character varying(40) NOT NULL,
    codigo character varying(80) NOT NULL,
    nombre character varying(120) NOT NULL,
    descripcion text,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: estados_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.estados_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: estados_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.estados_id_seq OWNED BY train_gimnasio.estados.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.failed_jobs_id_seq OWNED BY train_gimnasio.failed_jobs.id;


--
-- Name: gimnasios; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.gimnasios (
    id bigint NOT NULL,
    nombre character varying(120) NOT NULL,
    ruc character varying(30),
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT gimnasios_creado_en_not_null NOT NULL,
    updated_at timestamp without time zone DEFAULT now() CONSTRAINT gimnasios_actualizado_en_not_null NOT NULL
);


--
-- Name: gimnasios_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.gimnasios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: gimnasios_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.gimnasios_id_seq OWNED BY train_gimnasio.gimnasios.id;


--
-- Name: horarios_gym; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.horarios_gym (
    id bigint NOT NULL,
    sede_id bigint NOT NULL,
    tipo_servicio_id bigint CONSTRAINT horarios_gym_servicio_id_not_null NOT NULL,
    hora_apertura time without time zone NOT NULL,
    hora_cierre time without time zone NOT NULL,
    capacidad_maxima integer NOT NULL,
    tiempo_turno_min integer NOT NULL,
    tipo_usuario bigint NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_horas_validas CHECK ((hora_apertura < hora_cierre)),
    CONSTRAINT horarios_gym_capacidad_maxima_check CHECK ((capacidad_maxima > 0)),
    CONSTRAINT horarios_gym_tiempo_turno_min_check CHECK ((tiempo_turno_min > 0))
);


--
-- Name: horarios_gym_dias; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.horarios_gym_dias (
    horario_id bigint NOT NULL,
    dia_semana smallint NOT NULL,
    CONSTRAINT horarios_gym_dias_dia_semana_check CHECK (((dia_semana >= 1) AND (dia_semana <= 7)))
);


--
-- Name: horarios_gym_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.horarios_gym_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: horarios_gym_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.horarios_gym_id_seq OWNED BY train_gimnasio.horarios_gym.id;


--
-- Name: job_batches; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.jobs_id_seq OWNED BY train_gimnasio.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.migrations_id_seq OWNED BY train_gimnasio.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.personal_access_tokens_id_seq OWNED BY train_gimnasio.personal_access_tokens.id;


--
-- Name: personas; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.personas (
    id bigint NOT NULL,
    gimnasio_id bigint NOT NULL,
    cedula character varying(30),
    nombres character varying(120) NOT NULL,
    apellidos character varying(120) NOT NULL,
    fecha_nacimiento date,
    sexo character varying(20),
    nacionalidad character varying(80),
    provincia character varying(80),
    ciudad character varying(80),
    parroquia character varying(80),
    direccion text,
    celular character varying(30),
    email_contacto character varying(160),
    imagen_url text,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT personas_creado_en_not_null NOT NULL,
    updated_at timestamp without time zone DEFAULT now() CONSTRAINT personas_actualizado_en_not_null NOT NULL
);


--
-- Name: personas_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.personas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personas_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.personas_id_seq OWNED BY train_gimnasio.personas.id;


--
-- Name: reservas_gym; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.reservas_gym (
    id bigint NOT NULL,
    fecha date NOT NULL,
    hora time without time zone NOT NULL,
    horario_id bigint NOT NULL,
    sede_id bigint NOT NULL,
    tipo_servicio_id bigint CONSTRAINT reservas_gym_servicio_id_not_null NOT NULL,
    user_id bigint,
    cedula text,
    estado_id bigint NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT chk_reserva_persona CHECK (((user_id IS NOT NULL) OR ((cedula IS NOT NULL) AND (length(TRIM(BOTH FROM cedula)) > 0))))
);


--
-- Name: reservas_gym_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.reservas_gym_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: reservas_gym_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.reservas_gym_id_seq OWNED BY train_gimnasio.reservas_gym.id;


--
-- Name: sedes; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.sedes (
    id bigint NOT NULL,
    gimnasio_id bigint NOT NULL,
    nombre character varying(120) NOT NULL,
    direccion character varying(250),
    telefono character varying(30),
    activa boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT sedes_creado_en_not_null NOT NULL,
    updated_at timestamp without time zone DEFAULT now() CONSTRAINT sedes_actualizado_en_not_null NOT NULL
);


--
-- Name: sedes_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.sedes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sedes_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.sedes_id_seq OWNED BY train_gimnasio.sedes.id;


--
-- Name: sessions; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: tipos_servicios; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.tipos_servicios (
    id bigint NOT NULL,
    nombre text NOT NULL,
    descripcion text,
    breve_desc character varying(400),
    categoria_id bigint NOT NULL,
    estado_id smallint DEFAULT 1 NOT NULL,
    user_id bigint NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: tipos_servicios_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.tipos_servicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tipos_servicios_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.tipos_servicios_id_seq OWNED BY train_gimnasio.tipos_servicios.id;


--
-- Name: users; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.users_id_seq OWNED BY train_gimnasio.users.id;


--
-- Name: usuario_sedes; Type: TABLE; Schema: train_gimnasio; Owner: -
--

CREATE TABLE train_gimnasio.usuario_sedes (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    sede_id bigint NOT NULL,
    es_principal boolean DEFAULT false NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT usuario_sedes_creado_en_not_null NOT NULL
);


--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: -
--

CREATE SEQUENCE train_gimnasio.usuario_sedes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: -
--

ALTER SEQUENCE train_gimnasio.usuario_sedes_id_seq OWNED BY train_gimnasio.usuario_sedes.id;


--
-- Name: devolucion_detalles; Type: TABLE; Schema: ventas; Owner: -
--

CREATE TABLE ventas.devolucion_detalles (
    id bigint NOT NULL,
    devolucion_id bigint NOT NULL,
    venta_detalle_id bigint,
    producto_id bigint,
    membresia_id bigint,
    tipo_detalle character varying(30) DEFAULT 'PRODUCTO'::character varying NOT NULL,
    descripcion text,
    cantidad numeric(12,2) NOT NULL,
    precio_unitario numeric(12,2) DEFAULT 0 NOT NULL,
    subtotal numeric(12,2) DEFAULT 0 NOT NULL,
    reintegra_stock boolean DEFAULT true NOT NULL,
    movimiento_inventario_id bigint,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: devolucion_detalles_id_seq; Type: SEQUENCE; Schema: ventas; Owner: -
--

CREATE SEQUENCE ventas.devolucion_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: devolucion_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: -
--

ALTER SEQUENCE ventas.devolucion_detalles_id_seq OWNED BY ventas.devolucion_detalles.id;


--
-- Name: devoluciones; Type: TABLE; Schema: ventas; Owner: -
--

CREATE TABLE ventas.devoluciones (
    id bigint NOT NULL,
    venta_id bigint NOT NULL,
    tipo character varying(30) NOT NULL,
    motivo character varying(120) NOT NULL,
    observacion text,
    reintegra_stock boolean DEFAULT true NOT NULL,
    monto_total numeric(12,2) DEFAULT 0 NOT NULL,
    estado character varying(30) DEFAULT 'APLICADA'::character varying NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_by bigint,
    updated_by bigint,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: devoluciones_id_seq; Type: SEQUENCE; Schema: ventas; Owner: -
--

CREATE SEQUENCE ventas.devoluciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: devoluciones_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: -
--

ALTER SEQUENCE ventas.devoluciones_id_seq OWNED BY ventas.devoluciones.id;


--
-- Name: venta_detalles; Type: TABLE; Schema: ventas; Owner: -
--

CREATE TABLE ventas.venta_detalles (
    id bigint NOT NULL,
    venta_id bigint NOT NULL,
    producto_id bigint,
    cantidad numeric(12,2) DEFAULT 1 NOT NULL,
    precio_unitario numeric(10,2) NOT NULL,
    subtotal numeric(10,2) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    membresia_id bigint,
    tipo_detalle character varying(30) DEFAULT 'PRODUCTO'::character varying NOT NULL,
    descripcion text
);


--
-- Name: venta_detalles_id_seq; Type: SEQUENCE; Schema: ventas; Owner: -
--

CREATE SEQUENCE ventas.venta_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: venta_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: -
--

ALTER SEQUENCE ventas.venta_detalles_id_seq OWNED BY ventas.venta_detalles.id;


--
-- Name: venta_pagos; Type: TABLE; Schema: ventas; Owner: -
--

CREATE TABLE ventas.venta_pagos (
    id bigint NOT NULL,
    venta_id bigint NOT NULL,
    forma_pago character varying(30) NOT NULL,
    monto numeric(12,2) NOT NULL,
    referencia_pago character varying(120),
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: venta_pagos_id_seq; Type: SEQUENCE; Schema: ventas; Owner: -
--

CREATE SEQUENCE ventas.venta_pagos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: venta_pagos_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: -
--

ALTER SEQUENCE ventas.venta_pagos_id_seq OWNED BY ventas.venta_pagos.id;


--
-- Name: ventas; Type: TABLE; Schema: ventas; Owner: -
--

CREATE TABLE ventas.ventas (
    id bigint NOT NULL,
    sede_id bigint NOT NULL,
    cliente_id bigint,
    vendedor_id bigint NOT NULL,
    total numeric(10,2) DEFAULT 0.00 NOT NULL,
    estado smallint DEFAULT 1 NOT NULL,
    fecha timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_by bigint NOT NULL,
    updated_by bigint,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    persona_id bigint,
    vendedor_usuario_id bigint,
    referencia character varying(100),
    forma_pago character varying(30),
    observacion text,
    subtotal numeric(12,2) DEFAULT 0,
    iva numeric(12,2) DEFAULT 0,
    tipo_venta character varying(30) DEFAULT 'CONSUMO'::character varying NOT NULL,
    estado_pago character varying(30) DEFAULT 'PAGADO'::character varying NOT NULL,
    saldo_pendiente numeric(12,2) DEFAULT 0 NOT NULL,
    fecha_consumo date DEFAULT CURRENT_DATE NOT NULL,
    membresia_id bigint,
    metadata jsonb,
    estado_devolucion character varying(30) DEFAULT 'SIN_DEVOLUCION'::character varying NOT NULL,
    monto_devuelto numeric(12,2) DEFAULT 0 NOT NULL,
    anulada_at timestamp without time zone,
    anulada_by bigint
);


--
-- Name: ventas_id_seq; Type: SEQUENCE; Schema: ventas; Owner: -
--

CREATE SEQUENCE ventas.ventas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ventas_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: -
--

ALTER SEQUENCE ventas.ventas_id_seq OWNED BY ventas.ventas.id;


--
-- Name: aud_cambios id; Type: DEFAULT; Schema: auditoria; Owner: -
--

ALTER TABLE ONLY auditoria.aud_cambios ALTER COLUMN id SET DEFAULT nextval('auditoria.aud_cambios_id_seq'::regclass);


--
-- Name: estados id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.estados ALTER COLUMN id SET DEFAULT nextval('core.estados_id_seq'::regclass);


--
-- Name: persona_tipo_detalle id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.persona_tipo_detalle ALTER COLUMN id SET DEFAULT nextval('core.persona_tipo_detalle_id_seq'::regclass);


--
-- Name: persona_tipos id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.persona_tipos ALTER COLUMN id SET DEFAULT nextval('core.persona_tipos_id_seq'::regclass);


--
-- Name: personas id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.personas ALTER COLUMN id SET DEFAULT nextval('core.personas_id_seq'::regclass);


--
-- Name: sedes id; Type: DEFAULT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.sedes ALTER COLUMN id SET DEFAULT nextval('core.sedes_id_seq'::regclass);


--
-- Name: ejecuciones id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.ejecuciones ALTER COLUMN id SET DEFAULT nextval('entrenamiento.ejecuciones_id_seq'::regclass);


--
-- Name: ejercicios id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.ejercicios ALTER COLUMN id SET DEFAULT nextval('entrenamiento.ejercicios_id_seq'::regclass);


--
-- Name: evaluaciones id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.evaluaciones ALTER COLUMN id SET DEFAULT nextval('entrenamiento.evaluaciones_id_seq'::regclass);


--
-- Name: plan_asignaciones id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_asignaciones ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_asignaciones_id_seq'::regclass);


--
-- Name: plan_bloques id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_bloques ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_bloques_id_seq'::regclass);


--
-- Name: plan_dias id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_dias ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_dias_id_seq'::regclass);


--
-- Name: plan_ejecuciones id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_ejecuciones_id_seq'::regclass);


--
-- Name: plan_ejercicio_series id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_series ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_ejercicio_series_id_seq'::regclass);


--
-- Name: plan_ejercicio_transferencias id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_transferencias ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_ejercicio_transferencias_id_seq'::regclass);


--
-- Name: plan_ejercicios id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_ejercicios_id_seq'::regclass);


--
-- Name: plan_transferencia_series id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_transferencia_series ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_transferencia_series_id_seq'::regclass);


--
-- Name: planes id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.planes ALTER COLUMN id SET DEFAULT nextval('entrenamiento.planes_id_seq'::regclass);


--
-- Name: plantilla_semana_bloques id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_bloques ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_bloques_id_seq'::regclass);


--
-- Name: plantilla_semana_dias id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_dias ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_dias_id_seq'::regclass);


--
-- Name: plantilla_semana_ejercicio_series id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_series ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_ejercicio_series_id_seq'::regclass);


--
-- Name: plantilla_semana_ejercicio_transferencias id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_transferencias ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_ejercicio_transferencias_id_seq'::regclass);


--
-- Name: plantilla_semana_ejercicios id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicios ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_ejercicios_id_seq'::regclass);


--
-- Name: plantilla_semana_transferencia_series id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_transferencia_series ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_transferencia_series_id_seq'::regclass);


--
-- Name: plantillas_semanales id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantillas_semanales ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantillas_semanales_id_seq'::regclass);


--
-- Name: rm_registros id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rm_registros ALTER COLUMN id SET DEFAULT nextval('entrenamiento.rm_registros_id_seq'::regclass);


--
-- Name: rutina_plantilla_detalles id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles ALTER COLUMN id SET DEFAULT nextval('entrenamiento.rutina_plantilla_detalles_id_seq'::regclass);


--
-- Name: rutina_plantillas id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutina_plantillas ALTER COLUMN id SET DEFAULT nextval('entrenamiento.rutina_plantillas_id_seq'::regclass);


--
-- Name: rutinas id; Type: DEFAULT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutinas ALTER COLUMN id SET DEFAULT nextval('entrenamiento.rutinas_id_seq'::regclass);


--
-- Name: proveedores prov_id; Type: DEFAULT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.proveedores ALTER COLUMN prov_id SET DEFAULT nextval('inventario.proveedores_prov_id_seq'::regclass);


--
-- Name: catalogos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogos ALTER COLUMN id SET DEFAULT nextval('public.catalogos_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: catalogo_patologias id; Type: DEFAULT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.catalogo_patologias ALTER COLUMN id SET DEFAULT nextval('salud.catalogo_patologias_id_seq'::regclass);


--
-- Name: ficha_mediciones id; Type: DEFAULT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.ficha_mediciones ALTER COLUMN id SET DEFAULT nextval('salud.ficha_mediciones_id_seq'::regclass);


--
-- Name: ficha_patologias id; Type: DEFAULT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.ficha_patologias ALTER COLUMN id SET DEFAULT nextval('salud.ficha_patologias_id_seq'::regclass);


--
-- Name: fichas_tecnicas id; Type: DEFAULT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.fichas_tecnicas ALTER COLUMN id SET DEFAULT nextval('salud.fichas_tecnicas_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.roles ALTER COLUMN id SET DEFAULT nextval('seguridad.roles_id_seq'::regclass);


--
-- Name: usuario_roles id; Type: DEFAULT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_roles ALTER COLUMN id SET DEFAULT nextval('seguridad.usuario_roles_id_seq'::regclass);


--
-- Name: usuario_sedes id; Type: DEFAULT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_sedes ALTER COLUMN id SET DEFAULT nextval('seguridad.usuario_sedes_id_seq'::regclass);


--
-- Name: usuarios id; Type: DEFAULT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuarios ALTER COLUMN id SET DEFAULT nextval('seguridad.usuarios_id_seq'::regclass);


--
-- Name: membresia_precios_sede id; Type: DEFAULT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.membresia_precios_sede ALTER COLUMN id SET DEFAULT nextval('socios.membresia_precios_sede_id_seq'::regclass);


--
-- Name: membresias id; Type: DEFAULT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.membresias ALTER COLUMN id SET DEFAULT nextval('socios.membresias_id_seq'::regclass);


--
-- Name: socio_membresias id; Type: DEFAULT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socio_membresias ALTER COLUMN id SET DEFAULT nextval('socios.socio_membresias_id_seq'::regclass);


--
-- Name: socios id; Type: DEFAULT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socios ALTER COLUMN id SET DEFAULT nextval('socios.socios_id_seq'::regclass);


--
-- Name: auth_menu_items id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_menu_items_id_seq'::regclass);


--
-- Name: auth_permisos id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_permisos ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_permisos_id_seq'::regclass);


--
-- Name: auth_rol_permisos id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_rol_permisos_id_seq'::regclass);


--
-- Name: auth_roles id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_roles ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_roles_id_seq'::regclass);


--
-- Name: auth_tokens_acceso id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_tokens_acceso_id_seq'::regclass);


--
-- Name: auth_usuario_roles id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_usuario_roles_id_seq'::regclass);


--
-- Name: auth_usuarios id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_usuarios_id_seq'::regclass);


--
-- Name: categoria_servicios id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.categoria_servicios ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.categoria_servicios_id_seq'::regclass);


--
-- Name: estados id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.estados ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.estados_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.failed_jobs ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.failed_jobs_id_seq'::regclass);


--
-- Name: gimnasios id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.gimnasios ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.gimnasios_id_seq'::regclass);


--
-- Name: horarios_gym id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.horarios_gym ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.horarios_gym_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.jobs ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.migrations ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.personal_access_tokens_id_seq'::regclass);


--
-- Name: personas id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.personas ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.personas_id_seq'::regclass);


--
-- Name: reservas_gym id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.reservas_gym ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.reservas_gym_id_seq'::regclass);


--
-- Name: sedes id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.sedes ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.sedes_id_seq'::regclass);


--
-- Name: tipos_servicios id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.tipos_servicios ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.tipos_servicios_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.users ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.users_id_seq'::regclass);


--
-- Name: usuario_sedes id; Type: DEFAULT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.usuario_sedes_id_seq'::regclass);


--
-- Name: devolucion_detalles id; Type: DEFAULT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devolucion_detalles ALTER COLUMN id SET DEFAULT nextval('ventas.devolucion_detalles_id_seq'::regclass);


--
-- Name: devoluciones id; Type: DEFAULT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devoluciones ALTER COLUMN id SET DEFAULT nextval('ventas.devoluciones_id_seq'::regclass);


--
-- Name: venta_detalles id; Type: DEFAULT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.venta_detalles ALTER COLUMN id SET DEFAULT nextval('ventas.venta_detalles_id_seq'::regclass);


--
-- Name: venta_pagos id; Type: DEFAULT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.venta_pagos ALTER COLUMN id SET DEFAULT nextval('ventas.venta_pagos_id_seq'::regclass);


--
-- Name: ventas id; Type: DEFAULT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.ventas ALTER COLUMN id SET DEFAULT nextval('ventas.ventas_id_seq'::regclass);


--
-- Data for Name: aud_cambios; Type: TABLE DATA; Schema: auditoria; Owner: -
--

COPY auditoria.aud_cambios (id, gimnasio_id, sede_id, actor_usuario_id, actor_rol_id, operacion, esquema, tabla, registro_id, datos_antes, datos_despues, campos_cambiados, request_id, ip, user_agent, created_at, registro_pk, ip_publica, ip_forwarded_for, proxy_headers, tipo_dispositivo, sistema_operativo, navegador, equipo_nombre, equipo_usuario, ip_bd, actor_persona_id, modulo, accion) FROM stdin;
1	\N	\N	\N	\N	I	gym	gimnasios	1	\N	{"id": 1, "ruc": null, "activo": true, "nombre": "Revive", "creado_en": "2025-12-26T01:38:39.808047", "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
2	\N	\N	\N	\N	I	gym	gimnasios	2	\N	{"id": 2, "ruc": null, "activo": true, "nombre": "TrainRevive Demo", "creado_en": "2025-12-26T01:38:39.808047", "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
3	1	\N	\N	\N	I	gym	sedes	1	\N	{"id": 1, "activa": true, "nombre": "Revive Home", "telefono": "0999999999", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Dirección Home", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
4	1	\N	\N	\N	I	gym	sedes	2	\N	{"id": 2, "activa": true, "nombre": "Revive Xpadel", "telefono": "0988888888", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Dirección Xpadel", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
5	1	\N	\N	\N	I	gym	sedes	3	\N	{"id": 3, "activa": true, "nombre": "Revive Centro", "telefono": "0977777777", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Dirección Centro", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
6	1	\N	\N	\N	I	gym	personas	1	\N	{"id": 1, "sexo": "F", "cedula": "1300000001", "ciudad": "Manta", "celular": "098000222", "nombres": "María", "apellidos": "Admin", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Tarqui", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "admin@revive.com", "fecha_nacimiento": "1995-01-15"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
7	1	\N	\N	\N	I	gym	personas	2	\N	{"id": 2, "sexo": "M", "cedula": "1312345678", "ciudad": "Manta", "celular": "099000111", "nombres": "Juan", "apellidos": "Pérez", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Los Esteros", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "juan@revive.com", "fecha_nacimiento": "2000-05-10"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
8	1	\N	\N	\N	I	gym	personas	3	\N	{"id": 3, "sexo": "M", "cedula": "1300000002", "ciudad": "Manta", "celular": "097000333", "nombres": "Carlos", "apellidos": "Cajero", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Centro", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "cajero@revive.com", "fecha_nacimiento": "1998-08-20"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
9	1	\N	\N	\N	I	gym	personas	4	\N	{"id": 4, "sexo": "F", "cedula": "1300000003", "ciudad": "Manta", "celular": "096000444", "nombres": "Ana", "apellidos": "Trainer", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Uleam", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "trainer@revive.com", "fecha_nacimiento": "1997-03-12"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
10	1	\N	\N	\N	I	gym	personas	5	\N	{"id": 5, "sexo": "M", "cedula": "1300000004", "ciudad": "Manta", "celular": "095000555", "nombres": "Luis", "apellidos": "Cliente", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Xpadel", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "luis@revive.com", "fecha_nacimiento": "2002-11-02"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
11	1	\N	\N	\N	I	gym	auth_usuarios	1	\N	{"id": 1, "email": "admin@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 1, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_ADMIN_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
12	1	\N	\N	\N	I	gym	auth_usuarios	2	\N	{"id": 2, "email": "cajero@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 3, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_CAJERO_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
13	1	\N	\N	\N	I	gym	auth_usuarios	3	\N	{"id": 3, "email": "trainer@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 4, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_TRAINER_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
14	1	\N	\N	\N	I	gym	auth_usuarios	4	\N	{"id": 4, "email": "luis@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 5, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_CLIENTE_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
15	1	\N	\N	\N	I	gym	auth_usuarios	5	\N	{"id": 5, "email": "juan@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 2, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_CLIENTE_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
16	\N	1	\N	\N	I	gym	usuario_sedes	1	\N	{"id": 1, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": true}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
17	\N	1	\N	\N	I	gym	usuario_sedes	2	\N	{"id": 2, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 2, "es_principal": true}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
18	\N	1	\N	\N	I	gym	usuario_sedes	3	\N	{"id": 3, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 5, "es_principal": true}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
19	\N	1	\N	\N	I	gym	usuario_sedes	4	\N	{"id": 4, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 4, "es_principal": true}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
401	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 7}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
20	\N	2	\N	\N	I	gym	usuario_sedes	5	\N	{"id": 5, "activo": true, "sede_id": 2, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 3, "es_principal": true}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
21	\N	3	\N	\N	I	gym	usuario_sedes	6	\N	{"id": 6, "activo": true, "sede_id": 3, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": false}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
22	\N	2	\N	\N	I	gym	usuario_sedes	8	\N	{"id": 8, "activo": true, "sede_id": 2, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": false}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
23	1	\N	\N	\N	I	gym	auth_roles	1	\N	{"id": 1, "activo": true, "codigo": "ADMIN", "nombre": "Administrador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso total", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
24	1	\N	\N	\N	I	gym	auth_roles	1	\N	{"id": 1, "activo": true, "codigo": "ADMIN", "nombre": "Administrador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso total", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
25	1	\N	\N	\N	I	gym	auth_roles	2	\N	{"id": 2, "activo": true, "codigo": "CAJERO", "nombre": "Cajero", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ventas y pagos", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
26	1	\N	\N	\N	I	gym	auth_roles	2	\N	{"id": 2, "activo": true, "codigo": "CAJERO", "nombre": "Cajero", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ventas y pagos", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
27	1	\N	\N	\N	I	gym	auth_roles	3	\N	{"id": 3, "activo": true, "codigo": "ENTRENADOR", "nombre": "Entrenador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Gestión de clientes y evaluaciones", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
28	1	\N	\N	\N	I	gym	auth_roles	3	\N	{"id": 3, "activo": true, "codigo": "ENTRENADOR", "nombre": "Entrenador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Gestión de clientes y evaluaciones", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
29	1	\N	\N	\N	I	gym	auth_roles	4	\N	{"id": 4, "activo": true, "codigo": "CLIENTE", "nombre": "Cliente", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso a su perfil", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
30	1	\N	\N	\N	I	gym	auth_roles	4	\N	{"id": 4, "activo": true, "codigo": "CLIENTE", "nombre": "Cliente", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso a su perfil", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
31	1	\N	\N	\N	I	gym	auth_permisos	1	\N	{"id": 1, "activo": true, "codigo": "USUARIOS_VER", "modulo": "USUARIOS", "nombre": "Ver usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Lista y detalles", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
32	1	\N	\N	\N	I	gym	auth_permisos	1	\N	{"id": 1, "activo": true, "codigo": "USUARIOS_VER", "modulo": "USUARIOS", "nombre": "Ver usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Lista y detalles", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
33	1	\N	\N	\N	I	gym	auth_permisos	2	\N	{"id": 2, "activo": true, "codigo": "USUARIOS_CREAR", "modulo": "USUARIOS", "nombre": "Crear usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Crear nuevos usuarios", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
34	1	\N	\N	\N	I	gym	auth_permisos	2	\N	{"id": 2, "activo": true, "codigo": "USUARIOS_CREAR", "modulo": "USUARIOS", "nombre": "Crear usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Crear nuevos usuarios", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
35	1	\N	\N	\N	I	gym	auth_permisos	3	\N	{"id": 3, "activo": true, "codigo": "USUARIOS_EDITAR", "modulo": "USUARIOS", "nombre": "Editar usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Editar datos de usuario", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
36	1	\N	\N	\N	I	gym	auth_permisos	3	\N	{"id": 3, "activo": true, "codigo": "USUARIOS_EDITAR", "modulo": "USUARIOS", "nombre": "Editar usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Editar datos de usuario", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
37	1	\N	\N	\N	I	gym	auth_permisos	4	\N	{"id": 4, "activo": true, "codigo": "ROLES_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar roles", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD roles", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
38	1	\N	\N	\N	I	gym	auth_permisos	4	\N	{"id": 4, "activo": true, "codigo": "ROLES_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar roles", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD roles", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
39	1	\N	\N	\N	I	gym	auth_permisos	5	\N	{"id": 5, "activo": true, "codigo": "PERMISOS_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar permisos", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD permisos", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
40	1	\N	\N	\N	I	gym	auth_permisos	5	\N	{"id": 5, "activo": true, "codigo": "PERMISOS_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar permisos", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD permisos", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
41	1	\N	\N	\N	I	gym	auth_permisos	6	\N	{"id": 6, "activo": true, "codigo": "MENU_ADMIN", "modulo": "CONFIG", "nombre": "Administrar menú", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD menú", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
42	1	\N	\N	\N	I	gym	auth_permisos	6	\N	{"id": 6, "activo": true, "codigo": "MENU_ADMIN", "modulo": "CONFIG", "nombre": "Administrar menú", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD menú", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
308	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	39	\N	{"id": 39, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 21}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
43	1	\N	\N	\N	I	gym	auth_permisos	7	\N	{"id": 7, "activo": true, "codigo": "AUDITORIA_VER", "modulo": "CONFIG", "nombre": "Ver auditoría", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Consultar auditoría", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
44	1	\N	\N	\N	I	gym	auth_permisos	7	\N	{"id": 7, "activo": true, "codigo": "AUDITORIA_VER", "modulo": "CONFIG", "nombre": "Ver auditoría", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Consultar auditoría", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
45	1	\N	\N	\N	I	gym	auth_permisos	8	\N	{"id": 8, "activo": true, "codigo": "MEMBRESIAS_VER", "modulo": "MEMBRESIAS", "nombre": "Ver membresías", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar membresías", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
46	1	\N	\N	\N	I	gym	auth_permisos	8	\N	{"id": 8, "activo": true, "codigo": "MEMBRESIAS_VER", "modulo": "MEMBRESIAS", "nombre": "Ver membresías", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar membresías", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
47	1	\N	\N	\N	I	gym	auth_permisos	9	\N	{"id": 9, "activo": true, "codigo": "INVENTARIO_VER", "modulo": "INVENTARIO", "nombre": "Ver inventario", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ver stock", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
48	1	\N	\N	\N	I	gym	auth_permisos	9	\N	{"id": 9, "activo": true, "codigo": "INVENTARIO_VER", "modulo": "INVENTARIO", "nombre": "Ver inventario", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ver stock", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
49	1	\N	\N	\N	I	gym	auth_permisos	10	\N	{"id": 10, "activo": true, "codigo": "FACTURAS_VER", "modulo": "FACTURACION", "nombre": "Ver facturas", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar facturas", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
50	1	\N	\N	\N	I	gym	auth_permisos	10	\N	{"id": 10, "activo": true, "codigo": "FACTURAS_VER", "modulo": "FACTURACION", "nombre": "Ver facturas", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar facturas", "gimnasio_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
51	\N	\N	\N	\N	I	gym	auth_rol_permisos	1	\N	{"id": 1, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 7}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
52	\N	\N	\N	\N	I	gym	auth_rol_permisos	2	\N	{"id": 2, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 10}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
53	\N	\N	\N	\N	I	gym	auth_rol_permisos	3	\N	{"id": 3, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 9}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
54	\N	\N	\N	\N	I	gym	auth_rol_permisos	4	\N	{"id": 4, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 8}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
55	\N	\N	\N	\N	I	gym	auth_rol_permisos	5	\N	{"id": 5, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 6}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
56	\N	\N	\N	\N	I	gym	auth_rol_permisos	6	\N	{"id": 6, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 5}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
57	\N	\N	\N	\N	I	gym	auth_rol_permisos	7	\N	{"id": 7, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 4}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
58	\N	\N	\N	\N	I	gym	auth_rol_permisos	8	\N	{"id": 8, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 2}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
59	\N	\N	\N	\N	I	gym	auth_rol_permisos	9	\N	{"id": 9, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 3}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
60	\N	\N	\N	\N	I	gym	auth_rol_permisos	10	\N	{"id": 10, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
61	\N	\N	\N	\N	I	gym	auth_rol_permisos	11	\N	{"id": 11, "rol_id": 2, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 10}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
62	\N	\N	\N	\N	I	gym	auth_rol_permisos	12	\N	{"id": 12, "rol_id": 2, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 9}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
63	\N	\N	\N	\N	I	gym	auth_rol_permisos	13	\N	{"id": 13, "rol_id": 3, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 8}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
64	\N	\N	\N	\N	I	gym	auth_rol_permisos	14	\N	{"id": 14, "rol_id": 3, "creado_en": "2025-12-26T01:38:39.808047", "permiso_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
65	\N	\N	\N	\N	I	gym	auth_usuario_roles	1	\N	{"id": 1, "rol_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
66	\N	\N	\N	\N	I	gym	auth_usuario_roles	2	\N	{"id": 2, "rol_id": 2, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 2}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
67	\N	\N	\N	\N	I	gym	auth_usuario_roles	3	\N	{"id": 3, "rol_id": 4, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 5}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
68	\N	\N	\N	\N	I	gym	auth_usuario_roles	4	\N	{"id": 4, "rol_id": 4, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 4}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
69	\N	\N	\N	\N	I	gym	auth_usuario_roles	5	\N	{"id": 5, "rol_id": 3, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 3}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
70	1	\N	\N	\N	I	gym	auth_menu_items	1	\N	{"id": 1, "ruta": null, "tipo": "GRUPO", "icono": "settings", "orden": 1, "titulo": "Administración", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": null}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
71	1	\N	\N	\N	I	gym	auth_menu_items	1	\N	{"id": 1, "ruta": null, "tipo": "GRUPO", "icono": "settings", "orden": 1, "titulo": "Administración", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": null}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
72	1	\N	\N	\N	I	gym	auth_menu_items	2	\N	{"id": 2, "ruta": null, "tipo": "GRUPO", "icono": "grid", "orden": 2, "titulo": "Operación", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": null}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
73	1	\N	\N	\N	I	gym	auth_menu_items	2	\N	{"id": 2, "ruta": null, "tipo": "GRUPO", "icono": "grid", "orden": 2, "titulo": "Operación", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": null}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
74	1	\N	\N	\N	I	gym	auth_menu_items	3	\N	{"id": 3, "ruta": "/admin/usuarios", "tipo": "ITEM", "icono": "users", "orden": 1, "titulo": "Usuarios", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
75	1	\N	\N	\N	I	gym	auth_menu_items	3	\N	{"id": 3, "ruta": "/admin/usuarios", "tipo": "ITEM", "icono": "users", "orden": 1, "titulo": "Usuarios", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 1}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
76	1	\N	\N	\N	I	gym	auth_menu_items	4	\N	{"id": 4, "ruta": "/admin/roles", "tipo": "ITEM", "icono": "shield", "orden": 2, "titulo": "Roles", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 4}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
77	1	\N	\N	\N	I	gym	auth_menu_items	4	\N	{"id": 4, "ruta": "/admin/roles", "tipo": "ITEM", "icono": "shield", "orden": 2, "titulo": "Roles", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 4}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
78	1	\N	\N	\N	I	gym	auth_menu_items	5	\N	{"id": 5, "ruta": "/admin/permisos", "tipo": "ITEM", "icono": "key", "orden": 3, "titulo": "Permisos", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 5}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
79	1	\N	\N	\N	I	gym	auth_menu_items	5	\N	{"id": 5, "ruta": "/admin/permisos", "tipo": "ITEM", "icono": "key", "orden": 3, "titulo": "Permisos", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 5}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
80	1	\N	\N	\N	I	gym	auth_menu_items	6	\N	{"id": 6, "ruta": "/admin/menu", "tipo": "ITEM", "icono": "menu", "orden": 4, "titulo": "Menú", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 6}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
81	1	\N	\N	\N	I	gym	auth_menu_items	6	\N	{"id": 6, "ruta": "/admin/menu", "tipo": "ITEM", "icono": "menu", "orden": 4, "titulo": "Menú", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 6}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
82	1	\N	\N	\N	I	gym	auth_menu_items	7	\N	{"id": 7, "ruta": "/admin/auditoria", "tipo": "ITEM", "icono": "file-search", "orden": 5, "titulo": "Auditoría", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 7}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
83	1	\N	\N	\N	I	gym	auth_menu_items	7	\N	{"id": 7, "ruta": "/admin/auditoria", "tipo": "ITEM", "icono": "file-search", "orden": 5, "titulo": "Auditoría", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 7}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
84	1	\N	\N	\N	I	gym	auth_menu_items	8	\N	{"id": 8, "ruta": "/operacion/membresias", "tipo": "ITEM", "icono": "id-card", "orden": 1, "titulo": "Membresías", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 8}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
85	1	\N	\N	\N	I	gym	auth_menu_items	8	\N	{"id": 8, "ruta": "/operacion/membresias", "tipo": "ITEM", "icono": "id-card", "orden": 1, "titulo": "Membresías", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 8}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
86	1	\N	\N	\N	I	gym	auth_menu_items	9	\N	{"id": 9, "ruta": "/operacion/inventario", "tipo": "ITEM", "icono": "boxes", "orden": 2, "titulo": "Inventario", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 9}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
87	1	\N	\N	\N	I	gym	auth_menu_items	9	\N	{"id": 9, "ruta": "/operacion/inventario", "tipo": "ITEM", "icono": "boxes", "orden": 2, "titulo": "Inventario", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 9}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
88	1	\N	\N	\N	I	gym	auth_menu_items	10	\N	{"id": 10, "ruta": "/operacion/facturas", "tipo": "ITEM", "icono": "receipt", "orden": 3, "titulo": "Facturación", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 10}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
89	1	\N	\N	\N	I	gym	auth_menu_items	10	\N	{"id": 10, "ruta": "/operacion/facturas", "tipo": "ITEM", "icono": "receipt", "orden": 3, "titulo": "Facturación", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 10}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
90	1	\N	\N	\N	I	gym	auth_tokens_acceso	1	\N	{"id": 1, "nombre": "Web Admin", "creado_en": "2025-12-26T01:38:39.808047", "expira_en": "2026-01-25T01:38:39.808047", "token_hash": "HASH_TOKEN_DEMO_admin_revive.com", "usuario_id": 1, "gimnasio_id": 1, "habilidades": {"scope": ["admin"]}, "ultimo_uso_en": null, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
91	1	\N	\N	\N	I	gym	auth_tokens_acceso	2	\N	{"id": 2, "nombre": "App", "creado_en": "2025-12-26T01:38:39.808047", "expira_en": "2026-01-25T01:38:39.808047", "token_hash": "HASH_TOKEN_DEMO_cajero_revive.com", "usuario_id": 2, "gimnasio_id": 1, "habilidades": {"scope": ["user"]}, "ultimo_uso_en": null, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
92	1	\N	\N	\N	I	gym	auth_tokens_acceso	3	\N	{"id": 3, "nombre": "App", "creado_en": "2025-12-26T01:38:39.808047", "expira_en": "2026-01-25T01:38:39.808047", "token_hash": "HASH_TOKEN_DEMO_juan_revive.com", "usuario_id": 5, "gimnasio_id": 1, "habilidades": {"scope": ["user"]}, "ultimo_uso_en": null, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
93	1	\N	\N	\N	I	gym	auth_tokens_acceso	4	\N	{"id": 4, "nombre": "App", "creado_en": "2025-12-26T01:38:39.808047", "expira_en": "2026-01-25T01:38:39.808047", "token_hash": "HASH_TOKEN_DEMO_luis_revive.com", "usuario_id": 4, "gimnasio_id": 1, "habilidades": {"scope": ["user"]}, "ultimo_uso_en": null, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
94	1	\N	\N	\N	I	gym	auth_tokens_acceso	5	\N	{"id": 5, "nombre": "App", "creado_en": "2025-12-26T01:38:39.808047", "expira_en": "2026-01-25T01:38:39.808047", "token_hash": "HASH_TOKEN_DEMO_trainer_revive.com", "usuario_id": 3, "gimnasio_id": 1, "habilidades": {"scope": ["user"]}, "ultimo_uso_en": null, "actualizado_en": "2025-12-26T01:38:39.808047"}	\N	\N	\N	\N	2025-12-26 01:38:39.808047	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
95	1	\N	1	1	U	gym	personas	2	{"id": 2, "sexo": "M", "cedula": "1312345678", "ciudad": "Manta", "celular": "099000111", "nombres": "Juan", "apellidos": "Pérez", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Los Esteros", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "juan@revive.com", "fecha_nacimiento": "2000-05-10"}	{"id": 2, "sexo": "M", "cedula": "1312345678", "ciudad": "Manta", "celular": "099111999", "nombres": "Juan", "apellidos": "Pérez", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Los Esteros", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "juan@revive.com", "fecha_nacimiento": "2000-05-10"}	{"celular": ["099000111", "099111999"]}	seed_req_001	\N	Seed-Runner	2025-12-26 01:38:39.808047	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
96	\N	\N	\N	\N	I	gym	gimnasios	3	\N	{"id": 3, "ruc": null, "activo": true, "nombre": "Revive", "creado_en": "2025-12-26T01:50:38.381432", "actualizado_en": "2025-12-26T01:50:38.381432"}	\N	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
97	\N	\N	\N	\N	I	gym	gimnasios	4	\N	{"id": 4, "ruc": null, "activo": true, "nombre": "TrainRevive Demo", "creado_en": "2025-12-26T01:50:38.381432", "actualizado_en": "2025-12-26T01:50:38.381432"}	\N	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
98	1	\N	\N	\N	U	gym	sedes	1	{"id": 1, "activa": true, "nombre": "Revive Home", "telefono": "0999999999", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Dirección Home", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 1, "activa": true, "nombre": "Revive Home", "telefono": "0999999999", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Dirección Home", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
99	1	\N	\N	\N	U	gym	sedes	2	{"id": 2, "activa": true, "nombre": "Revive Xpadel", "telefono": "0988888888", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Dirección Xpadel", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 2, "activa": true, "nombre": "Revive Xpadel", "telefono": "0988888888", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Dirección Xpadel", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
100	1	\N	\N	\N	U	gym	sedes	3	{"id": 3, "activa": true, "nombre": "Revive Centro", "telefono": "0977777777", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Dirección Centro", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 3, "activa": true, "nombre": "Revive Centro", "telefono": "0977777777", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Dirección Centro", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
107	1	\N	\N	\N	U	gym	auth_usuarios	2	{"id": 2, "email": "cajero@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 3, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_CAJERO_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	{"id": 2, "email": "cajero@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 3, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_CAJERO_DEMO", "actualizado_en": "2025-12-26T01:50:38.381432", "foto_perfil_url": null, "actualizado_por_id": null}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
101	1	\N	\N	\N	U	gym	personas	1	{"id": 1, "sexo": "F", "cedula": "1300000001", "ciudad": "Manta", "celular": "098000222", "nombres": "María", "apellidos": "Admin", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Tarqui", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "admin@revive.com", "fecha_nacimiento": "1995-01-15"}	{"id": 1, "sexo": "F", "cedula": "1300000001", "ciudad": "Manta", "celular": "098000222", "nombres": "María", "apellidos": "Admin", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Tarqui", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:50:38.381432", "email_contacto": "admin@revive.com", "fecha_nacimiento": "1995-01-15"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
102	1	\N	\N	\N	U	gym	personas	2	{"id": 2, "sexo": "M", "cedula": "1312345678", "ciudad": "Manta", "celular": "099111999", "nombres": "Juan", "apellidos": "Pérez", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Los Esteros", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "juan@revive.com", "fecha_nacimiento": "2000-05-10"}	{"id": 2, "sexo": "M", "cedula": "1312345678", "ciudad": "Manta", "celular": "099000111", "nombres": "Juan", "apellidos": "Pérez", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Los Esteros", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:50:38.381432", "email_contacto": "juan@revive.com", "fecha_nacimiento": "2000-05-10"}	{"celular": ["099111999", "099000111"], "actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
103	1	\N	\N	\N	U	gym	personas	3	{"id": 3, "sexo": "M", "cedula": "1300000002", "ciudad": "Manta", "celular": "097000333", "nombres": "Carlos", "apellidos": "Cajero", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Centro", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "cajero@revive.com", "fecha_nacimiento": "1998-08-20"}	{"id": 3, "sexo": "M", "cedula": "1300000002", "ciudad": "Manta", "celular": "097000333", "nombres": "Carlos", "apellidos": "Cajero", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Centro", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:50:38.381432", "email_contacto": "cajero@revive.com", "fecha_nacimiento": "1998-08-20"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
104	1	\N	\N	\N	U	gym	personas	4	{"id": 4, "sexo": "F", "cedula": "1300000003", "ciudad": "Manta", "celular": "096000444", "nombres": "Ana", "apellidos": "Trainer", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Uleam", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "trainer@revive.com", "fecha_nacimiento": "1997-03-12"}	{"id": 4, "sexo": "F", "cedula": "1300000003", "ciudad": "Manta", "celular": "096000444", "nombres": "Ana", "apellidos": "Trainer", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Uleam", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:50:38.381432", "email_contacto": "trainer@revive.com", "fecha_nacimiento": "1997-03-12"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
105	1	\N	\N	\N	U	gym	personas	5	{"id": 5, "sexo": "M", "cedula": "1300000004", "ciudad": "Manta", "celular": "095000555", "nombres": "Luis", "apellidos": "Cliente", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Xpadel", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:38:39.808047", "email_contacto": "luis@revive.com", "fecha_nacimiento": "2002-11-02"}	{"id": 5, "sexo": "M", "cedula": "1300000004", "ciudad": "Manta", "celular": "095000555", "nombres": "Luis", "apellidos": "Cliente", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Xpadel", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:50:38.381432", "email_contacto": "luis@revive.com", "fecha_nacimiento": "2002-11-02"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
106	1	\N	\N	\N	U	gym	auth_usuarios	1	{"id": 1, "email": "admin@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 1, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_ADMIN_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	{"id": 1, "email": "admin@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 1, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_ADMIN_DEMO", "actualizado_en": "2025-12-26T01:50:38.381432", "foto_perfil_url": null, "actualizado_por_id": null}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
126	1	\N	\N	\N	U	gym	auth_permisos	4	{"id": 4, "activo": true, "codigo": "ROLES_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar roles", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD roles", "gimnasio_id": 1}	{"id": 4, "activo": true, "codigo": "ROLES_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar roles", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD roles", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
108	1	\N	\N	\N	U	gym	auth_usuarios	3	{"id": 3, "email": "trainer@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 4, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_TRAINER_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	{"id": 3, "email": "trainer@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 4, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_TRAINER_DEMO", "actualizado_en": "2025-12-26T01:50:38.381432", "foto_perfil_url": null, "actualizado_por_id": null}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
109	1	\N	\N	\N	U	gym	auth_usuarios	4	{"id": 4, "email": "luis@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 5, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_CLIENTE_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	{"id": 4, "email": "luis@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 5, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_CLIENTE_DEMO", "actualizado_en": "2025-12-26T01:50:38.381432", "foto_perfil_url": null, "actualizado_por_id": null}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
110	1	\N	\N	\N	U	gym	auth_usuarios	5	{"id": 5, "email": "juan@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 2, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_CLIENTE_DEMO", "actualizado_en": "2025-12-26T01:38:39.808047", "foto_perfil_url": null, "actualizado_por_id": null}	{"id": 5, "email": "juan@revive.com", "estado": "ACTIVO", "creado_en": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 2, "gimnasio_id": 1, "creado_por_id": null, "password_hash": "HASH_CLIENTE_DEMO", "actualizado_en": "2025-12-26T01:50:38.381432", "foto_perfil_url": null, "actualizado_por_id": null}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
111	1	\N	\N	\N	U	gym	auth_roles	1	{"id": 1, "activo": true, "codigo": "ADMIN", "nombre": "Administrador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso total", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 1, "activo": true, "codigo": "ADMIN", "nombre": "Administrador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso total", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
112	1	\N	\N	\N	U	gym	auth_roles	1	{"id": 1, "activo": true, "codigo": "ADMIN", "nombre": "Administrador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso total", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 1, "activo": true, "codigo": "ADMIN", "nombre": "Administrador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso total", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
113	1	\N	\N	\N	U	gym	auth_roles	2	{"id": 2, "activo": true, "codigo": "CAJERO", "nombre": "Cajero", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ventas y pagos", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 2, "activo": true, "codigo": "CAJERO", "nombre": "Cajero", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ventas y pagos", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
114	1	\N	\N	\N	U	gym	auth_roles	2	{"id": 2, "activo": true, "codigo": "CAJERO", "nombre": "Cajero", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ventas y pagos", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 2, "activo": true, "codigo": "CAJERO", "nombre": "Cajero", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ventas y pagos", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
115	1	\N	\N	\N	U	gym	auth_roles	3	{"id": 3, "activo": true, "codigo": "ENTRENADOR", "nombre": "Entrenador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Gestión de clientes y evaluaciones", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 3, "activo": true, "codigo": "ENTRENADOR", "nombre": "Entrenador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Gestión de clientes y evaluaciones", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
127	1	\N	\N	\N	U	gym	auth_permisos	5	{"id": 5, "activo": true, "codigo": "PERMISOS_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar permisos", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD permisos", "gimnasio_id": 1}	{"id": 5, "activo": true, "codigo": "PERMISOS_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar permisos", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD permisos", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
116	1	\N	\N	\N	U	gym	auth_roles	3	{"id": 3, "activo": true, "codigo": "ENTRENADOR", "nombre": "Entrenador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Gestión de clientes y evaluaciones", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 3, "activo": true, "codigo": "ENTRENADOR", "nombre": "Entrenador", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Gestión de clientes y evaluaciones", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
117	1	\N	\N	\N	U	gym	auth_roles	4	{"id": 4, "activo": true, "codigo": "CLIENTE", "nombre": "Cliente", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso a su perfil", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 4, "activo": true, "codigo": "CLIENTE", "nombre": "Cliente", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso a su perfil", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
118	1	\N	\N	\N	U	gym	auth_roles	4	{"id": 4, "activo": true, "codigo": "CLIENTE", "nombre": "Cliente", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso a su perfil", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047"}	{"id": 4, "activo": true, "codigo": "CLIENTE", "nombre": "Cliente", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Acceso a su perfil", "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:50:38.381432"}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:50:38.381432"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
119	1	\N	\N	\N	U	gym	auth_permisos	1	{"id": 1, "activo": true, "codigo": "USUARIOS_VER", "modulo": "USUARIOS", "nombre": "Ver usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Lista y detalles", "gimnasio_id": 1}	{"id": 1, "activo": true, "codigo": "USUARIOS_VER", "modulo": "USUARIOS", "nombre": "Ver usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Lista y detalles", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
120	1	\N	\N	\N	U	gym	auth_permisos	1	{"id": 1, "activo": true, "codigo": "USUARIOS_VER", "modulo": "USUARIOS", "nombre": "Ver usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Lista y detalles", "gimnasio_id": 1}	{"id": 1, "activo": true, "codigo": "USUARIOS_VER", "modulo": "USUARIOS", "nombre": "Ver usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Lista y detalles", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
121	1	\N	\N	\N	U	gym	auth_permisos	2	{"id": 2, "activo": true, "codigo": "USUARIOS_CREAR", "modulo": "USUARIOS", "nombre": "Crear usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Crear nuevos usuarios", "gimnasio_id": 1}	{"id": 2, "activo": true, "codigo": "USUARIOS_CREAR", "modulo": "USUARIOS", "nombre": "Crear usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Crear nuevos usuarios", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
122	1	\N	\N	\N	U	gym	auth_permisos	2	{"id": 2, "activo": true, "codigo": "USUARIOS_CREAR", "modulo": "USUARIOS", "nombre": "Crear usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Crear nuevos usuarios", "gimnasio_id": 1}	{"id": 2, "activo": true, "codigo": "USUARIOS_CREAR", "modulo": "USUARIOS", "nombre": "Crear usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Crear nuevos usuarios", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
123	1	\N	\N	\N	U	gym	auth_permisos	3	{"id": 3, "activo": true, "codigo": "USUARIOS_EDITAR", "modulo": "USUARIOS", "nombre": "Editar usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Editar datos de usuario", "gimnasio_id": 1}	{"id": 3, "activo": true, "codigo": "USUARIOS_EDITAR", "modulo": "USUARIOS", "nombre": "Editar usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Editar datos de usuario", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
124	1	\N	\N	\N	U	gym	auth_permisos	3	{"id": 3, "activo": true, "codigo": "USUARIOS_EDITAR", "modulo": "USUARIOS", "nombre": "Editar usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Editar datos de usuario", "gimnasio_id": 1}	{"id": 3, "activo": true, "codigo": "USUARIOS_EDITAR", "modulo": "USUARIOS", "nombre": "Editar usuarios", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Editar datos de usuario", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
125	1	\N	\N	\N	U	gym	auth_permisos	4	{"id": 4, "activo": true, "codigo": "ROLES_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar roles", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD roles", "gimnasio_id": 1}	{"id": 4, "activo": true, "codigo": "ROLES_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar roles", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD roles", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
139	\N	1	\N	\N	U	gym	usuario_sedes	1	{"id": 1, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": true}	{"id": 1, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": true}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
128	1	\N	\N	\N	U	gym	auth_permisos	5	{"id": 5, "activo": true, "codigo": "PERMISOS_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar permisos", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD permisos", "gimnasio_id": 1}	{"id": 5, "activo": true, "codigo": "PERMISOS_ADMIN", "modulo": "USUARIOS", "nombre": "Administrar permisos", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD permisos", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
129	1	\N	\N	\N	U	gym	auth_permisos	6	{"id": 6, "activo": true, "codigo": "MENU_ADMIN", "modulo": "CONFIG", "nombre": "Administrar menú", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD menú", "gimnasio_id": 1}	{"id": 6, "activo": true, "codigo": "MENU_ADMIN", "modulo": "CONFIG", "nombre": "Administrar menú", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD menú", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
130	1	\N	\N	\N	U	gym	auth_permisos	6	{"id": 6, "activo": true, "codigo": "MENU_ADMIN", "modulo": "CONFIG", "nombre": "Administrar menú", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD menú", "gimnasio_id": 1}	{"id": 6, "activo": true, "codigo": "MENU_ADMIN", "modulo": "CONFIG", "nombre": "Administrar menú", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "CRUD menú", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
131	1	\N	\N	\N	U	gym	auth_permisos	7	{"id": 7, "activo": true, "codigo": "AUDITORIA_VER", "modulo": "CONFIG", "nombre": "Ver auditoría", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Consultar auditoría", "gimnasio_id": 1}	{"id": 7, "activo": true, "codigo": "AUDITORIA_VER", "modulo": "CONFIG", "nombre": "Ver auditoría", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Consultar auditoría", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
132	1	\N	\N	\N	U	gym	auth_permisos	7	{"id": 7, "activo": true, "codigo": "AUDITORIA_VER", "modulo": "CONFIG", "nombre": "Ver auditoría", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Consultar auditoría", "gimnasio_id": 1}	{"id": 7, "activo": true, "codigo": "AUDITORIA_VER", "modulo": "CONFIG", "nombre": "Ver auditoría", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Consultar auditoría", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
133	1	\N	\N	\N	U	gym	auth_permisos	8	{"id": 8, "activo": true, "codigo": "MEMBRESIAS_VER", "modulo": "MEMBRESIAS", "nombre": "Ver membresías", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar membresías", "gimnasio_id": 1}	{"id": 8, "activo": true, "codigo": "MEMBRESIAS_VER", "modulo": "MEMBRESIAS", "nombre": "Ver membresías", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar membresías", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
134	1	\N	\N	\N	U	gym	auth_permisos	8	{"id": 8, "activo": true, "codigo": "MEMBRESIAS_VER", "modulo": "MEMBRESIAS", "nombre": "Ver membresías", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar membresías", "gimnasio_id": 1}	{"id": 8, "activo": true, "codigo": "MEMBRESIAS_VER", "modulo": "MEMBRESIAS", "nombre": "Ver membresías", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar membresías", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
135	1	\N	\N	\N	U	gym	auth_permisos	9	{"id": 9, "activo": true, "codigo": "INVENTARIO_VER", "modulo": "INVENTARIO", "nombre": "Ver inventario", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ver stock", "gimnasio_id": 1}	{"id": 9, "activo": true, "codigo": "INVENTARIO_VER", "modulo": "INVENTARIO", "nombre": "Ver inventario", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ver stock", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
136	1	\N	\N	\N	U	gym	auth_permisos	9	{"id": 9, "activo": true, "codigo": "INVENTARIO_VER", "modulo": "INVENTARIO", "nombre": "Ver inventario", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ver stock", "gimnasio_id": 1}	{"id": 9, "activo": true, "codigo": "INVENTARIO_VER", "modulo": "INVENTARIO", "nombre": "Ver inventario", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Ver stock", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
137	1	\N	\N	\N	U	gym	auth_permisos	10	{"id": 10, "activo": true, "codigo": "FACTURAS_VER", "modulo": "FACTURACION", "nombre": "Ver facturas", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar facturas", "gimnasio_id": 1}	{"id": 10, "activo": true, "codigo": "FACTURAS_VER", "modulo": "FACTURACION", "nombre": "Ver facturas", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar facturas", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
138	1	\N	\N	\N	U	gym	auth_permisos	10	{"id": 10, "activo": true, "codigo": "FACTURAS_VER", "modulo": "FACTURACION", "nombre": "Ver facturas", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar facturas", "gimnasio_id": 1}	{"id": 10, "activo": true, "codigo": "FACTURAS_VER", "modulo": "FACTURACION", "nombre": "Ver facturas", "creado_en": "2025-12-26T01:38:39.808047", "descripcion": "Listar facturas", "gimnasio_id": 1}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
309	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	40	\N	{"id": 40, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 22}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
140	\N	1	\N	\N	U	gym	usuario_sedes	2	{"id": 2, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 2, "es_principal": true}	{"id": 2, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 2, "es_principal": true}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
141	\N	1	\N	\N	U	gym	usuario_sedes	3	{"id": 3, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 5, "es_principal": true}	{"id": 3, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 5, "es_principal": true}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
142	\N	1	\N	\N	U	gym	usuario_sedes	4	{"id": 4, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 4, "es_principal": true}	{"id": 4, "activo": true, "sede_id": 1, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 4, "es_principal": true}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
143	\N	2	\N	\N	U	gym	usuario_sedes	5	{"id": 5, "activo": true, "sede_id": 2, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 3, "es_principal": true}	{"id": 5, "activo": true, "sede_id": 2, "creado_en": "2025-12-26T01:38:39.808047", "usuario_id": 3, "es_principal": true}	{}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
144	1	\N	1	1	U	gym	personas	2	{"id": 2, "sexo": "M", "cedula": "1312345678", "ciudad": "Manta", "celular": "099000111", "nombres": "Juan", "apellidos": "Pérez", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Los Esteros", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:50:38.381432", "email_contacto": "juan@revive.com", "fecha_nacimiento": "2000-05-10"}	{"id": 2, "sexo": "M", "cedula": "1312345678", "ciudad": "Manta", "celular": "099111999", "nombres": "Juan", "apellidos": "Pérez", "creado_en": "2025-12-26T01:38:39.808047", "direccion": "Manta", "parroquia": "Los Esteros", "provincia": "Manabí", "imagen_url": null, "gimnasio_id": 1, "nacionalidad": "Ecuatoriana", "actualizado_en": "2025-12-26T01:50:38.381432", "email_contacto": "juan@revive.com", "fecha_nacimiento": "2000-05-10"}	{"celular": ["099000111", "099111999"]}	seed_req_all	\N	Seed-Runner	2025-12-26 01:50:38.381432	\N	190.12.34.56	190.12.34.56, 10.0.0.1	{"x_forwarded_for": "190.12.34.56", "cf_connecting_ip": null}	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
145	1	\N	1	1	I	gym	auth_menu_items	11	\N	{"id": 11, "ruta": null, "tipo": "GRUPO", "icono": "settings", "orden": 1, "titulo": "Administración", "visible": true, "creado_en": "2025-12-26T01:51:25.889901", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": null}	\N	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
146	1	\N	1	1	I	gym	auth_menu_items	11	\N	{"id": 11, "ruta": null, "tipo": "GRUPO", "icono": "settings", "orden": 1, "titulo": "Administración", "visible": true, "creado_en": "2025-12-26T01:51:25.889901", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": null}	\N	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
147	1	\N	1	1	I	gym	auth_menu_items	12	\N	{"id": 12, "ruta": null, "tipo": "GRUPO", "icono": "grid", "orden": 2, "titulo": "Operación", "visible": true, "creado_en": "2025-12-26T01:51:25.889901", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": null}	\N	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
148	1	\N	1	1	I	gym	auth_menu_items	12	\N	{"id": 12, "ruta": null, "tipo": "GRUPO", "icono": "grid", "orden": 2, "titulo": "Operación", "visible": true, "creado_en": "2025-12-26T01:51:25.889901", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": null}	\N	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
149	1	\N	1	1	U	gym	auth_menu_items	3	{"id": 3, "ruta": "/admin/usuarios", "tipo": "ITEM", "icono": "users", "orden": 1, "titulo": "Usuarios", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 1}	{"id": 3, "ruta": "/admin/usuarios", "tipo": "ITEM", "icono": "users", "orden": 1, "titulo": "Usuarios", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 1}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
150	1	\N	1	1	U	gym	auth_menu_items	3	{"id": 3, "ruta": "/admin/usuarios", "tipo": "ITEM", "icono": "users", "orden": 1, "titulo": "Usuarios", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 1}	{"id": 3, "ruta": "/admin/usuarios", "tipo": "ITEM", "icono": "users", "orden": 1, "titulo": "Usuarios", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 1}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
151	1	\N	1	1	U	gym	auth_menu_items	4	{"id": 4, "ruta": "/admin/roles", "tipo": "ITEM", "icono": "shield", "orden": 2, "titulo": "Roles", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 4}	{"id": 4, "ruta": "/admin/roles", "tipo": "ITEM", "icono": "shield", "orden": 2, "titulo": "Roles", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 4}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
152	1	\N	1	1	U	gym	auth_menu_items	4	{"id": 4, "ruta": "/admin/roles", "tipo": "ITEM", "icono": "shield", "orden": 2, "titulo": "Roles", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 4}	{"id": 4, "ruta": "/admin/roles", "tipo": "ITEM", "icono": "shield", "orden": 2, "titulo": "Roles", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 4}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
153	1	\N	1	1	U	gym	auth_menu_items	5	{"id": 5, "ruta": "/admin/permisos", "tipo": "ITEM", "icono": "key", "orden": 3, "titulo": "Permisos", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 5}	{"id": 5, "ruta": "/admin/permisos", "tipo": "ITEM", "icono": "key", "orden": 3, "titulo": "Permisos", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 5}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
154	1	\N	1	1	U	gym	auth_menu_items	5	{"id": 5, "ruta": "/admin/permisos", "tipo": "ITEM", "icono": "key", "orden": 3, "titulo": "Permisos", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 5}	{"id": 5, "ruta": "/admin/permisos", "tipo": "ITEM", "icono": "key", "orden": 3, "titulo": "Permisos", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 5}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
155	1	\N	1	1	U	gym	auth_menu_items	6	{"id": 6, "ruta": "/admin/menu", "tipo": "ITEM", "icono": "menu", "orden": 4, "titulo": "Menú", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 6}	{"id": 6, "ruta": "/admin/menu", "tipo": "ITEM", "icono": "menu", "orden": 4, "titulo": "Menú", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 6}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
156	1	\N	1	1	U	gym	auth_menu_items	6	{"id": 6, "ruta": "/admin/menu", "tipo": "ITEM", "icono": "menu", "orden": 4, "titulo": "Menú", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 6}	{"id": 6, "ruta": "/admin/menu", "tipo": "ITEM", "icono": "menu", "orden": 4, "titulo": "Menú", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 6}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
157	1	\N	1	1	U	gym	auth_menu_items	7	{"id": 7, "ruta": "/admin/auditoria", "tipo": "ITEM", "icono": "file-search", "orden": 5, "titulo": "Auditoría", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 7}	{"id": 7, "ruta": "/admin/auditoria", "tipo": "ITEM", "icono": "file-search", "orden": 5, "titulo": "Auditoría", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 7}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
158	1	\N	1	1	U	gym	auth_menu_items	7	{"id": 7, "ruta": "/admin/auditoria", "tipo": "ITEM", "icono": "file-search", "orden": 5, "titulo": "Auditoría", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 7}	{"id": 7, "ruta": "/admin/auditoria", "tipo": "ITEM", "icono": "file-search", "orden": 5, "titulo": "Auditoría", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 1, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 7}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
159	1	\N	1	1	U	gym	auth_menu_items	8	{"id": 8, "ruta": "/operacion/membresias", "tipo": "ITEM", "icono": "id-card", "orden": 1, "titulo": "Membresías", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 8}	{"id": 8, "ruta": "/operacion/membresias", "tipo": "ITEM", "icono": "id-card", "orden": 1, "titulo": "Membresías", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 8}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
168	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	10	\N	{"id": 10, "nombre": "Entrenamiento físico", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2025-10-01T16:17:40.874286", "descripcion": "Rutinas de fuerza, resistencia, cardio y HIIT en el gimnasio"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
169	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	11	\N	{"id": 11, "nombre": "Entrenamiento funcional", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2025-10-01T16:21:23.044032", "descripcion": "Movimientos que mejoran la movilidad y el core con TRX y pesas rusas"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
160	1	\N	1	1	U	gym	auth_menu_items	8	{"id": 8, "ruta": "/operacion/membresias", "tipo": "ITEM", "icono": "id-card", "orden": 1, "titulo": "Membresías", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 8}	{"id": 8, "ruta": "/operacion/membresias", "tipo": "ITEM", "icono": "id-card", "orden": 1, "titulo": "Membresías", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 8}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
161	1	\N	1	1	U	gym	auth_menu_items	9	{"id": 9, "ruta": "/operacion/inventario", "tipo": "ITEM", "icono": "boxes", "orden": 2, "titulo": "Inventario", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 9}	{"id": 9, "ruta": "/operacion/inventario", "tipo": "ITEM", "icono": "boxes", "orden": 2, "titulo": "Inventario", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 9}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
162	1	\N	1	1	U	gym	auth_menu_items	9	{"id": 9, "ruta": "/operacion/inventario", "tipo": "ITEM", "icono": "boxes", "orden": 2, "titulo": "Inventario", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 9}	{"id": 9, "ruta": "/operacion/inventario", "tipo": "ITEM", "icono": "boxes", "orden": 2, "titulo": "Inventario", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 9}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
163	1	\N	1	1	U	gym	auth_menu_items	10	{"id": 10, "ruta": "/operacion/facturas", "tipo": "ITEM", "icono": "receipt", "orden": 3, "titulo": "Facturación", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 10}	{"id": 10, "ruta": "/operacion/facturas", "tipo": "ITEM", "icono": "receipt", "orden": 3, "titulo": "Facturación", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 10}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
164	1	\N	1	1	U	gym	auth_menu_items	10	{"id": 10, "ruta": "/operacion/facturas", "tipo": "ITEM", "icono": "receipt", "orden": 3, "titulo": "Facturación", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:38:39.808047", "permiso_requerido_id": 10}	{"id": 10, "ruta": "/operacion/facturas", "tipo": "ITEM", "icono": "receipt", "orden": 3, "titulo": "Facturación", "visible": true, "creado_en": "2025-12-26T01:38:39.808047", "parent_id": 2, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": 10}	{"actualizado_en": ["2025-12-26T01:38:39.808047", "2025-12-26T01:51:25.889901"]}	seed_menu	\N	Seed-Runner	2025-12-26 01:51:25.889901	\N	190.12.34.56	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
165	1	\N	\N	\N	U	gym	auth_menu_items	12	{"id": 12, "ruta": null, "tipo": "GRUPO", "icono": "grid", "orden": 2, "titulo": "Operación", "visible": true, "creado_en": "2025-12-26T01:51:25.889901", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": null}	{"id": 12, "ruta": null, "tipo": "GRUPO", "icono": "grid", "orden": 2, "titulo": "Operación", "visible": true, "creado_en": "2025-12-26T01:51:25.889901", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": null}	{}	prueba_equipo	\N	Seed-Runner	2025-12-26 01:53:55.545826	\N	190.12.34.56	\N	\N	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
166	1	\N	\N	\N	U	gym	auth_menu_items	12	{"id": 12, "ruta": null, "tipo": "GRUPO", "icono": "grid", "orden": 2, "titulo": "Operación", "visible": true, "creado_en": "2025-12-26T01:51:25.889901", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": null}	{"id": 12, "ruta": null, "tipo": "GRUPO", "icono": "grid", "orden": 2, "titulo": "Operación", "visible": true, "creado_en": "2025-12-26T01:51:25.889901", "parent_id": null, "gimnasio_id": 1, "actualizado_en": "2025-12-26T01:51:25.889901", "permiso_requerido_id": null}	{}	prueba_equipo	\N	Seed-Runner	2025-12-26 01:53:55.545826	\N	190.12.34.56	\N	\N	\N	\N	\N	PC-Recepcion	admin	::1	\N	\N	\N
167	1	\N	\N	\N	U	train_gimnasio	auth_usuarios	1	{"id": 1, "email": "admin@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 1, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "HASH_ADMIN_DEMO", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{"id": 1, "email": "admin@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 1, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "$2y$12$XPMlI5sbIayz/NjJCd.6SusC/z7HnCbzsJEjaT.znqXok7v1F5ayK", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{"password_hash": ["HASH_ADMIN_DEMO", "$2y$12$XPMlI5sbIayz/NjJCd.6SusC/z7HnCbzsJEjaT.znqXok7v1F5ayK"]}	\N	\N	\N	2026-02-04 11:35:17.250228	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
170	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	12	\N	{"id": 12, "nombre": "Yoga", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2025-10-01T16:23:03.687842", "descripcion": "Posturas, respiración y meditación para flexibilidad y calma"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
171	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	13	\N	{"id": 13, "nombre": "Pilates", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2025-10-01T16:24:25.957097", "descripcion": "Ejercicios de core, alineación y respiración en colchoneta y aparatos"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
172	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	14	\N	{"id": 14, "nombre": "Spinning", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2025-10-01T16:25:14.062495", "descripcion": "Cardio en bicicleta estática con entrenamientos de intensidad variable"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
173	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	15	\N	{"id": 15, "nombre": "Bailoterapia", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2025-10-01T16:27:02.947914", "descripcion": "Baile-fitness con música latina y ritmos de alta energía"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
174	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	16	\N	{"id": 16, "nombre": "CrossFit", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2025-11-26T09:20:36", "descripcion": "Entrenamiento funcional de alta intensidad con pesas, gimnasia y cardio"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
175	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	17	\N	{"id": 17, "nombre": "Nutrición", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2025-10-01T16:29:21.499668", "descripcion": "Asesoría dietética, planes de comidas y seguimiento de objetivos"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
176	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	18	\N	{"id": 18, "nombre": "Rehabilitación", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2025-10-01T16:30:28.686279", "descripcion": "Terapia física y movilización post-operatoria y de lesiones"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
177	\N	\N	\N	\N	I	train_gimnasio	categoria_servicios	19	\N	{"id": 19, "nombre": "Masajes", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2025-11-25T23:13:01", "descripcion": "Masajes terapéuticos, relajantes y deportivos"}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
178	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	13	\N	{"id": 13, "nombre": "Rutina de fuerza", "user_id": 1, "estado_id": 1, "breve_desc": "Fuerza", "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2025-10-01T16:17:40.874286", "descripcion": "Programa de fuerza con pesas y máquinas", "categoria_id": 10}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
179	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	14	\N	{"id": 14, "nombre": "HIIT", "user_id": 1, "estado_id": 1, "breve_desc": "HIIT", "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2025-10-01T16:17:40.874286", "descripcion": "Entrenamiento de alta intensidad en 20-30 min", "categoria_id": 10}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
180	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	15	\N	{"id": 15, "nombre": "Circuitos de cardio", "user_id": 1, "estado_id": 1, "breve_desc": "Circuito", "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2025-10-01T16:17:40.874286", "descripcion": "Combina cardio y fuerza en un solo circuito", "categoria_id": 10}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
181	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	16	\N	{"id": 16, "nombre": "Calistenia", "user_id": 1, "estado_id": 1, "breve_desc": "TRX", "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2025-10-01T16:21:23.044032", "descripcion": "Entrenamiento con bandas de suspensión", "categoria_id": 11}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
182	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	17	\N	{"id": 17, "nombre": "Pesas Rusas", "user_id": 1, "estado_id": 1, "breve_desc": "Pesas", "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2025-10-01T16:21:23.044032", "descripcion": "Ejercicios funcionales con pesas rusas", "categoria_id": 11}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
183	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	18	\N	{"id": 18, "nombre": "Gymnastics", "user_id": 1, "estado_id": 1, "breve_desc": "Gymnastics", "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2025-10-01T16:21:23.044032", "descripcion": "Ejercicios con calistenia y movimientos dinámicos", "categoria_id": 11}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
184	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	19	\N	{"id": 19, "nombre": "Yoga Restaurador", "user_id": 1, "estado_id": 1, "breve_desc": "Restaurador", "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2025-10-01T16:23:03.687842", "descripcion": "Posturas suaves y respiración consciente", "categoria_id": 12}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
185	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	20	\N	{"id": 20, "nombre": "Vinyasa", "user_id": 1, "estado_id": 1, "breve_desc": "Vinyasa", "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2025-10-01T16:23:03.687842", "descripcion": "Secuencia fluida de posturas y movimiento", "categoria_id": 12}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
186	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	21	\N	{"id": 21, "nombre": "Power Yoga", "user_id": 1, "estado_id": 1, "breve_desc": "Power", "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2025-10-01T16:23:03.687842", "descripcion": "Yoga de alta intensidad", "categoria_id": 12}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
187	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	22	\N	{"id": 22, "nombre": "Pilates Mat", "user_id": 1, "estado_id": 1, "breve_desc": "Mat", "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2025-10-01T16:24:25.957097", "descripcion": "Rutinas en colchoneta para fuerza y flexibilidad", "categoria_id": 13}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
188	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	23	\N	{"id": 23, "nombre": "Pilates Reformer", "user_id": 1, "estado_id": 1, "breve_desc": "Reformer", "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2025-10-01T16:24:25.957097", "descripcion": "Ejercicios con aparato Reformer", "categoria_id": 13}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
189	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	24	\N	{"id": 24, "nombre": "Pilates en suspensión", "user_id": 1, "estado_id": 1, "breve_desc": "Suspensión", "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2025-10-01T16:24:25.957097", "descripcion": "Pilates en barra de suspensión", "categoria_id": 13}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
190	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	25	\N	{"id": 25, "nombre": "Spinning 45", "user_id": 1, "estado_id": 1, "breve_desc": "45", "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2025-10-01T16:25:14.062495", "descripcion": "Sesión de 45 min a ritmo moderado-intenso", "categoria_id": 14}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
191	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	26	\N	{"id": 26, "nombre": "Spinning avanzado", "user_id": 1, "estado_id": 1, "breve_desc": "Avanzado", "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2025-10-01T16:25:14.062495", "descripcion": "Intensidad progresiva con resistencia alta", "categoria_id": 14}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
192	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	27	\N	{"id": 27, "nombre": "Spinning + HIIT", "user_id": 1, "estado_id": 1, "breve_desc": "Mix", "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2025-10-01T16:25:14.062495", "descripcion": "Mezcla de ciclismo y entrenamientos cortos de fuerza", "categoria_id": 14}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
193	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	28	\N	{"id": 28, "nombre": "Bailoterapia Básica", "user_id": 1, "estado_id": 1, "breve_desc": "Básica", "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2025-10-01T16:27:02.947914", "descripcion": "Clases para principiantes y nivel intermedio", "categoria_id": 15}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
194	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	29	\N	{"id": 29, "nombre": "Zumba Cardio", "user_id": 1, "estado_id": 1, "breve_desc": "Cardio", "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2025-10-01T16:27:02.947914", "descripcion": "Sesiones de alta energía con ritmo rápido", "categoria_id": 15}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
195	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	30	\N	{"id": 30, "nombre": "Bailoterapia + HIIT", "user_id": 1, "estado_id": 1, "breve_desc": "HIIT", "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2025-10-01T16:27:02.947914", "descripcion": "Mezcla de baile y entrenamientos cortos de fuerza", "categoria_id": 15}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
196	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	31	\N	{"id": 31, "nombre": "CrossFit", "user_id": 1, "estado_id": 1, "breve_desc": "WOD", "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2025-10-01T16:27:59.24903", "descripcion": "Rutina de CrossFit (WOD – workout of the day)", "categoria_id": 16}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
197	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	32	\N	{"id": 32, "nombre": "CrossFit Básica", "user_id": 1, "estado_id": 1, "breve_desc": "Básica", "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2025-10-01T16:27:59.24903", "descripcion": "Entrenamiento de fuerza y cardio para principiantes", "categoria_id": 16}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
198	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	33	\N	{"id": 33, "nombre": "CrossFit Avanzado", "user_id": 1, "estado_id": 1, "breve_desc": "Avanzado", "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2025-10-01T16:27:59.24903", "descripcion": "Rutina de alta intensidad para atletas", "categoria_id": 16}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
199	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	34	\N	{"id": 34, "nombre": "Plan de comidas", "user_id": 1, "estado_id": 1, "breve_desc": "Plan", "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2025-10-01T16:29:21.499668", "descripcion": "Plan personalizado de comidas diarias", "categoria_id": 17}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
200	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	35	\N	{"id": 35, "nombre": "Seguimiento nutricional", "user_id": 1, "estado_id": 1, "breve_desc": "Seguimiento", "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2025-10-01T16:29:21.499668", "descripcion": "Control de macronutrientes y calorías", "categoria_id": 17}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
201	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	36	\N	{"id": 36, "nombre": "Terapia nutricional", "user_id": 1, "estado_id": 1, "breve_desc": "Terapia", "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2025-10-01T16:29:21.499668", "descripcion": "Apoyo para problemas de salud (diabetes, hipertensión…)", "categoria_id": 17}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
202	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	37	\N	{"id": 37, "nombre": "Rehabilitación ortopédica", "user_id": 1, "estado_id": 1, "breve_desc": "Ortopédica", "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2025-10-01T16:30:28.686279", "descripcion": "Terapia post-operatoria de articulaciones", "categoria_id": 18}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
203	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	38	\N	{"id": 38, "nombre": "Terapia miofascial", "user_id": 1, "estado_id": 1, "breve_desc": "Miofascial", "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2025-10-01T16:30:28.686279", "descripcion": "Masaje y estiramientos para tejidos blandos", "categoria_id": 18}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
204	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	39	\N	{"id": 39, "nombre": "Rehabilitación cardiovascular", "user_id": 1, "estado_id": 1, "breve_desc": "Cardio", "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2025-10-01T16:30:28.686279", "descripcion": "Ejercicios para recuperar resistencia", "categoria_id": 18}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
402	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 7}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
205	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	40	\N	{"id": 40, "nombre": "Masaje deportivo", "user_id": 1, "estado_id": 1, "breve_desc": "Deportivo", "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2025-10-01T16:32:49.854967", "descripcion": "Masaje de tejido profundo para atletas", "categoria_id": 19}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
206	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	41	\N	{"id": 41, "nombre": "Masaje relajante", "user_id": 1, "estado_id": 1, "breve_desc": "Relajante", "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2025-10-01T16:32:49.854967", "descripcion": "Técnicas de relajación profunda", "categoria_id": 19}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
207	\N	\N	\N	\N	I	train_gimnasio	tipos_servicios	42	\N	{"id": 42, "nombre": "Masaje con aromaterapia", "user_id": 1, "estado_id": 1, "breve_desc": null, "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2025-11-25T23:21:11", "descripcion": "Masaje con aceites esenciales", "categoria_id": 19}	\N	\N	\N	\N	2026-02-05 10:50:35.839733	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
208	\N	\N	\N	\N	U	train_gimnasio	gimnasios	1	{"id": 1, "ruc": null, "activo": true, "nombre": "Revive", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:38:39.808047"}	{"id": 1, "ruc": null, "activo": true, "nombre": "Revive", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:38:39.808047"}	{}	\N	\N	\N	2026-02-05 10:54:11.838336	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
209	\N	\N	\N	\N	U	train_gimnasio	gimnasios	2	{"id": 2, "ruc": null, "activo": true, "nombre": "TrainRevive Demo", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:38:39.808047"}	{"id": 2, "ruc": null, "activo": true, "nombre": "TrainRevive Demo", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:38:39.808047"}	{}	\N	\N	\N	2026-02-05 10:54:11.838336	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
210	\N	\N	\N	\N	U	train_gimnasio	gimnasios	3	{"id": 3, "ruc": null, "activo": true, "nombre": "Revive", "created_at": "2025-12-26T01:50:38.381432", "updated_at": "2025-12-26T01:50:38.381432"}	{"id": 3, "ruc": null, "activo": true, "nombre": "Revive", "created_at": "2025-12-26T01:50:38.381432", "updated_at": "2025-12-26T01:50:38.381432"}	{}	\N	\N	\N	2026-02-05 10:54:11.838336	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
211	\N	\N	\N	\N	U	train_gimnasio	gimnasios	4	{"id": 4, "ruc": null, "activo": true, "nombre": "TrainRevive Demo", "created_at": "2025-12-26T01:50:38.381432", "updated_at": "2025-12-26T01:50:38.381432"}	{"id": 4, "ruc": null, "activo": true, "nombre": "TrainRevive Demo", "created_at": "2025-12-26T01:50:38.381432", "updated_at": "2025-12-26T01:50:38.381432"}	{}	\N	\N	\N	2026-02-05 10:54:11.838336	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
212	1	\N	\N	\N	U	train_gimnasio	sedes	1	{"id": 1, "activa": true, "nombre": "Revive Home", "telefono": "0999999999", "direccion": "Dirección Home", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1}	{"id": 1, "activa": true, "nombre": "Revive Home", "telefono": "0999999999", "direccion": "Dirección Home", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1}	{}	\N	\N	\N	2026-02-05 10:54:18.417604	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
213	1	\N	\N	\N	U	train_gimnasio	sedes	2	{"id": 2, "activa": true, "nombre": "Revive Xpadel", "telefono": "0988888888", "direccion": "Dirección Xpadel", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1}	{"id": 2, "activa": true, "nombre": "Revive Xpadel", "telefono": "0988888888", "direccion": "Dirección Xpadel", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1}	{}	\N	\N	\N	2026-02-05 10:54:18.417604	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
214	1	\N	\N	\N	U	train_gimnasio	sedes	3	{"id": 3, "activa": true, "nombre": "Revive Centro", "telefono": "0977777777", "direccion": "Dirección Centro", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1}	{"id": 3, "activa": true, "nombre": "Revive Centro", "telefono": "0977777777", "direccion": "Dirección Centro", "created_at": "2025-12-26T01:38:39.808047", "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1}	{}	\N	\N	\N	2026-02-05 10:54:18.417604	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
215	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	10	{"id": 10, "nombre": "Entrenamiento físico", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2025-10-01T16:17:40.874286", "descripcion": "Rutinas de fuerza, resistencia, cardio y HIIT en el gimnasio"}	{"id": 10, "nombre": "Entrenamiento físico", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Rutinas de fuerza, resistencia, cardio y HIIT en el gimnasio"}	{"updated_at": ["2025-10-01T16:17:40.874286", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
216	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	11	{"id": 11, "nombre": "Entrenamiento funcional", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2025-10-01T16:21:23.044032", "descripcion": "Movimientos que mejoran la movilidad y el core con TRX y pesas rusas"}	{"id": 11, "nombre": "Entrenamiento funcional", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Movimientos que mejoran la movilidad y el core con TRX y pesas rusas"}	{"updated_at": ["2025-10-01T16:21:23.044032", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
217	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	12	{"id": 12, "nombre": "Yoga", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2025-10-01T16:23:03.687842", "descripcion": "Posturas, respiración y meditación para flexibilidad y calma"}	{"id": 12, "nombre": "Yoga", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Posturas, respiración y meditación para flexibilidad y calma"}	{"updated_at": ["2025-10-01T16:23:03.687842", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
218	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	13	{"id": 13, "nombre": "Pilates", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2025-10-01T16:24:25.957097", "descripcion": "Ejercicios de core, alineación y respiración en colchoneta y aparatos"}	{"id": 13, "nombre": "Pilates", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Ejercicios de core, alineación y respiración en colchoneta y aparatos"}	{"updated_at": ["2025-10-01T16:24:25.957097", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
219	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	14	{"id": 14, "nombre": "Spinning", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2025-10-01T16:25:14.062495", "descripcion": "Cardio en bicicleta estática con entrenamientos de intensidad variable"}	{"id": 14, "nombre": "Spinning", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Cardio en bicicleta estática con entrenamientos de intensidad variable"}	{"updated_at": ["2025-10-01T16:25:14.062495", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
220	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	15	{"id": 15, "nombre": "Bailoterapia", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2025-10-01T16:27:02.947914", "descripcion": "Baile-fitness con música latina y ritmos de alta energía"}	{"id": 15, "nombre": "Bailoterapia", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Baile-fitness con música latina y ritmos de alta energía"}	{"updated_at": ["2025-10-01T16:27:02.947914", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
221	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	16	{"id": 16, "nombre": "CrossFit", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2025-11-26T09:20:36", "descripcion": "Entrenamiento funcional de alta intensidad con pesas, gimnasia y cardio"}	{"id": 16, "nombre": "CrossFit", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Entrenamiento funcional de alta intensidad con pesas, gimnasia y cardio"}	{"updated_at": ["2025-11-26T09:20:36", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
222	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	17	{"id": 17, "nombre": "Nutrición", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2025-10-01T16:29:21.499668", "descripcion": "Asesoría dietética, planes de comidas y seguimiento de objetivos"}	{"id": 17, "nombre": "Nutrición", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Asesoría dietética, planes de comidas y seguimiento de objetivos"}	{"updated_at": ["2025-10-01T16:29:21.499668", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
223	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	18	{"id": 18, "nombre": "Rehabilitación", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2025-10-01T16:30:28.686279", "descripcion": "Terapia física y movilización post-operatoria y de lesiones"}	{"id": 18, "nombre": "Rehabilitación", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Terapia física y movilización post-operatoria y de lesiones"}	{"updated_at": ["2025-10-01T16:30:28.686279", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
224	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	19	{"id": 19, "nombre": "Masajes", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2025-11-25T23:13:01", "descripcion": "Masajes terapéuticos, relajantes y deportivos"}	{"id": 19, "nombre": "Masajes", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Masajes terapéuticos, relajantes y deportivos"}	{"updated_at": ["2025-11-25T23:13:01", "2026-02-05T10:54:25.012959"]}	\N	\N	\N	2026-02-05 10:54:25.012959	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
225	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	10	{"id": 10, "nombre": "Entrenamiento físico", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Rutinas de fuerza, resistencia, cardio y HIIT en el gimnasio"}	{"id": 10, "nombre": "Entrenamiento físico", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Rutinas de fuerza, resistencia, cardio y HIIT en el gimnasio"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
226	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	11	{"id": 11, "nombre": "Entrenamiento funcional", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Movimientos que mejoran la movilidad y el core con TRX y pesas rusas"}	{"id": 11, "nombre": "Entrenamiento funcional", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Movimientos que mejoran la movilidad y el core con TRX y pesas rusas"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
227	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	12	{"id": 12, "nombre": "Yoga", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Posturas, respiración y meditación para flexibilidad y calma"}	{"id": 12, "nombre": "Yoga", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Posturas, respiración y meditación para flexibilidad y calma"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
228	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	13	{"id": 13, "nombre": "Pilates", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Ejercicios de core, alineación y respiración en colchoneta y aparatos"}	{"id": 13, "nombre": "Pilates", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Ejercicios de core, alineación y respiración en colchoneta y aparatos"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
268	\N	1	\N	\N	U	train_gimnasio	usuario_sedes	4	{"id": 4, "activo": true, "sede_id": 1, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 4, "es_principal": true}	{"id": 4, "activo": true, "sede_id": 1, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 4, "es_principal": true}	{}	\N	\N	\N	2026-02-05 10:55:16.777058	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
229	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	14	{"id": 14, "nombre": "Spinning", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Cardio en bicicleta estática con entrenamientos de intensidad variable"}	{"id": 14, "nombre": "Spinning", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Cardio en bicicleta estática con entrenamientos de intensidad variable"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
230	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	15	{"id": 15, "nombre": "Bailoterapia", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Baile-fitness con música latina y ritmos de alta energía"}	{"id": 15, "nombre": "Bailoterapia", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Baile-fitness con música latina y ritmos de alta energía"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
231	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	16	{"id": 16, "nombre": "CrossFit", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Entrenamiento funcional de alta intensidad con pesas, gimnasia y cardio"}	{"id": 16, "nombre": "CrossFit", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Entrenamiento funcional de alta intensidad con pesas, gimnasia y cardio"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
232	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	17	{"id": 17, "nombre": "Nutrición", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Asesoría dietética, planes de comidas y seguimiento de objetivos"}	{"id": 17, "nombre": "Nutrición", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Asesoría dietética, planes de comidas y seguimiento de objetivos"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
233	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	18	{"id": 18, "nombre": "Rehabilitación", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Terapia física y movilización post-operatoria y de lesiones"}	{"id": 18, "nombre": "Rehabilitación", "user_id": 1, "estado_id": 9, "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Terapia física y movilización post-operatoria y de lesiones"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
234	\N	\N	\N	\N	U	train_gimnasio	categoria_servicios	19	{"id": 19, "nombre": "Masajes", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2026-02-05T10:54:25.012959", "descripcion": "Masajes terapéuticos, relajantes y deportivos"}	{"id": 19, "nombre": "Masajes", "user_id": 1, "estado_id": 8, "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2026-02-05T10:55:00.302955", "descripcion": "Masajes terapéuticos, relajantes y deportivos"}	{"updated_at": ["2026-02-05T10:54:25.012959", "2026-02-05T10:55:00.302955"]}	\N	\N	\N	2026-02-05 10:55:00.302955	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
235	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	13	{"id": 13, "nombre": "Rutina de fuerza", "user_id": 1, "estado_id": 1, "breve_desc": "Fuerza", "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2025-10-01T16:17:40.874286", "descripcion": "Programa de fuerza con pesas y máquinas", "categoria_id": 10}	{"id": 13, "nombre": "Rutina de fuerza", "user_id": 1, "estado_id": 1, "breve_desc": "Fuerza", "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Programa de fuerza con pesas y máquinas", "categoria_id": 10}	{"updated_at": ["2025-10-01T16:17:40.874286", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
236	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	14	{"id": 14, "nombre": "HIIT", "user_id": 1, "estado_id": 1, "breve_desc": "HIIT", "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2025-10-01T16:17:40.874286", "descripcion": "Entrenamiento de alta intensidad en 20-30 min", "categoria_id": 10}	{"id": 14, "nombre": "HIIT", "user_id": 1, "estado_id": 1, "breve_desc": "HIIT", "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Entrenamiento de alta intensidad en 20-30 min", "categoria_id": 10}	{"updated_at": ["2025-10-01T16:17:40.874286", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
237	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	15	{"id": 15, "nombre": "Circuitos de cardio", "user_id": 1, "estado_id": 1, "breve_desc": "Circuito", "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2025-10-01T16:17:40.874286", "descripcion": "Combina cardio y fuerza en un solo circuito", "categoria_id": 10}	{"id": 15, "nombre": "Circuitos de cardio", "user_id": 1, "estado_id": 1, "breve_desc": "Circuito", "created_at": "2025-10-01T16:17:40.874286", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Combina cardio y fuerza en un solo circuito", "categoria_id": 10}	{"updated_at": ["2025-10-01T16:17:40.874286", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
238	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	16	{"id": 16, "nombre": "Calistenia", "user_id": 1, "estado_id": 1, "breve_desc": "TRX", "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2025-10-01T16:21:23.044032", "descripcion": "Entrenamiento con bandas de suspensión", "categoria_id": 11}	{"id": 16, "nombre": "Calistenia", "user_id": 1, "estado_id": 1, "breve_desc": "TRX", "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Entrenamiento con bandas de suspensión", "categoria_id": 11}	{"updated_at": ["2025-10-01T16:21:23.044032", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
310	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	41	\N	{"id": 41, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 23}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
239	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	17	{"id": 17, "nombre": "Pesas Rusas", "user_id": 1, "estado_id": 1, "breve_desc": "Pesas", "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2025-10-01T16:21:23.044032", "descripcion": "Ejercicios funcionales con pesas rusas", "categoria_id": 11}	{"id": 17, "nombre": "Pesas Rusas", "user_id": 1, "estado_id": 1, "breve_desc": "Pesas", "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Ejercicios funcionales con pesas rusas", "categoria_id": 11}	{"updated_at": ["2025-10-01T16:21:23.044032", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
240	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	18	{"id": 18, "nombre": "Gymnastics", "user_id": 1, "estado_id": 1, "breve_desc": "Gymnastics", "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2025-10-01T16:21:23.044032", "descripcion": "Ejercicios con calistenia y movimientos dinámicos", "categoria_id": 11}	{"id": 18, "nombre": "Gymnastics", "user_id": 1, "estado_id": 1, "breve_desc": "Gymnastics", "created_at": "2025-10-01T16:21:23.044032", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Ejercicios con calistenia y movimientos dinámicos", "categoria_id": 11}	{"updated_at": ["2025-10-01T16:21:23.044032", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
241	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	19	{"id": 19, "nombre": "Yoga Restaurador", "user_id": 1, "estado_id": 1, "breve_desc": "Restaurador", "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2025-10-01T16:23:03.687842", "descripcion": "Posturas suaves y respiración consciente", "categoria_id": 12}	{"id": 19, "nombre": "Yoga Restaurador", "user_id": 1, "estado_id": 1, "breve_desc": "Restaurador", "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Posturas suaves y respiración consciente", "categoria_id": 12}	{"updated_at": ["2025-10-01T16:23:03.687842", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
242	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	20	{"id": 20, "nombre": "Vinyasa", "user_id": 1, "estado_id": 1, "breve_desc": "Vinyasa", "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2025-10-01T16:23:03.687842", "descripcion": "Secuencia fluida de posturas y movimiento", "categoria_id": 12}	{"id": 20, "nombre": "Vinyasa", "user_id": 1, "estado_id": 1, "breve_desc": "Vinyasa", "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Secuencia fluida de posturas y movimiento", "categoria_id": 12}	{"updated_at": ["2025-10-01T16:23:03.687842", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
243	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	21	{"id": 21, "nombre": "Power Yoga", "user_id": 1, "estado_id": 1, "breve_desc": "Power", "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2025-10-01T16:23:03.687842", "descripcion": "Yoga de alta intensidad", "categoria_id": 12}	{"id": 21, "nombre": "Power Yoga", "user_id": 1, "estado_id": 1, "breve_desc": "Power", "created_at": "2025-10-01T16:23:03.687842", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Yoga de alta intensidad", "categoria_id": 12}	{"updated_at": ["2025-10-01T16:23:03.687842", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
244	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	22	{"id": 22, "nombre": "Pilates Mat", "user_id": 1, "estado_id": 1, "breve_desc": "Mat", "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2025-10-01T16:24:25.957097", "descripcion": "Rutinas en colchoneta para fuerza y flexibilidad", "categoria_id": 13}	{"id": 22, "nombre": "Pilates Mat", "user_id": 1, "estado_id": 1, "breve_desc": "Mat", "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Rutinas en colchoneta para fuerza y flexibilidad", "categoria_id": 13}	{"updated_at": ["2025-10-01T16:24:25.957097", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
245	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	23	{"id": 23, "nombre": "Pilates Reformer", "user_id": 1, "estado_id": 1, "breve_desc": "Reformer", "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2025-10-01T16:24:25.957097", "descripcion": "Ejercicios con aparato Reformer", "categoria_id": 13}	{"id": 23, "nombre": "Pilates Reformer", "user_id": 1, "estado_id": 1, "breve_desc": "Reformer", "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Ejercicios con aparato Reformer", "categoria_id": 13}	{"updated_at": ["2025-10-01T16:24:25.957097", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
246	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	24	{"id": 24, "nombre": "Pilates en suspensión", "user_id": 1, "estado_id": 1, "breve_desc": "Suspensión", "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2025-10-01T16:24:25.957097", "descripcion": "Pilates en barra de suspensión", "categoria_id": 13}	{"id": 24, "nombre": "Pilates en suspensión", "user_id": 1, "estado_id": 1, "breve_desc": "Suspensión", "created_at": "2025-10-01T16:24:25.957097", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Pilates en barra de suspensión", "categoria_id": 13}	{"updated_at": ["2025-10-01T16:24:25.957097", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
247	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	25	{"id": 25, "nombre": "Spinning 45", "user_id": 1, "estado_id": 1, "breve_desc": "45", "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2025-10-01T16:25:14.062495", "descripcion": "Sesión de 45 min a ritmo moderado-intenso", "categoria_id": 14}	{"id": 25, "nombre": "Spinning 45", "user_id": 1, "estado_id": 1, "breve_desc": "45", "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Sesión de 45 min a ritmo moderado-intenso", "categoria_id": 14}	{"updated_at": ["2025-10-01T16:25:14.062495", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
269	\N	2	\N	\N	U	train_gimnasio	usuario_sedes	5	{"id": 5, "activo": true, "sede_id": 2, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 3, "es_principal": true}	{"id": 5, "activo": true, "sede_id": 2, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 3, "es_principal": true}	{}	\N	\N	\N	2026-02-05 10:55:16.777058	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
311	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	42	\N	{"id": 42, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 24}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
248	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	26	{"id": 26, "nombre": "Spinning avanzado", "user_id": 1, "estado_id": 1, "breve_desc": "Avanzado", "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2025-10-01T16:25:14.062495", "descripcion": "Intensidad progresiva con resistencia alta", "categoria_id": 14}	{"id": 26, "nombre": "Spinning avanzado", "user_id": 1, "estado_id": 1, "breve_desc": "Avanzado", "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Intensidad progresiva con resistencia alta", "categoria_id": 14}	{"updated_at": ["2025-10-01T16:25:14.062495", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
249	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	27	{"id": 27, "nombre": "Spinning + HIIT", "user_id": 1, "estado_id": 1, "breve_desc": "Mix", "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2025-10-01T16:25:14.062495", "descripcion": "Mezcla de ciclismo y entrenamientos cortos de fuerza", "categoria_id": 14}	{"id": 27, "nombre": "Spinning + HIIT", "user_id": 1, "estado_id": 1, "breve_desc": "Mix", "created_at": "2025-10-01T16:25:14.062495", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Mezcla de ciclismo y entrenamientos cortos de fuerza", "categoria_id": 14}	{"updated_at": ["2025-10-01T16:25:14.062495", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
250	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	28	{"id": 28, "nombre": "Bailoterapia Básica", "user_id": 1, "estado_id": 1, "breve_desc": "Básica", "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2025-10-01T16:27:02.947914", "descripcion": "Clases para principiantes y nivel intermedio", "categoria_id": 15}	{"id": 28, "nombre": "Bailoterapia Básica", "user_id": 1, "estado_id": 1, "breve_desc": "Básica", "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Clases para principiantes y nivel intermedio", "categoria_id": 15}	{"updated_at": ["2025-10-01T16:27:02.947914", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
251	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	29	{"id": 29, "nombre": "Zumba Cardio", "user_id": 1, "estado_id": 1, "breve_desc": "Cardio", "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2025-10-01T16:27:02.947914", "descripcion": "Sesiones de alta energía con ritmo rápido", "categoria_id": 15}	{"id": 29, "nombre": "Zumba Cardio", "user_id": 1, "estado_id": 1, "breve_desc": "Cardio", "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Sesiones de alta energía con ritmo rápido", "categoria_id": 15}	{"updated_at": ["2025-10-01T16:27:02.947914", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
252	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	30	{"id": 30, "nombre": "Bailoterapia + HIIT", "user_id": 1, "estado_id": 1, "breve_desc": "HIIT", "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2025-10-01T16:27:02.947914", "descripcion": "Mezcla de baile y entrenamientos cortos de fuerza", "categoria_id": 15}	{"id": 30, "nombre": "Bailoterapia + HIIT", "user_id": 1, "estado_id": 1, "breve_desc": "HIIT", "created_at": "2025-10-01T16:27:02.947914", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Mezcla de baile y entrenamientos cortos de fuerza", "categoria_id": 15}	{"updated_at": ["2025-10-01T16:27:02.947914", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
253	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	31	{"id": 31, "nombre": "CrossFit", "user_id": 1, "estado_id": 1, "breve_desc": "WOD", "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2025-10-01T16:27:59.24903", "descripcion": "Rutina de CrossFit (WOD – workout of the day)", "categoria_id": 16}	{"id": 31, "nombre": "CrossFit", "user_id": 1, "estado_id": 1, "breve_desc": "WOD", "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Rutina de CrossFit (WOD – workout of the day)", "categoria_id": 16}	{"updated_at": ["2025-10-01T16:27:59.24903", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
254	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	32	{"id": 32, "nombre": "CrossFit Básica", "user_id": 1, "estado_id": 1, "breve_desc": "Básica", "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2025-10-01T16:27:59.24903", "descripcion": "Entrenamiento de fuerza y cardio para principiantes", "categoria_id": 16}	{"id": 32, "nombre": "CrossFit Básica", "user_id": 1, "estado_id": 1, "breve_desc": "Básica", "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Entrenamiento de fuerza y cardio para principiantes", "categoria_id": 16}	{"updated_at": ["2025-10-01T16:27:59.24903", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
255	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	33	{"id": 33, "nombre": "CrossFit Avanzado", "user_id": 1, "estado_id": 1, "breve_desc": "Avanzado", "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2025-10-01T16:27:59.24903", "descripcion": "Rutina de alta intensidad para atletas", "categoria_id": 16}	{"id": 33, "nombre": "CrossFit Avanzado", "user_id": 1, "estado_id": 1, "breve_desc": "Avanzado", "created_at": "2025-10-01T16:27:59.24903", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Rutina de alta intensidad para atletas", "categoria_id": 16}	{"updated_at": ["2025-10-01T16:27:59.24903", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
256	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	34	{"id": 34, "nombre": "Plan de comidas", "user_id": 1, "estado_id": 1, "breve_desc": "Plan", "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2025-10-01T16:29:21.499668", "descripcion": "Plan personalizado de comidas diarias", "categoria_id": 17}	{"id": 34, "nombre": "Plan de comidas", "user_id": 1, "estado_id": 1, "breve_desc": "Plan", "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Plan personalizado de comidas diarias", "categoria_id": 17}	{"updated_at": ["2025-10-01T16:29:21.499668", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
270	\N	3	\N	\N	U	train_gimnasio	usuario_sedes	6	{"id": 6, "activo": true, "sede_id": 3, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": false}	{"id": 6, "activo": true, "sede_id": 3, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": false}	{}	\N	\N	\N	2026-02-05 10:55:16.777058	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
257	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	35	{"id": 35, "nombre": "Seguimiento nutricional", "user_id": 1, "estado_id": 1, "breve_desc": "Seguimiento", "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2025-10-01T16:29:21.499668", "descripcion": "Control de macronutrientes y calorías", "categoria_id": 17}	{"id": 35, "nombre": "Seguimiento nutricional", "user_id": 1, "estado_id": 1, "breve_desc": "Seguimiento", "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Control de macronutrientes y calorías", "categoria_id": 17}	{"updated_at": ["2025-10-01T16:29:21.499668", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
258	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	36	{"id": 36, "nombre": "Terapia nutricional", "user_id": 1, "estado_id": 1, "breve_desc": "Terapia", "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2025-10-01T16:29:21.499668", "descripcion": "Apoyo para problemas de salud (diabetes, hipertensión…)", "categoria_id": 17}	{"id": 36, "nombre": "Terapia nutricional", "user_id": 1, "estado_id": 1, "breve_desc": "Terapia", "created_at": "2025-10-01T16:29:21.499668", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Apoyo para problemas de salud (diabetes, hipertensión…)", "categoria_id": 17}	{"updated_at": ["2025-10-01T16:29:21.499668", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
259	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	37	{"id": 37, "nombre": "Rehabilitación ortopédica", "user_id": 1, "estado_id": 1, "breve_desc": "Ortopédica", "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2025-10-01T16:30:28.686279", "descripcion": "Terapia post-operatoria de articulaciones", "categoria_id": 18}	{"id": 37, "nombre": "Rehabilitación ortopédica", "user_id": 1, "estado_id": 1, "breve_desc": "Ortopédica", "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Terapia post-operatoria de articulaciones", "categoria_id": 18}	{"updated_at": ["2025-10-01T16:30:28.686279", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
260	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	38	{"id": 38, "nombre": "Terapia miofascial", "user_id": 1, "estado_id": 1, "breve_desc": "Miofascial", "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2025-10-01T16:30:28.686279", "descripcion": "Masaje y estiramientos para tejidos blandos", "categoria_id": 18}	{"id": 38, "nombre": "Terapia miofascial", "user_id": 1, "estado_id": 1, "breve_desc": "Miofascial", "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Masaje y estiramientos para tejidos blandos", "categoria_id": 18}	{"updated_at": ["2025-10-01T16:30:28.686279", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
261	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	39	{"id": 39, "nombre": "Rehabilitación cardiovascular", "user_id": 1, "estado_id": 1, "breve_desc": "Cardio", "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2025-10-01T16:30:28.686279", "descripcion": "Ejercicios para recuperar resistencia", "categoria_id": 18}	{"id": 39, "nombre": "Rehabilitación cardiovascular", "user_id": 1, "estado_id": 1, "breve_desc": "Cardio", "created_at": "2025-10-01T16:30:28.686279", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Ejercicios para recuperar resistencia", "categoria_id": 18}	{"updated_at": ["2025-10-01T16:30:28.686279", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
262	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	40	{"id": 40, "nombre": "Masaje deportivo", "user_id": 1, "estado_id": 1, "breve_desc": "Deportivo", "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2025-10-01T16:32:49.854967", "descripcion": "Masaje de tejido profundo para atletas", "categoria_id": 19}	{"id": 40, "nombre": "Masaje deportivo", "user_id": 1, "estado_id": 1, "breve_desc": "Deportivo", "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Masaje de tejido profundo para atletas", "categoria_id": 19}	{"updated_at": ["2025-10-01T16:32:49.854967", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
263	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	41	{"id": 41, "nombre": "Masaje relajante", "user_id": 1, "estado_id": 1, "breve_desc": "Relajante", "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2025-10-01T16:32:49.854967", "descripcion": "Técnicas de relajación profunda", "categoria_id": 19}	{"id": 41, "nombre": "Masaje relajante", "user_id": 1, "estado_id": 1, "breve_desc": "Relajante", "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Técnicas de relajación profunda", "categoria_id": 19}	{"updated_at": ["2025-10-01T16:32:49.854967", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
264	\N	\N	\N	\N	U	train_gimnasio	tipos_servicios	42	{"id": 42, "nombre": "Masaje con aromaterapia", "user_id": 1, "estado_id": 1, "breve_desc": null, "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2025-11-25T23:21:11", "descripcion": "Masaje con aceites esenciales", "categoria_id": 19}	{"id": 42, "nombre": "Masaje con aromaterapia", "user_id": 1, "estado_id": 1, "breve_desc": null, "created_at": "2025-10-01T16:32:49.854967", "updated_at": "2026-02-05T10:55:07.812922", "descripcion": "Masaje con aceites esenciales", "categoria_id": 19}	{"updated_at": ["2025-11-25T23:21:11", "2026-02-05T10:55:07.812922"]}	\N	\N	\N	2026-02-05 10:55:07.812922	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
265	\N	1	\N	\N	U	train_gimnasio	usuario_sedes	1	{"id": 1, "activo": true, "sede_id": 1, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": true}	{"id": 1, "activo": true, "sede_id": 1, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": true}	{}	\N	\N	\N	2026-02-05 10:55:16.777058	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
266	\N	1	\N	\N	U	train_gimnasio	usuario_sedes	2	{"id": 2, "activo": true, "sede_id": 1, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 2, "es_principal": true}	{"id": 2, "activo": true, "sede_id": 1, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 2, "es_principal": true}	{}	\N	\N	\N	2026-02-05 10:55:16.777058	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
267	\N	1	\N	\N	U	train_gimnasio	usuario_sedes	3	{"id": 3, "activo": true, "sede_id": 1, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 5, "es_principal": true}	{"id": 3, "activo": true, "sede_id": 1, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 5, "es_principal": true}	{}	\N	\N	\N	2026-02-05 10:55:16.777058	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
271	\N	2	\N	\N	U	train_gimnasio	usuario_sedes	8	{"id": 8, "activo": true, "sede_id": 2, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": false}	{"id": 8, "activo": true, "sede_id": 2, "created_at": "2025-12-26T01:38:39.808047", "usuario_id": 1, "es_principal": false}	{}	\N	\N	\N	2026-02-05 10:55:16.777058	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
272	1	\N	\N	\N	U	train_gimnasio	auth_usuarios	1	{"id": 1, "email": "admin@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 1, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "$2y$12$XPMlI5sbIayz/NjJCd.6SusC/z7HnCbzsJEjaT.znqXok7v1F5ayK", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{"id": 1, "email": "admin@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 1, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "$2y$12$XPMlI5sbIayz/NjJCd.6SusC/z7HnCbzsJEjaT.znqXok7v1F5ayK", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{}	\N	\N	\N	2026-02-05 10:55:26.275582	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
273	1	\N	\N	\N	U	train_gimnasio	auth_usuarios	2	{"id": 2, "email": "cajero@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 3, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "HASH_CAJERO_DEMO", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{"id": 2, "email": "cajero@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 3, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "HASH_CAJERO_DEMO", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{}	\N	\N	\N	2026-02-05 10:55:26.275582	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
274	1	\N	\N	\N	U	train_gimnasio	auth_usuarios	3	{"id": 3, "email": "trainer@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 4, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "HASH_TRAINER_DEMO", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{"id": 3, "email": "trainer@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 4, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "HASH_TRAINER_DEMO", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{}	\N	\N	\N	2026-02-05 10:55:26.275582	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
275	1	\N	\N	\N	U	train_gimnasio	auth_usuarios	4	{"id": 4, "email": "luis@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 5, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "HASH_CLIENTE_DEMO", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{"id": 4, "email": "luis@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 5, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "HASH_CLIENTE_DEMO", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{}	\N	\N	\N	2026-02-05 10:55:26.275582	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
276	1	\N	\N	\N	U	train_gimnasio	auth_usuarios	5	{"id": 5, "email": "juan@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 2, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "HASH_CLIENTE_DEMO", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{"id": 5, "email": "juan@revive.com", "cedula": null, "estado": "ACTIVO", "created_at": "2025-12-26T01:38:39.808047", "fecha_baja": null, "persona_id": 2, "updated_at": "2025-12-26T01:50:38.381432", "gimnasio_id": 1, "password_hash": "HASH_CLIENTE_DEMO", "created_id_user": null, "foto_perfil_url": null, "updated_id_user": null}	{}	\N	\N	\N	2026-02-05 10:55:26.275582	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
277	1	\N	\N	\N	I	train_gimnasio	auth_permisos	21	\N	{"id": 21, "activo": true, "codigo": "AUTH.USUARIOS.VER", "modulo": "AUTH", "nombre": "Ver usuarios", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Listar y ver detalle de usuarios", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
278	1	\N	\N	\N	I	train_gimnasio	auth_permisos	22	\N	{"id": 22, "activo": true, "codigo": "AUTH.USUARIOS.CREAR", "modulo": "AUTH", "nombre": "Crear usuarios", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Crear usuarios", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
279	1	\N	\N	\N	I	train_gimnasio	auth_permisos	23	\N	{"id": 23, "activo": true, "codigo": "AUTH.USUARIOS.EDITAR", "modulo": "AUTH", "nombre": "Editar usuarios", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Editar usuarios", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
280	1	\N	\N	\N	I	train_gimnasio	auth_permisos	24	\N	{"id": 24, "activo": true, "codigo": "AUTH.USUARIOS.DESACTIVAR", "modulo": "AUTH", "nombre": "Desactivar usuarios", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Dar de baja usuarios", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
281	1	\N	\N	\N	I	train_gimnasio	auth_permisos	25	\N	{"id": 25, "activo": true, "codigo": "AUTH.ROLES.VER", "modulo": "AUTH", "nombre": "Ver roles", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Listar roles", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
282	1	\N	\N	\N	I	train_gimnasio	auth_permisos	26	\N	{"id": 26, "activo": true, "codigo": "AUTH.ROLES.CREAR", "modulo": "AUTH", "nombre": "Crear roles", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Crear roles", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
283	1	\N	\N	\N	I	train_gimnasio	auth_permisos	27	\N	{"id": 27, "activo": true, "codigo": "AUTH.ROLES.EDITAR", "modulo": "AUTH", "nombre": "Editar roles", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Editar roles", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
284	1	\N	\N	\N	I	train_gimnasio	auth_permisos	28	\N	{"id": 28, "activo": true, "codigo": "AUTH.ROLES.ASIGNAR_PERMISOS", "modulo": "AUTH", "nombre": "Asignar permisos a roles", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Gestionar permisos por rol", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
285	1	\N	\N	\N	I	train_gimnasio	auth_permisos	29	\N	{"id": 29, "activo": true, "codigo": "AUTH.PERMISOS.VER", "modulo": "AUTH", "nombre": "Ver permisos", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Listar permisos", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
286	1	\N	\N	\N	I	train_gimnasio	auth_permisos	30	\N	{"id": 30, "activo": true, "codigo": "CFG.SEDES.VER", "modulo": "CFG", "nombre": "Ver sedes", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Listar sedes", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
287	1	\N	\N	\N	I	train_gimnasio	auth_permisos	31	\N	{"id": 31, "activo": true, "codigo": "CFG.SEDES.EDITAR", "modulo": "CFG", "nombre": "Editar sedes", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Crear/editar sedes", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
288	1	\N	\N	\N	I	train_gimnasio	auth_permisos	32	\N	{"id": 32, "activo": true, "codigo": "CFG.SERVICIOS.VER", "modulo": "CFG", "nombre": "Ver servicios", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Listar servicios", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
289	1	\N	\N	\N	I	train_gimnasio	auth_permisos	33	\N	{"id": 33, "activo": true, "codigo": "CFG.SERVICIOS.EDITAR", "modulo": "CFG", "nombre": "Editar servicios", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Crear/editar servicios", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
290	1	\N	\N	\N	I	train_gimnasio	auth_permisos	34	\N	{"id": 34, "activo": true, "codigo": "CFG.HORARIOS.EDITAR", "modulo": "CFG", "nombre": "Editar horarios", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Configurar horarios y capacidad", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
291	1	\N	\N	\N	I	train_gimnasio	auth_permisos	35	\N	{"id": 35, "activo": true, "codigo": "CLI.PERFIL.VER", "modulo": "CLI", "nombre": "Ver mi perfil", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Ver datos personales", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
292	1	\N	\N	\N	I	train_gimnasio	auth_permisos	36	\N	{"id": 36, "activo": true, "codigo": "CLI.PERFIL.EDITAR", "modulo": "CLI", "nombre": "Editar mi perfil", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Editar datos personales", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
293	1	\N	\N	\N	I	train_gimnasio	auth_permisos	37	\N	{"id": 37, "activo": true, "codigo": "CLI.RESERVAS.CREAR", "modulo": "CLI", "nombre": "Reservar cupo", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Crear una reserva", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
294	1	\N	\N	\N	I	train_gimnasio	auth_permisos	38	\N	{"id": 38, "activo": true, "codigo": "CLI.RESERVAS.CANCELAR", "modulo": "CLI", "nombre": "Cancelar reserva", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Cancelar su reserva", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
295	1	\N	\N	\N	I	train_gimnasio	auth_permisos	39	\N	{"id": 39, "activo": true, "codigo": "CLI.PAGOS.VER", "modulo": "CLI", "nombre": "Ver mis pagos", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Ver historial de pagos", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
296	1	\N	\N	\N	I	train_gimnasio	auth_permisos	40	\N	{"id": 40, "activo": true, "codigo": "CLI.PLANES.VER", "modulo": "CLI", "nombre": "Ver mi plan", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Ver plan de entrenamiento/nutrición", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
297	1	\N	\N	\N	I	train_gimnasio	auth_permisos	41	\N	{"id": 41, "activo": true, "codigo": "ENT.CLIENTES.VER", "modulo": "ENT", "nombre": "Ver clientes asignados", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Listar clientes/deportistas del entrenador", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
298	1	\N	\N	\N	I	train_gimnasio	auth_permisos	42	\N	{"id": 42, "activo": true, "codigo": "ENT.EVALUACIONES.CREAR", "modulo": "ENT", "nombre": "Registrar evaluación", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Crear evaluación física", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
299	1	\N	\N	\N	I	train_gimnasio	auth_permisos	43	\N	{"id": 43, "activo": true, "codigo": "ENT.PLANES.CREAR", "modulo": "ENT", "nombre": "Crear plan", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Crear/editar planes de entrenamiento", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
300	1	\N	\N	\N	I	train_gimnasio	auth_permisos	44	\N	{"id": 44, "activo": true, "codigo": "ENT.PLANES.CREAR_NUTRICION", "modulo": "ENT", "nombre": "Crear plan nutrición", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Crear/editar plan nutricional", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
301	1	\N	\N	\N	I	train_gimnasio	auth_permisos	45	\N	{"id": 45, "activo": true, "codigo": "ENT.ASISTENCIA.VER", "modulo": "ENT", "nombre": "Ver asistencia", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Consultar asistencia y reservas", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
302	1	\N	\N	\N	I	train_gimnasio	auth_permisos	46	\N	{"id": 46, "activo": true, "codigo": "CAJ.PAGOS.CREAR", "modulo": "CAJ", "nombre": "Registrar pago", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Registrar pagos/membresías", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
303	1	\N	\N	\N	I	train_gimnasio	auth_permisos	47	\N	{"id": 47, "activo": true, "codigo": "CAJ.PAGOS.ANULAR", "modulo": "CAJ", "nombre": "Anular pago", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Anular transacciones", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
304	1	\N	\N	\N	I	train_gimnasio	auth_permisos	48	\N	{"id": 48, "activo": true, "codigo": "CAJ.VENTAS.VER", "modulo": "CAJ", "nombre": "Ver ventas", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Ver reportes de ventas", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
305	1	\N	\N	\N	I	train_gimnasio	auth_permisos	49	\N	{"id": 49, "activo": true, "codigo": "REP.GENERAL.VER", "modulo": "REP", "nombre": "Ver reporte general", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Reporte general del gimnasio", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
306	1	\N	\N	\N	I	train_gimnasio	auth_permisos	50	\N	{"id": 50, "activo": true, "codigo": "REP.RESERVAS.VER", "modulo": "REP", "nombre": "Ver reporte reservas", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Reporte de reservas y cupos", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
307	1	\N	\N	\N	I	train_gimnasio	auth_permisos	51	\N	{"id": 51, "activo": true, "codigo": "REP.INGRESOS.VER", "modulo": "REP", "nombre": "Ver reporte ingresos", "created_at": "2026-02-05T11:17:01.189499", "descripcion": "Reporte de ingresos", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:17:01.189499	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
398	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 7}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
312	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	43	\N	{"id": 43, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 25}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
313	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	44	\N	{"id": 44, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 26}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
314	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	45	\N	{"id": 45, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 27}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
315	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	46	\N	{"id": 46, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 28}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
316	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	47	\N	{"id": 47, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 29}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
317	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	48	\N	{"id": 48, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 30}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
318	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	49	\N	{"id": 49, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 31}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
319	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	50	\N	{"id": 50, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 32}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
320	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	51	\N	{"id": 51, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 33}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
321	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	52	\N	{"id": 52, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 34}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
322	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	53	\N	{"id": 53, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 35}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
323	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	54	\N	{"id": 54, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 36}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
324	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	55	\N	{"id": 55, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 37}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
325	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	56	\N	{"id": 56, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 38}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
326	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	57	\N	{"id": 57, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 39}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
327	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	58	\N	{"id": 58, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 40}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
328	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	59	\N	{"id": 59, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 41}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
329	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	60	\N	{"id": 60, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 42}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
330	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	61	\N	{"id": 61, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 43}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
331	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	62	\N	{"id": 62, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 44}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
332	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	63	\N	{"id": 63, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 45}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
333	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	64	\N	{"id": 64, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 46}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
334	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	65	\N	{"id": 65, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 47}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
335	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	66	\N	{"id": 66, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 48}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
336	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	67	\N	{"id": 67, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 49}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
337	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	68	\N	{"id": 68, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 50}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
338	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	69	\N	{"id": 69, "rol_id": 1, "created_at": "2026-02-05T11:17:11.821184", "permiso_id": 51}	\N	\N	\N	\N	2026-02-05 11:17:11.821184	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
339	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	70	\N	{"id": 70, "rol_id": 2, "created_at": "2026-02-05T11:17:18.635787", "permiso_id": 46}	\N	\N	\N	\N	2026-02-05 11:17:18.635787	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
340	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	71	\N	{"id": 71, "rol_id": 2, "created_at": "2026-02-05T11:17:18.635787", "permiso_id": 47}	\N	\N	\N	\N	2026-02-05 11:17:18.635787	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
341	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	72	\N	{"id": 72, "rol_id": 2, "created_at": "2026-02-05T11:17:18.635787", "permiso_id": 48}	\N	\N	\N	\N	2026-02-05 11:17:18.635787	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
342	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	73	\N	{"id": 73, "rol_id": 2, "created_at": "2026-02-05T11:17:18.635787", "permiso_id": 51}	\N	\N	\N	\N	2026-02-05 11:17:18.635787	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
343	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	74	\N	{"id": 74, "rol_id": 3, "created_at": "2026-02-05T11:17:24.890123", "permiso_id": 41}	\N	\N	\N	\N	2026-02-05 11:17:24.890123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
344	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	75	\N	{"id": 75, "rol_id": 3, "created_at": "2026-02-05T11:17:24.890123", "permiso_id": 42}	\N	\N	\N	\N	2026-02-05 11:17:24.890123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
345	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	76	\N	{"id": 76, "rol_id": 3, "created_at": "2026-02-05T11:17:24.890123", "permiso_id": 43}	\N	\N	\N	\N	2026-02-05 11:17:24.890123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
346	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	77	\N	{"id": 77, "rol_id": 3, "created_at": "2026-02-05T11:17:24.890123", "permiso_id": 44}	\N	\N	\N	\N	2026-02-05 11:17:24.890123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
347	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	78	\N	{"id": 78, "rol_id": 3, "created_at": "2026-02-05T11:17:24.890123", "permiso_id": 45}	\N	\N	\N	\N	2026-02-05 11:17:24.890123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
348	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	79	\N	{"id": 79, "rol_id": 3, "created_at": "2026-02-05T11:17:24.890123", "permiso_id": 50}	\N	\N	\N	\N	2026-02-05 11:17:24.890123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
349	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	80	\N	{"id": 80, "rol_id": 4, "created_at": "2026-02-05T11:17:43.404789", "permiso_id": 35}	\N	\N	\N	\N	2026-02-05 11:17:43.404789	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
350	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	81	\N	{"id": 81, "rol_id": 4, "created_at": "2026-02-05T11:17:43.404789", "permiso_id": 36}	\N	\N	\N	\N	2026-02-05 11:17:43.404789	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
351	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	82	\N	{"id": 82, "rol_id": 4, "created_at": "2026-02-05T11:17:43.404789", "permiso_id": 37}	\N	\N	\N	\N	2026-02-05 11:17:43.404789	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
352	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	83	\N	{"id": 83, "rol_id": 4, "created_at": "2026-02-05T11:17:43.404789", "permiso_id": 38}	\N	\N	\N	\N	2026-02-05 11:17:43.404789	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
353	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	84	\N	{"id": 84, "rol_id": 4, "created_at": "2026-02-05T11:17:43.404789", "permiso_id": 39}	\N	\N	\N	\N	2026-02-05 11:17:43.404789	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
354	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	85	\N	{"id": 85, "rol_id": 4, "created_at": "2026-02-05T11:17:43.404789", "permiso_id": 40}	\N	\N	\N	\N	2026-02-05 11:17:43.404789	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
355	1	\N	\N	\N	I	train_gimnasio	auth_roles	10	\N	{"id": 10, "activo": true, "codigo": "DEPORTISTA", "nombre": "Deportista", "created_at": "2026-02-05T11:22:21.698361", "updated_at": "2026-02-05T11:22:21.698361", "descripcion": "Acceso a su perfil, reservas, pagos y planes", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:22:21.698361	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
356	1	\N	\N	\N	I	train_gimnasio	auth_roles	10	\N	{"id": 10, "activo": true, "codigo": "DEPORTISTA", "nombre": "Deportista", "created_at": "2026-02-05T11:22:21.698361", "updated_at": "2026-02-05T11:22:21.698361", "descripcion": "Acceso a su perfil, reservas, pagos y planes", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:22:21.698361	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
357	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	92	\N	{"id": 92, "rol_id": 10, "created_at": "2026-02-05T11:22:36.117123", "permiso_id": 35}	\N	\N	\N	\N	2026-02-05 11:22:36.117123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
358	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	93	\N	{"id": 93, "rol_id": 10, "created_at": "2026-02-05T11:22:36.117123", "permiso_id": 36}	\N	\N	\N	\N	2026-02-05 11:22:36.117123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
359	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	94	\N	{"id": 94, "rol_id": 10, "created_at": "2026-02-05T11:22:36.117123", "permiso_id": 37}	\N	\N	\N	\N	2026-02-05 11:22:36.117123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
360	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	95	\N	{"id": 95, "rol_id": 10, "created_at": "2026-02-05T11:22:36.117123", "permiso_id": 38}	\N	\N	\N	\N	2026-02-05 11:22:36.117123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
361	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	96	\N	{"id": 96, "rol_id": 10, "created_at": "2026-02-05T11:22:36.117123", "permiso_id": 39}	\N	\N	\N	\N	2026-02-05 11:22:36.117123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
362	\N	\N	\N	\N	I	train_gimnasio	auth_rol_permisos	97	\N	{"id": 97, "rol_id": 10, "created_at": "2026-02-05T11:22:36.117123", "permiso_id": 40}	\N	\N	\N	\N	2026-02-05 11:22:36.117123	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
363	\N	1	\N	\N	I	train_gimnasio	horarios_gym	1	\N	{"id": 1, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:25:17.106511", "updated_at": "2026-02-05T11:25:17.106511", "hora_cierre": "22:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 30, "tiempo_turno_min": 60, "tipo_servicio_id": 13}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
364	\N	1	\N	\N	I	train_gimnasio	horarios_gym	2	\N	{"id": 2, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:25:17.106511", "updated_at": "2026-02-05T11:25:17.106511", "hora_cierre": "14:00:00", "tipo_usuario": 4, "hora_apertura": "08:00:00", "capacidad_maxima": 25, "tiempo_turno_min": 60, "tipo_servicio_id": 13}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
365	\N	1	\N	\N	I	train_gimnasio	horarios_gym	3	\N	{"id": 3, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:25:17.106511", "updated_at": "2026-02-05T11:25:17.106511", "hora_cierre": "12:00:00", "tipo_usuario": 4, "hora_apertura": "08:00:00", "capacidad_maxima": 20, "tiempo_turno_min": 60, "tipo_servicio_id": 13}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
366	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 1}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
367	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 1}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
368	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 1}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
369	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 1}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
370	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 1}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
371	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 2}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
372	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 7, "horario_id": 3}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
373	\N	1	\N	\N	I	train_gimnasio	horarios_gym	4	\N	{"id": 4, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:25:17.106511", "updated_at": "2026-02-05T11:25:17.106511", "hora_cierre": "22:00:00", "tipo_usuario": 5, "hora_apertura": "05:00:00", "capacidad_maxima": 40, "tiempo_turno_min": 60, "tipo_servicio_id": 13}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
399	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 7}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
400	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 7}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
374	\N	1	\N	\N	I	train_gimnasio	horarios_gym	5	\N	{"id": 5, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:25:17.106511", "updated_at": "2026-02-05T11:25:17.106511", "hora_cierre": "15:00:00", "tipo_usuario": 5, "hora_apertura": "07:00:00", "capacidad_maxima": 35, "tiempo_turno_min": 60, "tipo_servicio_id": 13}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
375	\N	1	\N	\N	I	train_gimnasio	horarios_gym	6	\N	{"id": 6, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:25:17.106511", "updated_at": "2026-02-05T11:25:17.106511", "hora_cierre": "13:00:00", "tipo_usuario": 5, "hora_apertura": "07:00:00", "capacidad_maxima": 30, "tiempo_turno_min": 60, "tipo_servicio_id": 13}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
376	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 4}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
377	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 4}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
378	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 4}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
379	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 4}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
380	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 4}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
381	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 5}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
382	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 7, "horario_id": 6}	\N	\N	\N	\N	2026-02-05 11:25:17.106511	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
383	1	\N	\N	\N	I	train_gimnasio	estados	1	\N	{"id": 1, "tipo": "RESERVAS", "activo": true, "codigo": "PENDIENTE", "nombre": "Pendiente", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Reserva creada, pendiente de confirmación", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
384	1	\N	\N	\N	I	train_gimnasio	estados	2	\N	{"id": 2, "tipo": "RESERVAS", "activo": true, "codigo": "RESERVADA", "nombre": "Reservada", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Reserva registrada", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
385	1	\N	\N	\N	I	train_gimnasio	estados	3	\N	{"id": 3, "tipo": "RESERVAS", "activo": true, "codigo": "CONFIRMADA", "nombre": "Confirmada", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Reserva confirmada/validada", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
386	1	\N	\N	\N	I	train_gimnasio	estados	4	\N	{"id": 4, "tipo": "RESERVAS", "activo": true, "codigo": "ASISTIO", "nombre": "Asistió", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "El cliente asistió al turno", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
387	1	\N	\N	\N	I	train_gimnasio	estados	5	\N	{"id": 5, "tipo": "RESERVAS", "activo": true, "codigo": "NO_ASISTIO", "nombre": "No asistió", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "El cliente no asistió", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
388	1	\N	\N	\N	I	train_gimnasio	estados	6	\N	{"id": 6, "tipo": "RESERVAS", "activo": true, "codigo": "CANCELADA", "nombre": "Cancelada", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Reserva cancelada por cliente o staff", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
389	1	\N	\N	\N	I	train_gimnasio	estados	7	\N	{"id": 7, "tipo": "RESERVAS", "activo": true, "codigo": "VENCIDA", "nombre": "Vencida", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Turno pasado sin validación", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
390	1	\N	\N	\N	I	train_gimnasio	estados	8	\N	{"id": 8, "tipo": "PAGOS", "activo": true, "codigo": "PENDIENTE", "nombre": "Pendiente", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Pago pendiente", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
391	1	\N	\N	\N	I	train_gimnasio	estados	9	\N	{"id": 9, "tipo": "PAGOS", "activo": true, "codigo": "PAGADO", "nombre": "Pagado", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Pago registrado", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
392	1	\N	\N	\N	I	train_gimnasio	estados	10	\N	{"id": 10, "tipo": "PAGOS", "activo": true, "codigo": "ANULADO", "nombre": "Anulado", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Pago anulado", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
393	1	\N	\N	\N	I	train_gimnasio	estados	11	\N	{"id": 11, "tipo": "MEMBRESIAS", "activo": true, "codigo": "ACTIVA", "nombre": "Activa", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Membresía vigente", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
394	1	\N	\N	\N	I	train_gimnasio	estados	12	\N	{"id": 12, "tipo": "MEMBRESIAS", "activo": true, "codigo": "SUSPENDIDA", "nombre": "Suspendida", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Membresía suspendida", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
395	1	\N	\N	\N	I	train_gimnasio	estados	13	\N	{"id": 13, "tipo": "MEMBRESIAS", "activo": true, "codigo": "VENCIDA", "nombre": "Vencida", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Membresía vencida", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
396	1	\N	\N	\N	I	train_gimnasio	estados	14	\N	{"id": 14, "tipo": "MEMBRESIAS", "activo": true, "codigo": "CANCELADA", "nombre": "Cancelada", "created_at": "2026-02-05T11:33:54.012501", "updated_at": "2026-02-05T11:33:54.012501", "descripcion": "Membresía cancelada", "gimnasio_id": 1}	\N	\N	\N	\N	2026-02-05 11:33:54.012501	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
397	\N	1	\N	\N	I	train_gimnasio	horarios_gym	7	\N	{"id": 7, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "22:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 25, "tiempo_turno_min": 60, "tipo_servicio_id": 13}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
403	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 7}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
404	\N	1	\N	\N	I	train_gimnasio	horarios_gym	8	\N	{"id": 8, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "22:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 20, "tiempo_turno_min": 45, "tipo_servicio_id": 14}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
405	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 8}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
406	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 8}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
407	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 8}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
408	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 8}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
409	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 8}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
410	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 8}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
411	\N	1	\N	\N	I	train_gimnasio	horarios_gym	9	\N	{"id": 9, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "22:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 25, "tiempo_turno_min": 45, "tipo_servicio_id": 15}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
412	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 9}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
413	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 9}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
414	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 9}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
415	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 9}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
416	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 9}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
417	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 9}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
418	\N	1	\N	\N	I	train_gimnasio	horarios_gym	10	\N	{"id": 10, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "07:00:00", "capacidad_maxima": 18, "tiempo_turno_min": 60, "tipo_servicio_id": 16}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
419	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 10}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
420	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 10}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
421	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 10}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
422	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 10}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
423	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 10}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
424	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 10}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
425	\N	1	\N	\N	I	train_gimnasio	horarios_gym	11	\N	{"id": 11, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "07:00:00", "capacidad_maxima": 16, "tiempo_turno_min": 45, "tipo_servicio_id": 17}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
426	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 11}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
427	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 11}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
428	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 11}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
429	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 11}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
430	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 11}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
431	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 11}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
432	\N	1	\N	\N	I	train_gimnasio	horarios_gym	12	\N	{"id": 12, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "07:00:00", "capacidad_maxima": 12, "tiempo_turno_min": 60, "tipo_servicio_id": 18}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
433	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 12}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
434	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 12}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
435	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 12}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
436	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 12}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
437	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 12}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
438	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 12}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
439	\N	1	\N	\N	I	train_gimnasio	horarios_gym	13	\N	{"id": 13, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "20:30:00", "tipo_usuario": 4, "hora_apertura": "06:30:00", "capacidad_maxima": 20, "tiempo_turno_min": 60, "tipo_servicio_id": 19}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
440	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 13}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
441	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 13}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
442	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 13}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
443	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 13}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
444	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 13}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
445	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 13}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
446	\N	1	\N	\N	I	train_gimnasio	horarios_gym	14	\N	{"id": 14, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "20:30:00", "tipo_usuario": 4, "hora_apertura": "06:30:00", "capacidad_maxima": 20, "tiempo_turno_min": 60, "tipo_servicio_id": 20}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
447	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 14}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
448	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 14}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
449	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 14}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
450	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 14}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
451	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 14}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
452	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 14}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
453	\N	1	\N	\N	I	train_gimnasio	horarios_gym	15	\N	{"id": 15, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "20:30:00", "tipo_usuario": 4, "hora_apertura": "06:30:00", "capacidad_maxima": 18, "tiempo_turno_min": 60, "tipo_servicio_id": 21}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
454	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 15}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
455	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 15}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
456	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 15}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
457	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 15}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
458	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 15}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
459	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 15}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
460	\N	1	\N	\N	I	train_gimnasio	horarios_gym	16	\N	{"id": 16, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 14, "tiempo_turno_min": 60, "tipo_servicio_id": 22}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
461	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 16}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
462	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 16}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
463	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 16}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
464	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 16}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
465	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 16}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
466	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 16}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
467	\N	1	\N	\N	I	train_gimnasio	horarios_gym	17	\N	{"id": 17, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 10, "tiempo_turno_min": 60, "tipo_servicio_id": 23}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
468	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 17}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
469	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 17}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
470	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 17}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
471	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 17}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
472	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 17}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
473	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 17}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
474	\N	1	\N	\N	I	train_gimnasio	horarios_gym	18	\N	{"id": 18, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 12, "tiempo_turno_min": 60, "tipo_servicio_id": 24}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
475	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 18}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
476	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 18}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
477	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 18}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
478	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 18}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
479	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 18}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
480	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 18}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
481	\N	1	\N	\N	I	train_gimnasio	horarios_gym	19	\N	{"id": 19, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 22, "tiempo_turno_min": 45, "tipo_servicio_id": 25}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
482	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 19}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
483	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 19}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
484	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 19}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
485	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 19}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
486	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 19}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
487	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 19}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
488	\N	1	\N	\N	I	train_gimnasio	horarios_gym	20	\N	{"id": 20, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 18, "tiempo_turno_min": 45, "tipo_servicio_id": 26}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
489	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 20}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
490	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 20}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
491	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 20}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
492	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 20}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
493	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 20}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
494	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 20}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
495	\N	1	\N	\N	I	train_gimnasio	horarios_gym	21	\N	{"id": 21, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 18, "tiempo_turno_min": 45, "tipo_servicio_id": 27}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
496	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 21}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
497	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 21}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
498	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 21}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
499	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 21}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
500	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 21}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
501	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 21}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
502	\N	1	\N	\N	I	train_gimnasio	horarios_gym	22	\N	{"id": 22, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "20:00:00", "tipo_usuario": 4, "hora_apertura": "08:00:00", "capacidad_maxima": 30, "tiempo_turno_min": 60, "tipo_servicio_id": 28}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
503	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 22}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
504	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 22}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
505	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 22}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
506	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 22}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
507	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 22}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
508	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 22}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
509	\N	1	\N	\N	I	train_gimnasio	horarios_gym	23	\N	{"id": 23, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "20:00:00", "tipo_usuario": 4, "hora_apertura": "08:00:00", "capacidad_maxima": 30, "tiempo_turno_min": 60, "tipo_servicio_id": 29}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
510	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 23}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
511	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 23}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
512	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 23}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
513	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 23}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
514	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 23}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
515	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 23}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
553	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 29}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
554	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 29}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
516	\N	1	\N	\N	I	train_gimnasio	horarios_gym	24	\N	{"id": 24, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "20:00:00", "tipo_usuario": 4, "hora_apertura": "08:00:00", "capacidad_maxima": 25, "tiempo_turno_min": 60, "tipo_servicio_id": 30}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
517	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 24}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
518	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 24}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
519	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 24}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
520	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 24}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
521	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 24}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
522	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 24}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
523	\N	1	\N	\N	I	train_gimnasio	horarios_gym	25	\N	{"id": 25, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 20, "tiempo_turno_min": 60, "tipo_servicio_id": 31}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
524	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 25}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
525	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 25}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
526	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 25}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
527	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 25}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
528	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 25}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
529	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 25}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
530	\N	1	\N	\N	I	train_gimnasio	horarios_gym	26	\N	{"id": 26, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 20, "tiempo_turno_min": 60, "tipo_servicio_id": 32}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
531	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 26}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
532	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 26}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
533	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 26}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
534	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 26}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
535	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 26}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
536	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 26}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
537	\N	1	\N	\N	I	train_gimnasio	horarios_gym	27	\N	{"id": 27, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "21:00:00", "tipo_usuario": 4, "hora_apertura": "06:00:00", "capacidad_maxima": 16, "tiempo_turno_min": 60, "tipo_servicio_id": 33}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
538	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 27}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
539	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 27}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
540	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 27}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
541	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 27}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
542	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 27}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
543	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 27}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
544	\N	1	\N	\N	I	train_gimnasio	horarios_gym	28	\N	{"id": 28, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "23:59:00", "tipo_usuario": 4, "hora_apertura": "00:00:00", "capacidad_maxima": 9999, "tiempo_turno_min": 1440, "tipo_servicio_id": 34}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
545	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 28}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
546	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 28}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
547	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 28}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
548	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 28}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
549	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 28}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
550	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 28}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
551	\N	1	\N	\N	I	train_gimnasio	horarios_gym	29	\N	{"id": 29, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "23:59:00", "tipo_usuario": 4, "hora_apertura": "00:00:00", "capacidad_maxima": 9999, "tiempo_turno_min": 1440, "tipo_servicio_id": 35}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
552	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 29}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
555	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 29}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
556	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 29}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
557	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 29}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
558	\N	1	\N	\N	I	train_gimnasio	horarios_gym	30	\N	{"id": 30, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "23:59:00", "tipo_usuario": 4, "hora_apertura": "00:00:00", "capacidad_maxima": 9999, "tiempo_turno_min": 1440, "tipo_servicio_id": 36}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
559	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 30}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
560	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 30}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
561	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 30}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
562	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 30}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
563	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 30}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
564	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 30}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
565	\N	1	\N	\N	I	train_gimnasio	horarios_gym	31	\N	{"id": 31, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "19:00:00", "tipo_usuario": 4, "hora_apertura": "07:00:00", "capacidad_maxima": 10, "tiempo_turno_min": 60, "tipo_servicio_id": 37}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
566	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 31}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
567	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 31}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
568	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 31}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
569	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 31}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
570	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 31}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
571	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 31}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
572	\N	1	\N	\N	I	train_gimnasio	horarios_gym	32	\N	{"id": 32, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "19:00:00", "tipo_usuario": 4, "hora_apertura": "07:00:00", "capacidad_maxima": 10, "tiempo_turno_min": 60, "tipo_servicio_id": 38}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
573	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 32}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
574	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 32}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
575	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 32}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
576	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 32}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
577	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 32}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
578	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 32}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
579	\N	1	\N	\N	I	train_gimnasio	horarios_gym	33	\N	{"id": 33, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "19:00:00", "tipo_usuario": 4, "hora_apertura": "07:00:00", "capacidad_maxima": 10, "tiempo_turno_min": 60, "tipo_servicio_id": 39}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
580	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 33}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
581	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 33}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
582	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 33}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
583	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 33}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
584	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 33}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
585	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 33}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
586	\N	1	\N	\N	I	train_gimnasio	horarios_gym	34	\N	{"id": 34, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "19:00:00", "tipo_usuario": 4, "hora_apertura": "09:00:00", "capacidad_maxima": 6, "tiempo_turno_min": 60, "tipo_servicio_id": 40}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
587	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 34}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
588	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 34}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
589	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 34}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
590	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 34}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
591	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 34}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
592	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 34}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
593	\N	1	\N	\N	I	train_gimnasio	horarios_gym	35	\N	{"id": 35, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "19:00:00", "tipo_usuario": 4, "hora_apertura": "09:00:00", "capacidad_maxima": 6, "tiempo_turno_min": 60, "tipo_servicio_id": 41}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
594	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 35}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
595	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 35}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
596	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 35}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
597	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 35}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
598	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 35}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
599	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 35}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
600	\N	1	\N	\N	I	train_gimnasio	horarios_gym	36	\N	{"id": 36, "activo": true, "sede_id": 1, "created_at": "2026-02-05T11:35:51.357704", "updated_at": "2026-02-05T11:35:51.357704", "hora_cierre": "19:00:00", "tipo_usuario": 4, "hora_apertura": "09:00:00", "capacidad_maxima": 6, "tiempo_turno_min": 60, "tipo_servicio_id": 42}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
601	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 1, "horario_id": 36}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
602	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 2, "horario_id": 36}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
603	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 3, "horario_id": 36}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
604	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 4, "horario_id": 36}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
605	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 5, "horario_id": 36}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
606	\N	\N	\N	\N	I	train_gimnasio	horarios_gym_dias	\N	\N	{"dia_semana": 6, "horario_id": 36}	\N	\N	\N	\N	2026-02-05 11:35:51.357704	\N	\N	\N	\N	\N	\N	\N	\N	\N	::1	\N	\N	\N
\.


--
-- Data for Name: estados; Type: TABLE DATA; Schema: core; Owner: -
--

COPY core.estados (id, codigo, nombre, descripcion, activo, created_at, updated_at) FROM stdin;
1	ACTIVO	Activo	Registro activo	t	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
2	INACTIVO	Inactivo	Registro inactivo	t	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
3	SUSPENDIDO	Suspendido	Registro suspendido	t	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
\.


--
-- Data for Name: persona_tipo_detalle; Type: TABLE DATA; Schema: core; Owner: -
--

COPY core.persona_tipo_detalle (id, persona_id, tipo_id, activo, fecha_inicio, fecha_fin, created_at, updated_at) FROM stdin;
1	1	1	t	2026-05-31	\N	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
2	3	1	t	2026-05-31	\N	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
3	4	1	t	2026-05-31	\N	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
4	5	1	t	2026-05-31	\N	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
5	2	1	t	2026-05-31	\N	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
6	1	5	t	2026-05-31	\N	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
7	3	3	t	2026-05-31	\N	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
8	4	4	t	2026-05-31	\N	2026-05-31 22:42:12.632333	2026-05-31 22:42:12.632333
14	2	2	t	2026-06-30	\N	2026-06-30 02:01:39	2026-06-30 02:01:39
\.


--
-- Data for Name: persona_tipos; Type: TABLE DATA; Schema: core; Owner: -
--

COPY core.persona_tipos (id, codigo, nombre, descripcion, activo) FROM stdin;
1	CLIENTE	Cliente	Persona que compra en punto de venta	t
2	SOCIO	Socio	Persona con membresia en Revive	t
3	FUNCIONARIO	Funcionario	Personal operativo o administrativo	t
4	ENTRENADOR	Entrenador	Coach o trainer	t
5	ADMIN	Administrador	Usuario administrador del sistema	t
\.


--
-- Data for Name: personas; Type: TABLE DATA; Schema: core; Owner: -
--

COPY core.personas (id, gimnasio_id, tipo_identificacion, numero_identificacion, nombres, apellidos, fecha_nacimiento, sexo, nacionalidad, provincia, ciudad, parroquia, direccion, telefono, email, foto_url, estado_id, created_at, updated_at) FROM stdin;
4	1	CEDULA	1300000003	Ana	Trainer	1997-03-12	F	Ecuatoriana	Manabí	Manta	Uleam	Manta	096000444	trainer@revive.com	\N	1	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
2	1	CEDULA	1312345678	Juan	Pérez	2000-05-10	M	Ecuatoriana	Manabí	Manta	Los Esteros	Manta	099111999	juan@revive.com	\N	1	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
1	1	CEDULA	1311718181	Yandry Moisés	Navarrete Mendoza	1992-08-07	MASCULINO	Ecuatoriana	Manabí	Manta	Tarqui	Manta	0968076758	ynavarrete8181@gmail.com	\N	1	2025-12-26 01:38:39.808047	2026-06-23 17:38:06
3	1	CEDULA	1300000002	Karol	Cajero	1998-08-20	FEMENINO	Ecuatoriana	Manabí	Manta	Centro	Manta	097000333	karol@revive.com	\N	1	2025-12-26 01:38:39.808047	2026-06-30 01:59:00
5	1	CEDULA	1311888521	Maria Daniela	Pinargote Palma	1998-12-01	FEMENINO	Ecuatoriana	Manabí	Portoviejo	Xpadel	La Fortaleza	095000555	danielapinargote.uecn@gmail.com	http://127.0.0.1:8002/uploads/personas/20260630112338_2yonbyRo49ZO.png	1	2025-12-26 01:38:39.808047	2026-07-03 20:53:37
\.


--
-- Data for Name: sedes; Type: TABLE DATA; Schema: core; Owner: -
--

COPY core.sedes (id, gimnasio_id, nombre, direccion, telefono, activa, created_at, updated_at) FROM stdin;
1	1	Revive Home	Dirección Home	0999999999	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
2	1	Revive Xpadel	Dirección Xpadel	0988888888	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
3	1	Revive Centro	Dirección Centro	0977777777	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
\.


--
-- Data for Name: ejecuciones; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.ejecuciones (id, plan_id, rutina_id, fecha_ejecucion, estado, series_completadas, repeticiones_reales, carga_real, unidad_carga_real, rpe_real, dolor_nivel, observaciones, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: ejercicios; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.ejercicios (id, gimnasio_id, nombre, grupo_muscular, equipamiento, instrucciones, url_recurso, activo, created_at, updated_at, tipo_entrenamiento) FROM stdin;
4	\N	Patadas de glúteo	PIERNAS	POLEA	Cada variación trabaja los glúteos de forma diferente.\n\nSi haces el mismo ángulo en cada entrenamiento...\n\nNo necesitas hacerlas todas, solo necesitas las correctas.\n\n📌 La máquina de poleas es una de las mejores herramientas para aislar los glúteos, pero veo que mucha gente se siente perdida entre tantas variaciones.\n\nEntonces... ¿cuáles deberías elegir?\n\n📌 Hay docenas de variaciones más, pero el secreto no está en hacerlas todas el mismo día.\n\nEl error que comete la mayoría es no saber cuáles incluir en su rutina de entrenamiento, lo que lleva a sobrecargar las articulaciones o a entrenar siempre "de la misma manera" sin ver resultados.\n\n🍑 Desarrollar unos glúteos fuertes no se trata de\nhacer todos los ejercicios que existen, sino de saber cuáles son esenciales para tu tipo de cuerpo y tu rutina.	https://www.youtube.com/watch?v=du6Va1m4MVE	t	2026-06-12 14:00:46	2026-06-12 14:00:46	GENERAL
1	1	Sentadilla	Piernas	Barra Libre	SENTADILLAS CON BARRA MUSCULOS IMPLICADOS | CORRECTA EJECUCION DE LA SENTADILLA CON BARRA	https://www.youtube.com/watch?v=KtZsQrYAJ0Y	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Fuerza
15	1	Remo barra	Espalda	Barra Libre	Remo con barra inclinado (bent-over row) para dorsal ancho.	https://www.youtube.com/watch?v=sr_U0jBE89A	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Fuerza
16	1	Press de hombro arrodillado	Hombros	Mancuernas	Press militar unilateral en posición arrodillada.	https://www.youtube.com/watch?v=SvFJZG4us5Y	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
12	\N	Step flexiones ida y vuelta	Cardio	Cajón	Trabajo de agilidad y fuerza sobre step o cajón.	https://www.youtube.com/watch?v=m9ATbvYJgxA	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
10	1	Vuelos laterales	Hombros	Mancuernas	Elevaciones laterales para deltoides lateral.	https://www.youtube.com/watch?v=zBqZqAjCnR4	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
23	1	Habilidad de juego	Agilidad	Varios	Ejercicios de agilidad y coordinación dinámicos.	https://www.youtube.com/watch?v=gcNh17Ckjgg	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
7	1	Pecho inclinado a una mano	Pecho	Mancuernas	Press de pecho en banco inclinado de forma unilateral.	https://www.youtube.com/watch?v=tooqN12AnQU	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
18	\N	Carreras (10 mts)	Cardio	Ninguno	Sprints cortos de aceleración de 10 metros.	https://www.youtube.com/watch?v=gGfx7z24c0E	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
19	\N	Pogo jump con una pierna arriba cajón	Pliometría	Cajón	Saltos reactivos unipodales apoyados en cajón.	https://www.youtube.com/watch?v=L_khHgMz9uU	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
2	\N	Elevaciones frontales con mancuernas dumbbell front raises name	HOMBROS	MANCUERNAS	Para comenzar el movimiento debemos colocarnos de pie, con los pies ligeramente separados y mancuernas asidas con las manos en pronación o palmas hacia abajo, es decir, con el dorso de la mano mirando hacia afuera y los dedos hacia el cuerpo.\n\nLas mancuernas deben apoyarse junto a las manos sobre los muslos, ligeramente hacia los costados. Inspiramos y comenzamos a contraer los músculos para elevar un brazo hacia adelante mientras espiramos el aire.\n\nDescendemos mientras inhalamos nuevamente y elevamos el brazo contrario. Los brazos deben elevarse hasta formar con el torso un ángulo de 90 grados o hasta la altura de los ojos, no más de allí, y siempre el codo debe estar ligeramente flexionado.\n\nPuede realizarse con ambas manos juntas o como en este caso, alternando las elevaciones.	https://www.youtube.com/watch?v=jk7YrK79ciA	t	2026-06-12 13:29:38	2026-06-12 13:29:38	GENERAL
9	1	Press pecho	Pecho	Barra Libre	Press horizontal con mancuernas para desarrollo de pectoral.	https://www.youtube.com/watch?v=48L0oQApm_0	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Fuerza
26	1	Predicador	Brazos	Máquina/Banco	Curl de bíceps aislado en banco Scott/predicador.	https://www.youtube.com/watch?v=uO5OUPTT5fE	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
22	\N	Clean snatch (peso suave)	Cuerpo Completo	Barra	Movimiento olímpico para desarrollo de potencia y coordinación.	https://www.youtube.com/watch?v=EZYd4MMfHjE	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
24	\N	Bicep barra	Biceps	Barra	Curl de bíceps convencional con barra.	https://www.youtube.com/watch?v=WnDxMH-adp8	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
27	\N	Triceps polea accesorio pequeño	Triceps	Polea	Extensión de tríceps en polea con manilla/barra pequeña.	https://www.youtube.com/watch?v=CYCTsgthR2A	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
3	\N	Plancha	ABDOMEN	PESO_CORPORAL	¿Te duele la parte baja de la espalda al hacer planchas? ¡Deja de arquear la espalda! ❌ Mete el coxis para trabajar la grasa abdominal, no la columna.	https://www.youtube.com/watch?v=3AM7L2k7BEw	t	2026-06-12 13:43:08	2026-06-12 13:43:08	GENERAL
6	\N	Liga flexiones pecho	Pecho	Liga	Flexiones de pecho con resistencia de liga elástica sobre espalda.	https://www.youtube.com/watch?v=z3a2XvznN2s	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
29	\N	Fondo	Triceps	Paralelas	Fondos en paralelas para tríceps y porción inferior de pectoral.	https://www.youtube.com/watch?v=oVs-HluNKP0	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
30	\N	Pantorrilla mancuerna	Pantorrillas	Mancuerna	Elevación de talones con mancuernas para gemelos.	https://www.youtube.com/watch?v=OsQkieeI-5I	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
13	\N	Balón pliométrico colchoneta verde	Pliometría	Balón Medicinal	Pliometría con balón medicinal en colchoneta verde.	https://www.youtube.com/watch?v=7oOeFV9zO9k	t	2026-06-12 20:30:10	2026-06-16 16:40:57	DEPORTIVO
20	\N	Pogo lateral y al cajón	Pliometría	Cajón	Pogo jump lateral y subida reactiva al cajón.	https://www.youtube.com/watch?v=52r_Ul5k03g	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
21	\N	Salto lateral tipo pogo	Pliometría	Ninguno	Saltos de tobillo reactivos de forma lateral.	https://www.youtube.com/watch?v=52r_Ul5k03g	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
28	\N	Rompe cráneo	Triceps	Barra	Extensión de tríceps acostado (Skull crusher) con barra Z.	https://www.youtube.com/watch?v=gcNh17Ckjgg	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
58	1	Liga flex. pecho	Pecho	Bandas de Resistencia	\N	https://www.youtube.com/watch?v=PZuk9W6JLoc	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
45	1	Dominadas (Pull-ups)	Espalda	Peso Corporal	Agarre prono más ancho que los hombros. Tira de tu cuerpo hacia arriba hasta que la barbilla pase la barra, retrayendo las escápulas.	https://www.youtube.com/watch?v=6GWT7GLXE3c	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Muscular
48	1	Hip Thrust	Glúteos	Barra Libre	Espalda alta apoyada en el banco. Empuja la cadera hacia arriba contrayendo fuertemente los glúteos en la parte superior.	https://www.youtube.com/watch?v=vmx-4TMYNK4	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Muscular
56	1	Balón plio colchoneta negra	Cuerpo Completo	Balón Medicinal	\N	https://www.youtube.com/watch?v=gcNh17Ckjgg	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Híbrido
49	1	Salto al Cajón (Box Jump)	Piernas	Cajón Pliométrico	Posición atlética, carga energía flexionando caderas y brazos. Salta explosivamente hacia el cajón y aterriza suavemente en posición de media sentadilla.	https://www.youtube.com/watch?v=Usg0DmCPxmM	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Deportivo
41	\N	Lumbar	Core	Ninguno	Hiperextensiones para fortalecimiento de la espalda baja.	https://www.youtube.com/watch?v=u-0M2Cb-jrw	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
17	\N	Empuje balón hacia pared	Pliometría	Balón Medicinal	Lanzamientos/empujes de balón medicinal contra la pared.	https://www.youtube.com/watch?v=HKvySz49WBY	t	2026-06-12 20:30:10	2026-06-16 16:40:57	DEPORTIVO
33	\N	Pogos a 1 pie cajón con mancuernas	Pliometría	Cajón	Pogos unilaterales sobre cajón con peso libre.	https://www.youtube.com/watch?v=52r_Ul5k03g	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
35	\N	Cargada deportiva	Cuerpo Completo	Barra	Cargada de fuerza orientada al rendimiento deportivo.	https://www.youtube.com/watch?v=52r_Ul5k03g	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
60	1	Burpees	Cardio	Peso Corporal	\N	https://www.youtube.com/watch?v=Uy2nUNX38xE	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
11	\N	Burpees + 2 press hombro	Cuerpo Completo	Mancuerna	Ejercicio híbrido realizando burpee con mancuernas seguido de dos press de hombro al levantarse.	https://www.youtube.com/watch?v=2FKjyjT_msE	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
40	\N	Abdomen	Core	Ninguno	Ejercicios varios de fortalecimiento de la pared abdominal.	https://www.youtube.com/watch?v=6hW1lU0zSXw	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
8	\N	Vuelos frontales cable	Hombros	Polea	Elevaciones frontales para deltoides anterior utilizando la polea baja.	https://www.youtube.com/watch?v=F6URfzyxRSQ	t	2026-06-12 20:30:10	2026-06-16 16:40:57	HIBRIDO
42	1	Sentadilla Libre con Barra (Squat)	Piernas	Barra Libre	Pies al ancho de los hombros, flexiona la cadera y las rodillas bajando como si fueras a sentarte, manteniendo la espalda recta. Activa el core en todo momento.	https://www.youtube.com/watch?v=NHD0vH7XXgw	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Fuerza
43	1	Press de Banca Plano	Pecho	Barra Libre	Acuéstate en el banco, retrae las escápulas y baja la barra hasta tocar el esternón. Empuja con fuerza manteniendo los pies firmes en el suelo.	https://www.youtube.com/watch?v=48L0oQApm_0	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Fuerza
44	1	Peso Muerto Convencional	Espalda	Barra Libre	Flexiona cadera y rodillas para agarrar la barra. Saca pecho, contrae glúteos y espalda baja, y levanta el peso extendiendo cadera y rodillas simultáneamente.	https://www.youtube.com/watch?v=0XL4cZR2Ink	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Fuerza
46	1	Press Militar con Mancuernas	Hombros	Mancuernas	Sentado o de pie, empuja las mancuernas desde la altura de los hombros hasta la extensión completa de los brazos. Evita arquear la espalda.	https://www.youtube.com/watch?v=SPlZA3Rvts8	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Muscular
47	1	Remo con Barra	Espalda	Barra Libre	Torso inclinado a 45 grados, espalda recta. Tira de la barra hacia el abdomen contrayendo la espalda.	https://www.youtube.com/watch?v=sr_U0jBE89A	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Muscular
50	1	Clean and Jerk (Envión)	Cuerpo Completo	Barra Olímpica	Levantamiento explosivo desde el suelo hasta los hombros (clean), seguido de un empuje explosivo por encima de la cabeza (jerk).	https://www.youtube.com/watch?v=5LH4bsNgOhk	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Híbrido
51	1	Lanzamiento de Balón Medicinal	Core	Balón Medicinal	Fuerza rotacional desde la cadera. Lanza el balón contra la pared con explosividad pivotando el pie trasero.	https://www.youtube.com/watch?v=__0qBX6lRkk	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Deportivo
52	1	Curl de Bíceps Alterno	Brazos	Mancuernas	Flexiona el codo levantando la mancuerna hacia el hombro. Supina la muñeca al subir.	https://www.youtube.com/watch?v=3AdTKHAbRns	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Muscular
53	1	Extensión de Tríceps en Polea	Brazos	Polea	Codos fijos a los lados del cuerpo. Extiende los brazos completamente hacia abajo contrayendo el tríceps.	https://www.youtube.com/watch?v=FELcywKlkqE	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Muscular
54	1	Elevaciones Laterales	Hombros	Mancuernas	Levanta los brazos hacia los lados hasta que los codos estén a la altura de los hombros. Mantén una ligera flexión en los codos.	https://www.youtube.com/watch?v=zBqZqAjCnR4	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Muscular
55	1	Prensa de Piernas (Leg Press)	Piernas	Máquina	Empuja la plataforma controlando la bajada. No bloquees las rodillas al final de la extensión.	https://www.youtube.com/watch?v=CZrG20G5B1g	t	2026-06-23 22:13:24	2026-06-23 22:13:24	Muscular
34	\N	2 pie al cajón con mancuernas	Pliometría	Cajón	Saltos reactivos a dos pies sosteniendo mancuernas.	https://www.youtube.com/watch?v=1EhgPuz0YFE	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
63	1	Biceps barra	Brazos	Barra Libre	\N	https://www.youtube.com/watch?v=l-BPupny6cM	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
78	1	Carreras 10mts	Cardio	Ninguno	\N	https://www.youtube.com/watch?v=gcNh17Ckjgg	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
79	1	Pogo jump con una pierna	Piernas	Cajón/Step	\N	https://www.youtube.com/watch?v=L_khHgMz9uU	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
64	1	Tríceps copa	Brazos	Mancuernas	\N	https://www.youtube.com/watch?v=q1BO4munGqM	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
65	1	Biceps concentrado	Brazos	Mancuernas	\N	https://www.youtube.com/watch?v=-fWXrhX3hGI	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
38	\N	Balístico	Potencia	Varios	Lanzamientos y empujes balísticos para potencia.	https://www.youtube.com/watch?v=52r_Ul5k03g	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
32	\N	Pogos	Pliometría	Ninguno	Saltos reactivos utilizando la articulación del tobillo.	https://www.youtube.com/watch?v=52r_Ul5k03g	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
37	\N	Transferencia cajón medio, cajón alto	Pliometría	Cajón	Transferencia de fuerza reactiva de cajón medio a cajón alto.	https://www.youtube.com/watch?v=52r_Ul5k03g	t	2026-06-12 20:30:10	2026-06-12 20:30:10	DEPORTIVO
66	1	Triceps cabo	Brazos	Polea	\N	https://www.youtube.com/watch?v=SbYr_pGBJlU	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
80	1	Lateral y pl cajon	Piernas	Cajón/Step	\N	https://www.youtube.com/watch?v=mupTXaQ-P1c	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
57	1	Balón plio colchoneta verde	Cuerpo Completo	Balón Medicinal	\N	https://www.youtube.com/watch?v=gcNh17Ckjgg	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Híbrido
59	1	Vuelos frontales cabo	Hombros	Polea	\N	https://www.youtube.com/watch?v=ppNsZ0MORxY	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
61	1	Press hombro	Hombros	Mancuernas	\N	https://www.youtube.com/watch?v=IuR427toLXE	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
62	1	Step flex. ida y vuelta	Piernas	Cajón/Step	\N	https://www.youtube.com/watch?v=iOOnqTQWYQs	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Híbrido
67	1	Cabo desde la cabeza	Brazos	Polea	\N	https://www.youtube.com/watch?v=kGIf6UZP2nM	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
68	1	Extensión	Cuerpo	Varios	\N	https://www.youtube.com/watch?v=SguKyjZ6y9Q	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
5	\N	Balón pliométrico colchoneta negra	Pliometría	Balón Medicinal	Trabajo pliométrico y reactivo sobre colchoneta con balón medicinal.	https://www.youtube.com/watch?v=59jOiQIXWA4	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
81	1	Salto lateral tipos pogos	Piernas	Ninguno	\N	https://www.youtube.com/watch?v=dNnjPG9PTvA	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
69	1	Tijera pasos cortos con Manc.	Piernas	Mancuernas	\N	https://www.youtube.com/watch?v=xq1nJmpD0PA	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
70	1	Frances	Brazos	Barra/Mancuernas	\N	https://www.youtube.com/watch?v=x-Si_84IK74	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
71	1	Salto al cajón desde Sent.	Piernas	Cajón Pliométrico	\N	https://www.youtube.com/watch?v=8LstYuM2UoM	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
72	1	Clean squat	Cuerpo Completo	Barra/Mancuernas	\N	https://www.youtube.com/watch?v=BQTdGEuDg_4	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Híbrido
73	1	Asist. a una pierna	Piernas	Bandas/Soporte	\N	https://www.youtube.com/watch?v=B9fgiQaB0BE	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
14	1	Empuje de balón desde piso	Pecho/Core	Balón Medicinal	Empuje explosivo de balón medicinal desde el suelo.	https://www.youtube.com/watch?v=dSPVipulrKc	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
74	1	Liga remate	Cuerpo	Bandas de Resistencia	\N	https://www.youtube.com/watch?v=mBBuen0bJVw	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
75	1	Dominadas	Espalda	Peso Corporal	\N	https://www.youtube.com/watch?v=6GWT7GLXE3c	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Muscular
76	1	Liga biceps expl.	Brazos	Bandas de Resistencia	\N	https://www.youtube.com/watch?v=mDKfeoGtias	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
77	1	Lanzamiento balón a pared	Core/Hombros	Balón Medicinal	\N	https://www.youtube.com/watch?v=sUE1-aTioGo	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
82	1	Kettlebell squat jump	Piernas	Kettlebell	\N	https://www.youtube.com/watch?v=8EjbK0FhvF0	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
83	1	Salto tijera tipo chavo	Cardio	Peso Corporal	\N	https://www.youtube.com/watch?v=52r_Ul5k03g	t	2026-06-24 04:27:13	2026-06-24 04:27:13	Deportivo
31	\N	Corridas intervalos	Cardio	Ninguno	Trabajo interválico de velocidad en pista o campo.	https://www.youtube.com/watch?v=gcNh17Ckjgg	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
25	\N	Caminata de puntilla y con mancuerna biceps	Biceps	Mancuerna	Trabajo de gemelos combinando caminata sobre puntillas con curl de bíceps.	https://www.youtube.com/watch?v=gcNh17Ckjgg	t	2026-06-12 20:30:10	2026-06-12 20:30:10	HIBRIDO
\.


--
-- Data for Name: evaluaciones; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.evaluaciones (id, persona_id, tipo_evaluacion, fecha_evaluacion, resultado_resumen, observaciones, created_at, updated_at, nivel_resultado, fecha_proxima_evaluacion) FROM stdin;
1	1	DEPORTIVA	2026-06-22	Excelente progreso en RM. Sentadilla: 140kg, Peso Muerto: 160kg. Buena explosividad en saltos pliométricos.	Mantiene buena técnica en cargas altas (85%+). Se recomienda seguir enfocado en trabajo híbrido para no perder agilidad.	2026-06-24 04:08:09	2026-06-24 04:08:09	EXCELENTE	2026-07-22
3	5	DEPORTIVA	2026-06-29	Mejoro un poco de explosividad	Excelente mejora. Ya puede transferir ejercicios de pliometria con la técncia pertinente	2026-06-24 04:08:09	2026-06-29 22:10:52	BAJO	2026-07-29
\.


--
-- Data for Name: plan_asignaciones; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plan_asignaciones (id, plan_id, alcance, persona_id, nombre_grupo, fecha_inicio, fecha_fin, estado, observaciones, created_at, updated_at) FROM stdin;
2	3	INDIVIDUAL	1	\N	2026-06-23	\N	ACTIVO	\N	2026-06-23 23:56:52.129697	2026-06-23 23:56:52.129697
4	2	GRUPAL	5	Hibrido	2026-06-24	2026-06-24	ACTIVO	\N	2026-06-29 22:22:20	2026-06-29 22:22:20
5	2	GRUPAL	1	Hibrido	2026-06-24	2026-06-24	ACTIVO	\N	2026-06-29 22:22:20	2026-06-29 22:22:20
\.


--
-- Data for Name: plan_bloques; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plan_bloques (id, plan_dia_id, nombre, tipo_bloque, orden, observaciones, created_at, updated_at) FROM stdin;
19	11	Bloque 1	\N	1	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
20	11	Bloque 2	\N	2	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
21	11	Bloque 3	\N	3	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
22	11	Finisher	\N	4	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
23	12	Bloque 1	\N	1	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
24	12	Bloque 2 (Pesado)	\N	2	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
25	12	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
26	12	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
27	12	Cardio	\N	5	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
28	13	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
29	13	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
30	13	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
31	14	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
32	14	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
33	14	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
34	14	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
35	15	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
36	15	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
37	15	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
38	15	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
39	16	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
40	16	Bloque 2 (Pesado)	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
41	16	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
42	16	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
43	16	Cardio	\N	5	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
44	17	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
45	17	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
46	17	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
47	18	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
48	18	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
49	18	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
50	18	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
51	19	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
52	19	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
53	19	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
54	19	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
55	20	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
56	20	Bloque 2 (Pesado)	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
57	20	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
58	20	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
59	20	Cardio	\N	5	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
60	21	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
61	21	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
62	21	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
63	22	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
64	22	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
65	22	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
66	22	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
67	23	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
68	23	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
69	23	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
70	23	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
71	24	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
72	24	Bloque 2 (Pesado)	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
73	24	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
74	24	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
75	24	Cardio	\N	5	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
76	25	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
77	25	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
78	25	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
79	26	Bloque 1	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
80	26	Bloque 2	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
81	26	Bloque 3	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
82	26	Finisher	\N	4	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
83	27	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
84	27	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
85	27	Transferencia	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
86	28	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
87	28	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
88	28	Cardio	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
89	29	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
90	29	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
91	29	Transferencia	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
92	30	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
93	30	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
94	30	Transferencia	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
95	31	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
96	31	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
97	31	Cardio	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
98	32	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
99	32	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
100	32	Transferencia	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
101	33	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
102	33	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
103	33	Transferencia	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
104	34	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
105	34	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
106	34	Cardio	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
107	35	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
108	35	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
109	35	Transferencia	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
110	36	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
111	36	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
112	36	Transferencia	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
113	37	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
114	37	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
115	37	Cardio	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
116	38	RM Principal	\N	1	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
117	38	Accesorios	\N	2	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
118	38	Transferencia	\N	3	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
\.


--
-- Data for Name: plan_dias; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plan_dias (id, plan_id, semana, dia, nombre_sesion, observaciones, created_at, updated_at) FROM stdin;
11	2	1	LUNES	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
12	2	1	MARTES	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
13	2	1	MIERCOLES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
14	2	1	JUEVES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
15	2	2	LUNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
16	2	2	MARTES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
17	2	2	MIERCOLES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
18	2	2	JUEVES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
19	2	3	LUNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
20	2	3	MARTES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
21	2	3	MIERCOLES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
22	2	3	JUEVES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
23	2	4	LUNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
24	2	4	MARTES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
25	2	4	MIERCOLES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
26	2	4	JUEVES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
27	3	1	LUNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
28	3	1	MIERCOLES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
29	3	1	VIERNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
30	3	2	LUNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
31	3	2	MIERCOLES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
32	3	2	VIERNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
33	3	3	LUNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
34	3	3	MIERCOLES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
35	3	3	VIERNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
36	3	4	LUNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
37	3	4	MIERCOLES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
38	3	4	VIERNES	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
\.


--
-- Data for Name: plan_ejecuciones; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plan_ejecuciones (id, plan_id, plan_ejercicio_id, fecha_ejecucion, estado, series_completadas, repeticiones_reales, carga_real, unidad_carga_real, rpe_real, dolor_nivel, observaciones, created_at, updated_at) FROM stdin;
1	3	113	2026-06-24	COMPLETADO	5	[{"numero_serie":1,"reps":"3","carga":"60","completado":true},{"numero_serie":2,"reps":"5","carga":"77","completado":true},{"numero_serie":3,"reps":"5","carga":"70","completado":true},{"numero_serie":4,"reps":"5","carga":"70","completado":true},{"numero_serie":5,"reps":"5","carga":"70","completado":true}]	77.00	kg	5.0	3	Ok	2026-06-24 21:57:00	2026-06-24 21:57:00
2	3	114	2026-06-24	PARCIAL	2	[{"numero_serie":1,"reps":"8","carga":"787","completado":true},{"numero_serie":2,"reps":"8","carga":"87","completado":true}]	787.00	kg	7.0	1	Ok me senti mareado	2026-06-24 21:58:24	2026-06-24 21:58:24
\.


--
-- Data for Name: plan_ejercicio_series; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plan_ejercicio_series (id, plan_ejercicio_id, numero_serie, tipo_carga, porcentaje_rm, carga_fija, unidad_carga, repeticiones, tiempo_segundos, distancia_metros, rpe, descanso_segundos, tempo, observaciones, created_at, updated_at) FROM stdin;
1	1	1	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
2	1	2	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
3	1	3	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
4	1	4	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
5	2	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
6	2	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
7	2	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
8	2	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
9	3	1	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
10	3	2	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
11	3	3	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
12	4	1	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
13	4	2	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
14	4	3	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
15	5	1	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
16	5	2	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
17	5	3	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
18	6	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
19	6	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
20	6	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
21	7	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
22	7	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
23	7	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
24	7	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
25	8	1	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
26	8	2	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
27	8	3	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
28	9	1	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
29	9	2	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
30	9	3	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
31	9	4	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
32	10	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
33	10	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
34	10	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
35	10	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
36	11	1	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
37	11	2	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
38	11	3	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
39	12	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
40	13	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
41	13	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
42	13	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
43	14	1	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
44	14	2	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
45	14	3	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
46	15	1	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
47	15	2	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
48	15	3	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
49	15	4	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
50	15	5	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
51	15	6	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
52	15	7	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
53	15	8	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
54	15	9	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
55	15	10	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
56	16	1	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
57	16	2	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
58	16	3	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
59	16	4	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
60	17	1	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
61	17	2	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
62	17	3	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
63	17	4	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
64	18	1	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
65	18	2	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
66	18	3	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
67	19	1	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
68	19	2	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
69	19	3	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
70	19	4	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
71	20	1	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
72	20	2	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
73	20	3	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
74	21	1	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
75	21	2	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
76	21	3	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
77	21	4	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
78	22	1	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
79	22	2	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
80	22	3	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
81	22	4	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
82	23	1	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
83	23	2	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
84	23	3	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
85	23	4	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
86	24	1	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
87	24	2	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
88	24	3	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
89	24	4	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
90	25	1	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
91	25	2	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
92	25	3	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
93	25	4	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
94	26	1	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
95	26	2	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
96	26	3	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
97	26	4	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
98	27	1	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
99	27	2	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
100	27	3	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
101	27	4	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
102	28	1	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
103	28	2	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
104	28	3	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
105	28	4	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
106	29	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
107	29	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
108	29	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
109	29	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
110	30	1	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
111	30	2	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
112	30	3	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
113	31	1	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
114	31	2	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
115	31	3	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
116	32	1	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
117	32	2	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
118	32	3	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
119	33	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
120	33	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
121	33	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
122	34	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
123	34	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
124	34	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
125	34	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
126	35	1	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
127	35	2	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
128	35	3	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
129	36	1	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
130	36	2	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
131	36	3	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
132	36	4	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
133	37	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
134	37	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
135	37	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
136	37	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
137	38	1	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
138	38	2	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
139	38	3	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
140	39	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
141	40	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
142	40	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
143	40	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
144	41	1	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
145	41	2	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
146	41	3	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
147	42	1	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
148	42	2	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
149	42	3	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
150	42	4	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
151	42	5	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
152	42	6	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
153	42	7	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
154	42	8	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
155	42	9	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
156	42	10	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
157	43	1	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
158	43	2	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
159	43	3	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
160	43	4	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
161	44	1	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
162	44	2	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
163	44	3	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
164	44	4	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
165	45	1	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
166	45	2	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
167	45	3	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
168	46	1	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
169	46	2	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
170	46	3	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
171	46	4	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
172	47	1	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
173	47	2	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
174	47	3	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
175	48	1	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
176	48	2	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
177	48	3	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
178	48	4	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
179	49	1	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
180	49	2	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
181	49	3	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
182	49	4	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
183	50	1	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
184	50	2	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
185	50	3	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
186	50	4	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
187	51	1	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
188	51	2	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
189	51	3	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
190	51	4	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
191	52	1	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
192	52	2	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
193	52	3	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
194	52	4	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
195	53	1	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
196	53	2	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
197	53	3	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
198	53	4	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
199	54	1	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
200	54	2	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
201	54	3	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
202	54	4	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
203	55	1	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
204	55	2	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
205	55	3	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
206	55	4	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
207	56	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
208	56	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
209	56	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
210	56	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
211	57	1	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
212	57	2	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
213	57	3	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
214	58	1	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
215	58	2	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
216	58	3	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
217	59	1	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
218	59	2	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
219	59	3	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
220	60	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
221	60	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
222	60	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
223	61	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
224	61	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
225	61	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
226	61	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
227	62	1	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
228	62	2	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
229	62	3	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
230	63	1	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
231	63	2	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
232	63	3	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
233	63	4	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
234	64	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
235	64	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
236	64	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
237	64	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
238	65	1	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
239	65	2	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
240	65	3	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
241	66	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
242	67	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
243	67	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
244	67	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
245	68	1	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
246	68	2	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
247	68	3	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
248	69	1	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
249	69	2	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
250	69	3	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
251	69	4	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
252	69	5	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
253	69	6	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
254	69	7	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
255	69	8	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
256	69	9	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
257	69	10	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
258	70	1	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
259	70	2	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
260	70	3	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
261	70	4	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
262	71	1	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
263	71	2	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
264	71	3	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
265	71	4	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
266	72	1	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
267	72	2	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
268	72	3	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
269	73	1	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
270	73	2	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
271	73	3	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
272	73	4	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
273	74	1	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
274	74	2	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
275	74	3	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
276	75	1	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
277	75	2	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
278	75	3	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
279	75	4	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
280	76	1	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
281	76	2	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
282	76	3	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
283	76	4	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
284	77	1	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
285	77	2	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
286	77	3	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
287	77	4	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
288	78	1	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
289	78	2	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
290	78	3	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
291	78	4	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
292	79	1	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
293	79	2	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
294	79	3	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
295	79	4	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
296	80	1	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
297	80	2	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
298	80	3	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
299	80	4	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
300	81	1	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
301	81	2	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
302	81	3	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
303	81	4	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
304	82	1	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
305	82	2	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
306	82	3	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
307	82	4	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
308	83	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
309	83	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
310	83	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
311	83	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
312	84	1	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
313	84	2	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
314	84	3	LIBRE	\N	\N	\N	12 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
315	85	1	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
316	85	2	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
317	85	3	LIBRE	\N	\N	\N	14	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
318	86	1	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
319	86	2	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
320	86	3	LIBRE	\N	\N	\N	14, 12, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
321	87	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
322	87	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
323	87	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
324	88	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
325	88	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
326	88	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
327	88	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
328	89	1	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
329	89	2	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
330	89	3	LIBRE	\N	\N	\N	ida y vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
331	90	1	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
332	90	2	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
333	90	3	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
334	90	4	LIBRE	\N	\N	\N	20	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
335	91	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
336	91	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
337	91	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
338	91	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
339	92	1	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
340	92	2	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
341	92	3	LIBRE	\N	\N	\N	8, 6, 4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
342	93	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
343	94	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
344	94	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
345	94	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
346	95	1	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
347	95	2	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
348	95	3	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
349	96	1	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
350	96	2	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
351	96	3	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
352	96	4	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
353	96	5	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
354	96	6	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
355	96	7	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
356	96	8	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
357	96	9	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
358	96	10	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
359	97	1	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
360	97	2	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
361	97	3	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
362	97	4	LIBRE	\N	\N	\N	14, 12, 10, 8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
363	98	1	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
364	98	2	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
365	98	3	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
366	98	4	LIBRE	\N	\N	\N	14, 14, 10, 10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
367	99	1	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
368	99	2	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
369	99	3	LIBRE	\N	\N	\N	14, 12, fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
370	100	1	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
371	100	2	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
372	100	3	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
373	100	4	LIBRE	\N	\N	\N	12	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
374	101	1	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
375	101	2	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
376	101	3	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
377	102	1	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
378	102	2	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
379	102	3	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
380	102	4	LIBRE	\N	\N	\N	50	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
381	103	1	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
382	103	2	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
383	103	3	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
384	103	4	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
385	104	1	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
386	104	2	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
387	104	3	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
388	104	4	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
389	105	1	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
390	105	2	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
391	105	3	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
392	105	4	LIBRE	\N	\N	\N	6	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
393	106	1	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
394	106	2	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
395	106	3	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
396	106	4	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
397	107	1	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
398	107	2	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
399	107	3	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
400	107	4	LIBRE	\N	\N	\N	8 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
401	108	1	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
402	108	2	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
403	108	3	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
404	108	4	LIBRE	\N	\N	\N	6 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
405	109	1	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
406	109	2	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
407	109	3	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
408	109	4	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
409	109	5	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
410	110	1	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
411	110	2	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
412	110	3	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
413	111	1	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
414	111	2	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
415	111	3	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
416	111	4	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
417	112	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
418	112	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
419	112	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
420	113	1	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
421	113	2	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
422	113	3	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
423	113	4	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
424	113	5	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
425	114	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
426	114	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
427	114	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
428	114	4	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
429	115	1	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
430	115	2	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
431	115	3	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
432	116	1	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
433	116	2	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
434	116	3	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
435	116	4	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
436	116	5	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
437	117	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
438	117	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
439	117	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
440	117	4	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
441	118	1	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
442	118	2	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
443	118	3	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
444	118	4	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
445	119	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
446	119	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
447	119	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
448	119	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
449	120	1	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
450	120	2	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
451	120	3	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
452	120	4	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
453	120	5	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
454	121	1	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
455	121	2	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
456	121	3	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
457	122	1	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
458	122	2	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
459	122	3	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
460	122	4	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
461	123	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
462	123	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
463	123	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
464	124	1	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
465	124	2	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
466	124	3	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
467	124	4	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
468	124	5	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
469	125	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
470	125	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
471	125	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
472	125	4	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
473	126	1	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
474	126	2	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
475	126	3	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
476	127	1	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
477	127	2	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
478	127	3	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
479	127	4	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
480	127	5	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
481	128	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
482	128	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
483	128	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
484	128	4	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
485	129	1	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
486	129	2	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
487	129	3	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
488	129	4	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
489	130	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
490	130	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
491	130	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
492	130	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
493	131	1	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
494	131	2	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
495	131	3	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
496	131	4	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
497	131	5	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
498	132	1	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
499	132	2	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
500	132	3	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
501	133	1	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
502	133	2	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
503	133	3	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
504	133	4	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
505	134	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
506	134	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
507	134	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
508	135	1	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
509	135	2	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
510	135	3	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
511	135	4	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
512	135	5	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
513	136	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
514	136	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
515	136	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
516	136	4	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
517	137	1	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
518	137	2	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
519	137	3	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
520	138	1	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
521	138	2	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
522	138	3	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
523	138	4	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
524	138	5	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
525	139	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
526	139	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
527	139	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
528	139	4	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
529	140	1	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
530	140	2	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
531	140	3	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
532	140	4	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
533	141	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
534	141	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
535	141	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
536	141	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
537	142	1	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
538	142	2	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
539	142	3	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
540	142	4	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
541	142	5	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
542	143	1	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
543	143	2	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
544	143	3	LIBRE	\N	\N	\N	10 x lado	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
545	144	1	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
546	144	2	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
547	144	3	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
548	144	4	LIBRE	\N	\N	\N	15	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
549	145	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
550	145	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
551	145	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
552	146	1	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
553	146	2	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
554	146	3	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
555	146	4	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
556	146	5	LIBRE	\N	\N	\N	5	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
557	147	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
558	147	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
559	147	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
560	147	4	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
561	148	1	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
562	148	2	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
563	148	3	LIBRE	\N	\N	\N	Ida y Vuelta	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
564	149	1	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
565	149	2	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
566	149	3	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
567	149	4	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
568	149	5	LIBRE	\N	\N	\N	1 min	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
569	150	1	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
570	150	2	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
571	150	3	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
572	150	4	LIBRE	\N	\N	\N	8	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
573	151	1	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
574	151	2	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
575	151	3	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
576	151	4	LIBRE	\N	\N	\N	Fallo	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
577	152	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
578	152	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
579	152	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
580	152	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
\.


--
-- Data for Name: plan_ejercicio_transferencias; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plan_ejercicio_transferencias (id, plan_ejercicio_id, ejercicio_id, orden, modo_aplicacion, observaciones, created_at, updated_at, nombre_libre) FROM stdin;
1	7	61	1	POR_CADA_SERIE	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
2	14	75	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
3	16	64	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
4	17	66	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
5	34	61	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
6	41	75	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
7	43	64	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
8	44	66	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
9	61	61	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
10	68	75	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
11	70	64	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
12	71	66	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
13	88	61	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
14	95	75	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
15	97	64	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
16	98	66	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
17	112	61	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
18	119	64	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
19	123	61	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
20	130	64	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
21	134	61	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
22	141	64	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
23	145	61	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
24	152	64	1	POR_CADA_SERIE	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
\.


--
-- Data for Name: plan_ejercicios; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plan_ejercicios (id, plan_bloque_id, ejercicio_id, orden, lado, observaciones, usa_rm, rm_referencia, rm_registro_id, modo_prescripcion, descanso_segundos, tempo, rpe_objetivo, created_at, updated_at, nombre_libre) FROM stdin;
1	19	56	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
2	19	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
3	20	7	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
4	20	59	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
5	21	9	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
6	21	10	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
7	22	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
8	22	62	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
9	23	57	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
10	23	14	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
11	24	9	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
12	24	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13	\N
13	25	15	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
14	26	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
15	27	78	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
16	28	63	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
17	28	65	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
18	29	26	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
19	29	67	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
20	30	69	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
21	31	79	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
22	31	80	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
23	32	81	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
24	32	72	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
25	33	23	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
26	34	82	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
27	34	83	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
28	35	56	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
29	35	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
30	36	7	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
31	36	59	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
32	37	9	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
33	37	10	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
34	38	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
35	38	62	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
36	39	57	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
37	39	14	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
38	40	9	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
39	40	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
40	41	15	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
41	42	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
42	43	78	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
43	44	63	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
44	44	65	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
45	45	26	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
46	45	67	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
47	46	69	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
48	47	79	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
49	47	80	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
50	48	81	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
51	48	72	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
52	49	23	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
53	50	82	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
54	50	83	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
55	51	56	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
56	51	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
57	52	7	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
58	52	59	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
59	53	9	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
60	53	10	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
61	54	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
62	54	62	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
63	55	57	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
64	55	14	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
65	56	9	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
66	56	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
67	57	15	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
68	58	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
69	59	78	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
70	60	63	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
71	60	65	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
72	61	26	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
73	61	67	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
74	62	69	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
75	63	79	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
76	63	80	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
77	64	81	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
78	64	72	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
79	65	23	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
80	66	82	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
81	66	83	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
82	67	56	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
83	67	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
84	68	7	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
85	68	59	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
86	69	9	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
87	69	10	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
88	70	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
89	70	62	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
90	71	57	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
91	71	14	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
92	72	9	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
93	72	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
94	73	15	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
95	74	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
96	75	78	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
97	76	63	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
98	76	65	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
99	77	26	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
100	77	67	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
101	78	69	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
102	79	79	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
103	79	80	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
104	80	81	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
105	80	72	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
106	81	23	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
107	82	82	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
108	82	83	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
109	83	9	1	\N	Trabajar al 70% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
110	84	7	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
111	84	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
112	85	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
113	86	1	1	\N	Trabajar al 70% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
114	87	71	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
115	87	69	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
116	88	78	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
117	89	15	1	\N	Trabajar al 70% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
118	90	75	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
119	91	63	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
120	92	9	1	\N	Trabajar al 75% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
121	93	7	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
122	93	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
123	94	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
124	95	1	1	\N	Trabajar al 75% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
125	96	71	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
126	96	69	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
127	97	78	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
128	98	15	1	\N	Trabajar al 75% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
129	99	75	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
130	100	63	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
131	101	9	1	\N	Trabajar al 80% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
132	102	7	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
133	102	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
134	103	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
135	104	1	1	\N	Trabajar al 80% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
136	105	71	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
137	105	69	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
138	106	78	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
139	107	15	1	\N	Trabajar al 80% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
140	108	75	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
141	109	63	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
142	110	9	1	\N	Trabajar al 85% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
143	111	7	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
144	111	58	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
145	112	60	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
146	113	1	1	\N	Trabajar al 85% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
147	114	71	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
148	114	69	2	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
149	115	78	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
150	116	15	1	\N	Trabajar al 85% del RM	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
151	117	75	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
152	118	63	1	\N	\N	f	\N	\N	POR_SERIE	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14	\N
\.


--
-- Data for Name: plan_transferencia_series; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plan_transferencia_series (id, transferencia_id, numero_serie, tipo_carga, porcentaje_rm, carga_fija, unidad_carga, repeticiones, tiempo_segundos, distancia_metros, rpe, observaciones, created_at, updated_at) FROM stdin;
1	1	1	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
2	1	2	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
3	1	3	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
4	1	4	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:13	2026-06-24 04:27:13
5	2	1	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
6	2	2	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
7	2	3	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
8	3	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
9	3	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
10	3	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
11	3	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
12	4	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
13	4	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
14	4	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
15	4	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
16	5	1	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
17	5	2	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
18	5	3	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
19	5	4	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
20	6	1	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
21	6	2	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
22	6	3	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
23	7	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
24	7	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
25	7	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
26	7	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
27	8	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
28	8	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
29	8	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
30	8	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
31	9	1	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
32	9	2	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
33	9	3	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
34	9	4	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
35	10	1	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
36	10	2	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
37	10	3	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
38	11	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
39	11	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
40	11	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
41	11	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
42	12	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
43	12	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
44	12	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
45	12	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
46	13	1	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
47	13	2	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
48	13	3	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
49	13	4	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
50	14	1	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
51	14	2	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
52	14	3	LIBRE	\N	\N	\N	2	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
53	15	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
54	15	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
55	15	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
56	15	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
57	16	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
58	16	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
59	16	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
60	16	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
61	17	1	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
62	17	2	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
63	17	3	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
64	18	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
65	18	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
66	18	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
67	18	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
68	19	1	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
69	19	2	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
70	19	3	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
71	20	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
72	20	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
73	20	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
74	20	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
75	21	1	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
76	21	2	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
77	21	3	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
78	22	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
79	22	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
80	22	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
81	22	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
82	23	1	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
83	23	2	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
84	23	3	LIBRE	\N	\N	\N	4	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
85	24	1	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
86	24	2	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
87	24	3	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
88	24	4	LIBRE	\N	\N	\N	10	\N	\N	\N	\N	2026-06-24 04:27:14	2026-06-24 04:27:14
\.


--
-- Data for Name: planes; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.planes (id, persona_id, nombre, objetivo, fecha_inicio, fecha_fin, estado, observaciones, created_at, updated_at, tipo, estructura, alcance) FROM stdin;
2	\N	Plan Muscular Híbrido (Grupal)	Mejorar fuerza hipertrófica combinada con resistencia metabólica para grupos grandes.	2026-06-23	2026-09-23	ACTIVO	Plan diseñado para clases grupales.	2026-09-23 22:14:21	2026-09-23 22:14:21	HIBRIDO	SEMANAL	GRUPAL
3	1	Plan de Fuerza por RM (Yandry Navarrete)	Aumento de 1RM en ejercicios básicos (Sentadilla, Banca, Peso Muerto).	2026-09-23	2026-11-23	ACTIVO	Programa de progresión lineal basado en porcentajes de Repetición Máxima (RM).	2026-11-23 22:14:21	2026-11-23 22:14:21	FUERZA	SEMANAL	INDIVIDUAL
\.


--
-- Data for Name: plantilla_semana_bloques; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plantilla_semana_bloques (id, plantilla_dia_id, nombre, tipo_bloque, orden, observaciones, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: plantilla_semana_dias; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plantilla_semana_dias (id, plantilla_id, orden_dia, dia, nombre_sesion, observaciones, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: plantilla_semana_ejercicio_series; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plantilla_semana_ejercicio_series (id, plantilla_ejercicio_id, numero_serie, tipo_carga, porcentaje_rm, carga_fija, unidad_carga, repeticiones, tiempo_segundos, distancia_metros, rpe, descanso_segundos, tempo, observaciones, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: plantilla_semana_ejercicio_transferencias; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plantilla_semana_ejercicio_transferencias (id, plantilla_ejercicio_id, ejercicio_id, nombre_libre, orden, modo_aplicacion, observaciones, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: plantilla_semana_ejercicios; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plantilla_semana_ejercicios (id, plantilla_bloque_id, ejercicio_id, nombre_libre, orden, lado, observaciones, usa_rm, modo_prescripcion, descanso_segundos, tempo, rpe_objetivo, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: plantilla_semana_transferencia_series; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plantilla_semana_transferencia_series (id, transferencia_id, numero_serie, tipo_carga, porcentaje_rm, carga_fija, unidad_carga, repeticiones, tiempo_segundos, distancia_metros, rpe, observaciones, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: plantillas_semanales; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.plantillas_semanales (id, nombre, objetivo, disciplina, total_dias, activa, observaciones, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: rm_registros; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.rm_registros (id, persona_id, ejercicio_id, tipo_registro, peso, repeticiones, rm_estimado, fecha_registro, observaciones, created_at, updated_at, fecha_proximo_control) FROM stdin;
1	1	42	DIRECTO	140.00	1	140.00	2026-06-22	Buen bloqueo al final. Subida controlada.	2026-06-24 04:12:30	2026-06-24 04:12:30	2026-07-22
2	1	9	ESTIMADO	100.00	5	116.67	2026-06-20	Hombros estables. Podía dar una repetición más.	2026-06-24 04:12:30	2026-06-24 04:12:30	2026-07-20
\.


--
-- Data for Name: rutina_plantilla_detalles; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.rutina_plantilla_detalles (id, plantilla_id, dia, bloque, ejercicio_id, series, repeticiones, carga_objetivo, tipo_carga, unidad_objetivo, tempo, rpe, descanso_segundos, orden, notas, created_at, updated_at, bloque_orden, ejercicio_transferencia_id, repeticiones_transferencia, series_detalles) FROM stdin;
\.


--
-- Data for Name: rutina_plantillas; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.rutina_plantillas (id, nombre, objetivo, descripcion, activa, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: rutinas; Type: TABLE DATA; Schema: entrenamiento; Owner: -
--

COPY entrenamiento.rutinas (id, plan_id, semana, dia, bloque, ejercicio_id, series, repeticiones, carga_objetivo, tipo_carga, descanso_segundos, notas, created_at, updated_at, unidad_objetivo, tempo, rpe, orden, bloque_orden, ejercicio_transferencia_id, repeticiones_transferencia, series_detalles) FROM stdin;
\.


--
-- Data for Name: categorias_producto; Type: TABLE DATA; Schema: inventario; Owner: -
--

COPY inventario.categorias_producto (id, nombre, descripcion, estado, created_at, updated_at) FROM stdin;
12	Suplementos Deportivos	Proteínas, creatinas, pre-entrenos, etc.	1	2026-06-24 14:58:56	2026-06-24 14:58:56
13	Bebidas e Hidratación	Aguas, isotónicos, energizantes.	1	2026-06-24 14:58:56	2026-06-24 14:58:56
14	Accesorios	Toallas, shakers, guantes, straps.	1	2026-06-24 14:58:56	2026-06-24 14:58:56
\.


--
-- Data for Name: movimientos_inventario; Type: TABLE DATA; Schema: inventario; Owner: -
--

COPY inventario.movimientos_inventario (id, producto_id, sede_id, lote_id, tipo_movimiento, motivo, cantidad, stock_anterior, stock_nuevo, costo_unitario, precio_unitario, referencia_tipo, referencia_id, observacion, created_by, created_at) FROM stdin;
35	31	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
36	31	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
37	31	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
38	32	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
39	32	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
40	32	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
41	36	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
42	36	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
43	36	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
44	39	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
45	39	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
46	39	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
47	40	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
48	40	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
49	40	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
50	41	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
51	41	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
52	41	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
53	33	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
54	33	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
55	33	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
56	42	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
57	42	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
58	42	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
59	34	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
60	34	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
61	34	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
62	35	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
63	35	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
64	35	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
65	37	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
66	37	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
67	37	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
68	38	1	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
69	38	2	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
70	38	3	\N	ENTRADA	INVENTARIO_INICIAL	50.00	0.00	50.00	\N	\N	INVENTARIO_INICIAL	\N	Stock inicial cargado para ejercicio base	1	2026-06-28 21:49:04.772947
71	34	3	\N	SALIDA	VENTA	1.00	50.00	49.00	\N	25.00	VENTA_POS	\N	Venta POS Revive | Ref: POS-REVIVE-1782784899661-CON | Pago: PENDIENTE	2	2026-06-30 02:01:39
72	36	3	\N	SALIDA	VENTA	1.00	50.00	49.00	\N	1.50	VENTA_POS	\N	Venta POS Revive | Ref: POS-REVIVE-1782784899661-CON | Pago: PENDIENTE	2	2026-06-30 02:01:39
\.


--
-- Data for Name: producto_lotes; Type: TABLE DATA; Schema: inventario; Owner: -
--

COPY inventario.producto_lotes (id, producto_id, sede_id, codigo_lote, fecha_elaboracion, fecha_vencimiento, stock_actual, estado, created_by, updated_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: producto_precios; Type: TABLE DATA; Schema: inventario; Owner: -
--

COPY inventario.producto_precios (id, producto_id, sede_id, tipo_precio, moneda, monto, vigencia_inicio, vigencia_fin, estado, created_by, updated_by, created_at, updated_at) FROM stdin;
56	31	\N	VENTA	USD	85.00	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
57	32	\N	VENTA	USD	35.00	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
58	33	\N	VENTA	USD	30.00	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
59	34	\N	VENTA	USD	25.00	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
60	35	\N	VENTA	USD	1.00	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
61	36	\N	VENTA	USD	1.50	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
62	37	\N	VENTA	USD	3.00	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
63	38	\N	VENTA	USD	10.00	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
64	39	\N	VENTA	USD	12.00	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
65	40	\N	VENTA	USD	1.50	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
66	41	\N	VENTA	USD	2.00	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
67	42	\N	VENTA	USD	3.50	2026-06-24 14:58:56	\N	1	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
\.


--
-- Data for Name: producto_stock_sede; Type: TABLE DATA; Schema: inventario; Owner: -
--

COPY inventario.producto_stock_sede (id, producto_id, sede_id, stock_actual, stock_reservado, stock_disponible, stock_minimo, ubicacion, estado, created_by, updated_by, created_at, updated_at) FROM stdin;
82	37	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
83	37	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
84	38	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
85	38	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
51	31	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
52	31	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
53	31	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
54	32	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
55	32	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
56	32	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
57	36	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
58	36	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
60	39	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
61	39	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
62	39	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
63	40	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
64	40	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
65	40	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
66	41	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
67	41	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
68	41	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
69	33	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
70	33	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
71	33	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
72	42	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
73	42	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
74	42	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
75	34	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
76	34	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
78	35	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
79	35	2	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
80	35	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
81	37	1	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
86	38	3	50.00	0.00	50.00	5.00	Inventario inicial	1	1	1	2026-06-28 21:49:04.772947	2026-06-28 21:49:04.772947
77	34	3	49.00	0.00	49.00	5.00	Inventario inicial	1	1	2	2026-06-28 21:49:04.772947	2026-06-30 02:01:39
59	36	3	49.00	0.00	49.00	5.00	Inventario inicial	1	1	2	2026-06-28 21:49:04.772947	2026-06-30 02:01:39
\.


--
-- Data for Name: productos; Type: TABLE DATA; Schema: inventario; Owner: -
--

COPY inventario.productos (id, codigo, nombre, descripcion, categoria_id, marca, modelo, sku, codigo_barras, unidad_medida, controla_stock, permite_decimales, maneja_lotes, maneja_vencimiento, stock_minimo, stock_maximo, estado, imagen_url, created_by, updated_by, created_at, updated_at) FROM stdin;
31	PROT-001	100% Whey Protein Gold Standard - 5 lbs	\N	12	Optimum Nutrition	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.unsplash.com/photo-1593095948071-474c5cc2989d?q=80&w=600&auto=format&fit=crop	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
32	PREW-001	C4 Original Pre-Workout - 30 serv	\N	12	Cellucor	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.unsplash.com/photo-1579722820308-d74e571900a9?q=80&w=600&auto=format&fit=crop	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
36	GATO-001	Gatorade - 750ml	\N	13	Gatorade	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.unsplash.com/photo-1622543925917-763c34d1a86e?q=80&w=600&auto=format&fit=crop	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
39	SHAK-001	Shaker / Mezclador SmartShake - 600ml	\N	14	SmartShake	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.unsplash.com/photo-1594882645126-14020914d58d?q=80&w=600&auto=format&fit=crop	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
40	BEB-001	Imperial Cola	\N	13	Imperial	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.unsplash.com/photo-1622483767028-3f66f32aef97?q=80&w=600&auto=format&fit=crop	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
41	BEB-002	Jugo Natural	\N	13	Genérico	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.unsplash.com/photo-1600271886742-f049cd451bba?q=80&w=600&auto=format&fit=crop	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
33	BCAA-001	BCAA Amino X - 30 serv	\N	12	BSN	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.pexels.com/photos/556828/pexels-photo-556828.jpeg?auto=compress&cs=tinysrgb&w=600	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
42	BEB-003	Michelada Light	\N	13	Genérico	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.pexels.com/photos/1283219/pexels-photo-1283219.jpeg?auto=compress&cs=tinysrgb&w=600	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
34	CREA-001	Creatina Monohidratada Platinum - 400g	\N	12	Muscletech	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.pexels.com/photos/1435904/pexels-photo-1435904.jpeg?auto=compress&cs=tinysrgb&w=600	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
35	AGUA-001	Agua Mineral Vital - 500ml	\N	13	Vital	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.pexels.com/photos/4000088/pexels-photo-4000088.jpeg?auto=compress&cs=tinysrgb&w=600	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
37	QBAR-001	Barra de Proteína Quest Bar	\N	12	Quest	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.pexels.com/photos/7311029/pexels-photo-7311029.jpeg?auto=compress&cs=tinysrgb&w=600	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
38	TOAL-001	Toalla Deportiva Microfibra	\N	14	Revive Gym	\N	\N	\N	UND	t	f	f	f	5.00	100.00	1	https://images.pexels.com/photos/4108819/pexels-photo-4108819.jpeg?auto=compress&cs=tinysrgb&w=600	1	\N	2026-06-24 14:58:56	2026-06-24 14:58:56
\.


--
-- Data for Name: proveedores; Type: TABLE DATA; Schema: inventario; Owner: -
--

COPY inventario.proveedores (prov_id, prov_ruc, prov_nombre, prov_direccion, prov_telefono, prov_correo, prov_id_usuario, created_at, updated_at, prov_estado) FROM stdin;
1	0992334411001	Distribuidora Global S.A.	Av. Principal 123	0987654321	ventas@global.com	\N	2026-05-31 05:46:31-05	2026-05-31 05:46:31-05	1
2	1790011223001	Importadora del Norte	Calle Secundaria 456	0999999999	info@importadora.com	\N	2026-05-31 05:46:31-05	2026-05-31 05:46:31-05	1
\.


--
-- Data for Name: transferencia_detalle; Type: TABLE DATA; Schema: inventario; Owner: -
--

COPY inventario.transferencia_detalle (id, transferencia_id, producto_id, cantidad, created_at) FROM stdin;
\.


--
-- Data for Name: transferencias_inventario; Type: TABLE DATA; Schema: inventario; Owner: -
--

COPY inventario.transferencias_inventario (id, sede_origen_id, sede_destino_id, estado, fecha_solicitud, fecha_envio, fecha_recepcion, observacion, created_by, updated_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cache (key, value, expiration) FROM stdin;
train-gym-backend-cache-5c785c036466adea360111aa28563bfd556b5fba:timer	i:1780205393;	1780205393
train-gym-backend-cache-5c785c036466adea360111aa28563bfd556b5fba	i:1;	1780205393
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: catalogos; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.catalogos (id, grupo, codigo, nombre, valor_adicional, activo, created_at, updated_at) FROM stdin;
1	ESTADO_REGISTRO	ACTIVO	Activo	🟢	t	2026-06-12 16:43:29	2026-06-12 16:43:29
2	ESTADO_REGISTRO	INACTIVO	Inactivo	🔴	t	2026-06-12 16:43:29	2026-06-12 16:43:29
3	ESTADO_REGISTRO	CANCELADO	Cancelado	⚫	t	2026-06-12 16:43:29	2026-06-12 16:43:29
4	ESTADO_REGISTRO	SUSPENDIDO	Suspendido	🟡	t	2026-06-12 16:43:29	2026-06-12 16:43:29
5	ESTADO_PAGO	PAGADO	Pagado	🟢	t	2026-06-12 16:43:29	2026-06-12 16:43:29
6	ESTADO_PAGO	PENDIENTE	Pendiente	🟡	t	2026-06-12 16:43:29	2026-06-12 16:43:29
7	ESTADO_PAGO	VENCIDO	Vencido	🔴	t	2026-06-12 16:43:29	2026-06-12 16:43:29
8	ESTADO_PAGO	ANULADO	Anulado	⚫	t	2026-06-12 16:43:29	2026-06-12 16:43:29
9	METODO_PAGO	EFECTIVO	Efectivo	💵	t	2026-06-12 16:43:29	2026-06-12 16:43:29
10	METODO_PAGO	TRANSFERENCIA	Transferencia	📱	t	2026-06-12 16:43:29	2026-06-12 16:43:29
11	METODO_PAGO	TARJETA	Tarjeta Crédito/Débito	💳	t	2026-06-12 16:43:29	2026-06-12 16:43:29
12	METODO_PAGO	CHEQUE	Cheque	✍️	t	2026-06-12 16:43:29	2026-06-12 16:43:29
13	NIVEL_RENDIMIENTO	BAJO	Bajo	🔴	t	2026-06-12 16:43:29	2026-06-12 16:43:29
14	NIVEL_RENDIMIENTO	MEDIO	Medio	🟡	t	2026-06-12 16:43:29	2026-06-12 16:43:29
15	NIVEL_RENDIMIENTO	ALTO	Alto	🟢	t	2026-06-12 16:43:29	2026-06-12 16:43:29
16	NIVEL_RENDIMIENTO	EXCELENTE	Excelente	🏆	t	2026-06-12 16:43:29	2026-06-12 16:43:29
17	NIVEL_RENDIMIENTO	MEJORO_TECNICA	Mejoró Técnica	💪	t	2026-06-12 16:43:29	2026-06-12 16:43:29
18	TIPO_EVALUACION	CORPORAL	Corporal	⚖️	t	2026-06-12 16:43:29	2026-06-12 16:43:29
19	TIPO_EVALUACION	FUNCIONAL	Funcional	🏃	t	2026-06-12 16:43:29	2026-06-12 16:43:29
20	TIPO_EVALUACION	MOVILIDAD	Movilidad	🧘	t	2026-06-12 16:43:29	2026-06-12 16:43:29
21	TIPO_EVALUACION	DEPORTIVA	Deportiva	⚽	t	2026-06-12 16:43:29	2026-06-12 16:43:29
22	TIPO_EVALUACION	REHABILITACION	Rehabilitación	🩹	t	2026-06-12 16:43:29	2026-06-12 16:43:29
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_02_04_154345_create_personal_access_tokens_table	1
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: personal_access_tokens; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.personal_access_tokens (id, tokenable_type, tokenable_id, name, token, abilities, last_used_at, expires_at, created_at, updated_at) FROM stdin;
1	App\\Models\\AuthUsuario	1	train-gym-web	e053273176c50621c1f730dc7a8c26d97a28fb6059df0ad77c67d5cbf8da8815	["*"]	2026-02-04 16:40:59	\N	2026-02-04 16:36:03	2026-02-04 16:40:59
2	App\\Models\\AuthUsuario	1	train-gym-web	70323deca1f4443a28cd011ddd2b42fe110b9f68028c405ddf8c3b36fb324871	["*"]	\N	\N	2026-02-04 18:09:49	2026-02-04 18:09:49
12	App\\Models\\AuthUsuario	1	train-gym-web	bb02693a32b4dedc8b898bb72461aa8511f73c3348488e858f11672349a7c015	["*"]	2026-05-26 11:15:48	\N	2026-05-18 05:30:37	2026-05-26 11:15:48
14	App\\Models\\AuthUsuario	1	train-gym-web	869407859da6a4f3efdf26c7f1db08f8e63064ca7cb043143e6955519d231605	["*"]	2026-05-31 05:33:00	\N	2026-05-31 05:28:53	2026-05-31 05:33:00
11	App\\Models\\AuthUsuario	1	train-gym-web	61e07efe88542c17837b41325d1c45f6cfa852571db65489e73615c6ed331943	["*"]	2026-05-11 13:01:19	\N	2026-05-01 07:30:45	2026-05-11 13:01:19
6	App\\Models\\AuthUsuario	1	train-gym-web	cdd224c1fd7edf51dbd341b2eadd0c93d6514609d1d4b36312134ddfdcad7e62	["*"]	2026-02-06 13:47:28	\N	2026-02-06 13:19:44	2026-02-06 13:47:28
9	App\\Models\\AuthUsuario	1	train-gym-web	bde618604b44ab948e819c5a6cace02d4501fae5e74c0d022fabf0b7bc17d33c	["*"]	2026-04-21 15:40:17	\N	2026-04-18 14:41:08	2026-04-21 15:40:17
7	App\\Models\\AuthUsuario	1	train-gym-web	9618cff93e1481c4ef93094900c119eeb311c14f814d406b58704e0658418506	["*"]	2026-02-10 14:32:47	\N	2026-02-09 13:48:52	2026-02-10 14:32:47
10	App\\Models\\AuthUsuario	1	train-gym-web	be39f6b7c282cbc22e88ea7880d930ecec8a68243334c411af623f18a093ba6d	["*"]	2026-04-23 19:05:30	\N	2026-04-22 03:49:44	2026-04-23 19:05:30
13	App\\Models\\AuthUsuario	1	train-gym-web	0a0ac50655308c1f58d58d07d3558de04204b1b41b0d1d37d6e1124ee5ed2a9a	["*"]	2026-05-31 05:19:59	\N	2026-05-31 03:12:56	2026-05-31 05:19:59
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: catalogo_patologias; Type: TABLE DATA; Schema: salud; Owner: -
--

COPY salud.catalogo_patologias (id, nombre, descripcion, activa, created_at, updated_at) FROM stdin;
1	Dolor lumbar	Molestia lumbar leve	t	2026-06-01 03:44:15	2026-06-01 04:16:42
2	Rodilla sensible	Requiere seguimiento en tren inferior	t	2026-06-01 03:44:15	2026-06-01 04:16:42
\.


--
-- Data for Name: ficha_mediciones; Type: TABLE DATA; Schema: salud; Owner: -
--

COPY salud.ficha_mediciones (id, ficha_tecnica_id, peso_kg, talla_cm, imc, cintura_cm, grasa_corporal_pct, masa_magra_kg, created_at) FROM stdin;
7	6	78.50	175.00	25.63	82.00	12.50	68.68	2026-06-24 03:45:46
9	8	63.00	159.00	24.92	80.00	14.00	54.18	2026-06-24 03:45:46
\.


--
-- Data for Name: ficha_patologias; Type: TABLE DATA; Schema: salud; Owner: -
--

COPY salud.ficha_patologias (id, ficha_tecnica_id, patologia_id, detalle, created_at) FROM stdin;
\.


--
-- Data for Name: fichas_tecnicas; Type: TABLE DATA; Schema: salud; Owner: -
--

COPY salud.fichas_tecnicas (id, persona_id, fecha_ficha, actividad_fisica, objetivo, observaciones, registrado_por, sede_id, created_at, updated_at) FROM stdin;
6	1	2026-06-24	Intenso	Aumento de fuerza e hipertrofia muscular. Mejorar RM en básicos.	Deportista avanzado. Sin lesiones recientes. Plan enfocado en fuerza e híbrido.	\N	\N	2026-06-24 03:45:46	2026-06-24 03:59:27
8	5	2026-06-22	Moderado	Mejorar la rigidez y flexibilidad del cuerpo para poder ser mas explosiva	ok	\N	\N	2026-06-24 03:45:46	2026-06-29 22:56:26
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: seguridad; Owner: -
--

COPY seguridad.roles (id, gimnasio_id, codigo, nombre, descripcion, activo, created_at, updated_at) FROM stdin;
1	1	ADMIN	Administrador	Acceso total	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
2	1	CAJERO	Cajero	Ventas y pagos	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
3	1	ENTRENADOR	Entrenador	Gestión de clientes y evaluaciones	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
4	1	CLIENTE	Cliente	Acceso a su perfil	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
10	1	DEPORTISTA	Deportista	Acceso a su perfil, reservas, pagos y planes	t	2026-02-05 11:22:21.698361	2026-02-05 11:22:21.698361
\.


--
-- Data for Name: usuario_roles; Type: TABLE DATA; Schema: seguridad; Owner: -
--

COPY seguridad.usuario_roles (id, usuario_id, rol_id, created_at) FROM stdin;
3	5	4	2025-12-26 01:38:39.808047
5	3	3	2025-12-26 01:38:39.808047
6	1	1	2026-06-23 17:36:30
10	2	2	2026-06-29 21:28:13
11	4	4	2026-06-29 21:52:53
\.


--
-- Data for Name: usuario_sedes; Type: TABLE DATA; Schema: seguridad; Owner: -
--

COPY seguridad.usuario_sedes (id, usuario_id, sede_id, activo, created_at, updated_at) FROM stdin;
1	3	1	t	2026-06-29 21:14:32.388813	2026-06-29 21:14:32.388813
2	3	3	t	2026-06-29 21:14:32.388813	2026-06-29 21:14:32.388813
4	1	3	t	2026-06-29 21:14:32.388813	2026-06-29 21:14:32.388813
6	5	3	t	2026-06-29 21:14:32.388813	2026-06-29 21:14:32.388813
7	5	1	t	2026-06-29 21:14:32.388813	2026-06-29 21:14:32.388813
9	1	1	t	2026-06-29 21:14:32.388813	2026-06-29 21:14:32.388813
10	5	2	t	2026-06-29 21:14:32.388813	2026-06-29 21:14:32.388813
12	1	2	t	2026-06-29 21:14:32.388813	2026-06-29 21:14:32.388813
15	3	2	t	2026-06-29 21:14:32.388813	2026-06-29 21:14:32.388813
19	2	2	t	2026-06-29 21:28:13	2026-06-29 21:28:13
20	2	3	t	2026-06-29 21:28:13	2026-06-29 21:28:13
21	4	3	t	2026-06-29 21:52:53	2026-06-29 21:52:53
22	4	1	t	2026-06-29 21:52:53	2026-06-29 21:52:53
23	4	2	t	2026-06-29 21:52:53	2026-06-29 21:52:53
\.


--
-- Data for Name: usuarios; Type: TABLE DATA; Schema: seguridad; Owner: -
--

COPY seguridad.usuarios (id, gimnasio_id, persona_id, email, password_hash, estado, fecha_baja, foto_perfil_url, created_id_user, updated_id_user, created_at, updated_at, cedula) FROM stdin;
3	1	4	trainer@revive.com	42y12J0ed6o/JItsAFWn2Vsln2uFh5nM4U1Me6EAs4qCf0GD8n/EovihnO	ACTIVO	\N	\N	\N	\N	2025-12-26 01:38:39.808047	2026-06-22 23:24:57.900507	1300000003
5	1	2	juan@revive.com	42y12J0ed6o/JItsAFWn2Vsln2uFh5nM4U1Me6EAs4qCf0GD8n/EovihnO	ACTIVO	\N	\N	\N	\N	2025-12-26 01:38:39.808047	2026-06-22 23:24:57.900507	1312345678
1	1	1	ynavarrete8181@gmail.com	$2y$12$ueGsW9mdMBmkz/K86Y8Es.7Nw5RCupLrlgCyIRKoRQXuvusZjIsPu	ACTIVO	\N	\N	\N	1	2025-12-26 01:38:39.808047	2026-06-23 17:36:30	13117181181
2	1	3	karol@revive.com	$2y$12$D93U9bw6KFYybOTB9Yo/RuAjtg7qU3cyxmb/VhkGzWm2Vm6KQGC66	ACTIVO	\N	\N	\N	2	2025-12-26 01:38:39.808047	2026-06-29 21:28:13	1300000002
4	1	5	danielapinargote.uecn@gmail.com	$2y$12$BK7TO4INdSdsVVy8x42uC.J5CJXaHOS4r.MXNzF5oy1UOQyB8Ow3e	ACTIVO	\N	\N	\N	1	2025-12-26 01:38:39.808047	2026-06-29 21:52:53	1311888521
\.


--
-- Data for Name: membresia_precios_sede; Type: TABLE DATA; Schema: socios; Owner: -
--

COPY socios.membresia_precios_sede (id, membresia_id, sede_id, precio, vigencia_inicio, vigencia_fin, activa, created_at, updated_at) FROM stdin;
1	3	2	2.50	2026-07-03	2026-08-03	t	2026-07-03 11:16:26	2026-07-03 11:16:26
2	10	2	2.00	2026-07-04	2026-08-04	t	2026-07-03 21:19:34	2026-07-03 21:19:34
3	10	1	1.50	2026-07-04	2026-08-04	t	2026-07-03 21:19:51	2026-07-03 21:19:51
\.


--
-- Data for Name: membresias; Type: TABLE DATA; Schema: socios; Owner: -
--

COPY socios.membresias (id, nombre, descripcion, duracion_dias, precio, activa, created_at, updated_at) FROM stdin;
1	Revive Gold	Plan premium con acceso total a clases y evaluacion fisica.	30	39.90	t	2026-06-01 03:44:15	2026-06-01 04:16:42
2	Revive Corporate	Plan corporativo para aliados y becados.	30	20.00	t	2026-06-01 03:44:15	2026-06-11 20:45:14
4	Plan Anual	Acceso ilimitado por 1 año.	365	399.00	t	2026-06-24 14:08:26	2026-06-24 14:08:26
3	Pase Diario	Acceso por 1 día a las instalaciones.	1	2.50	t	2026-06-24 14:08:26	2026-07-03 10:48:18
5	Musculación	Ideal para la ganancia de masa muscular, salud articular y longevidad	30	60.00	t	2026-07-03 20:50:34	2026-07-03 20:50:34
6	Funcional	Ideal para personas que quieren perder peso y tonificar	30	60.00	t	2026-07-03 20:50:34	2026-07-03 20:50:34
7	Híbrido	Ideales para las personas que quieran ser más atléticas, tener una recomposición corporal, verse bien y sentirse como un atleta	30	80.00	t	2026-07-03 20:50:34	2026-07-03 20:50:34
8	Deportivo	Ideal para deportistas	30	100.00	t	2026-07-03 20:50:34	2026-07-03 20:50:34
9	Fortalecimiento / Rehabilitación	Ideal para personas que salen de una lesión, o están con molestias musculares o articulares	30	100.00	t	2026-07-03 20:50:34	2026-07-03 20:50:34
10	Pase Diario Familia	Acceso por 1 dia a las instalaciones	30	2.00	t	2026-07-03 21:19:00	2026-07-03 21:19:11
\.


--
-- Data for Name: socio_membresias; Type: TABLE DATA; Schema: socios; Owner: -
--

COPY socios.socio_membresias (id, socio_id, membresia_id, fecha_inicio, fecha_fin, estado_id, created_at, updated_at, cedula, sede_id, precio_aplicado) FROM stdin;
11	2	3	2026-07-05	2026-07-05	1	2026-06-29 03:01:04	2026-06-29 03:01:04	1311718181	\N	\N
12	3	3	2026-06-30	2026-06-30	1	2026-06-30 02:01:39	2026-06-29 21:56:01	1311888521	\N	\N
13	3	3	2026-07-03	2026-07-03	1	2026-07-03 11:52:10	2026-07-03 11:52:10	1311888521	3	2.50
14	2	3	2026-07-06	2026-07-06	1	2026-07-03 20:55:34	2026-07-03 20:55:34	1311718181	3	2.50
15	3	10	2026-07-04	2026-08-02	1	2026-07-03 22:29:21	2026-07-03 22:29:21	1311888521	2	2.00
16	2	10	2026-07-07	2026-08-05	1	2026-07-03 22:29:21	2026-07-03 22:29:21	1311718181	2	2.00
\.


--
-- Data for Name: socios; Type: TABLE DATA; Schema: socios; Owner: -
--

COPY socios.socios (id, persona_id, codigo_socio, sede_id, fecha_alta, estado_id, observacion, created_at, updated_at) FROM stdin;
2	1	S-001	\N	2026-06-24	1	\N	2026-06-24 14:13:02	2026-06-24 14:13:02
3	5	S-002	\N	2026-06-24	1	\N	2026-06-24 14:13:02	2026-06-24 14:13:02
4	2	SOC-0004	3	2026-06-30	1	\N	2026-06-30 02:01:39	2026-06-30 02:01:39
\.


--
-- Data for Name: auth_menu_items; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.auth_menu_items (id, gimnasio_id, parent_id, tipo, titulo, icono, ruta, orden, visible, permiso_requerido_id, created_at, updated_at) FROM stdin;
1	1	\N	GRUPO	Administración	settings	\N	1	t	\N	2025-12-26 01:38:39.808047	2025-12-26 01:38:39.808047
2	1	\N	GRUPO	Operación	grid	\N	2	t	\N	2025-12-26 01:38:39.808047	2025-12-26 01:38:39.808047
11	1	\N	GRUPO	Administración	settings	\N	1	t	\N	2025-12-26 01:51:25.889901	2025-12-26 01:51:25.889901
3	1	1	ITEM	Usuarios	users	/admin/usuarios	1	t	1	2025-12-26 01:38:39.808047	2025-12-26 01:51:25.889901
4	1	1	ITEM	Roles	shield	/admin/roles	2	t	4	2025-12-26 01:38:39.808047	2025-12-26 01:51:25.889901
5	1	1	ITEM	Permisos	key	/admin/permisos	3	t	5	2025-12-26 01:38:39.808047	2025-12-26 01:51:25.889901
6	1	1	ITEM	Menú	menu	/admin/menu	4	t	6	2025-12-26 01:38:39.808047	2025-12-26 01:51:25.889901
7	1	1	ITEM	Auditoría	file-search	/admin/auditoria	5	t	7	2025-12-26 01:38:39.808047	2025-12-26 01:51:25.889901
8	1	2	ITEM	Membresías	id-card	/operacion/membresias	1	t	8	2025-12-26 01:38:39.808047	2025-12-26 01:51:25.889901
9	1	2	ITEM	Inventario	boxes	/operacion/inventario	2	t	9	2025-12-26 01:38:39.808047	2025-12-26 01:51:25.889901
10	1	2	ITEM	Facturación	receipt	/operacion/facturas	3	t	10	2025-12-26 01:38:39.808047	2025-12-26 01:51:25.889901
12	1	\N	GRUPO	Operación	grid	\N	2	t	\N	2025-12-26 01:51:25.889901	2025-12-26 01:51:25.889901
\.


--
-- Data for Name: auth_permisos; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.auth_permisos (id, gimnasio_id, codigo, nombre, modulo, descripcion, activo, created_at) FROM stdin;
1	1	USUARIOS_VER	Ver usuarios	USUARIOS	Lista y detalles	t	2025-12-26 01:38:39.808047
2	1	USUARIOS_CREAR	Crear usuarios	USUARIOS	Crear nuevos usuarios	t	2025-12-26 01:38:39.808047
3	1	USUARIOS_EDITAR	Editar usuarios	USUARIOS	Editar datos de usuario	t	2025-12-26 01:38:39.808047
4	1	ROLES_ADMIN	Administrar roles	USUARIOS	CRUD roles	t	2025-12-26 01:38:39.808047
5	1	PERMISOS_ADMIN	Administrar permisos	USUARIOS	CRUD permisos	t	2025-12-26 01:38:39.808047
6	1	MENU_ADMIN	Administrar menú	CONFIG	CRUD menú	t	2025-12-26 01:38:39.808047
7	1	AUDITORIA_VER	Ver auditoría	CONFIG	Consultar auditoría	t	2025-12-26 01:38:39.808047
8	1	MEMBRESIAS_VER	Ver membresías	MEMBRESIAS	Listar membresías	t	2025-12-26 01:38:39.808047
9	1	INVENTARIO_VER	Ver inventario	INVENTARIO	Ver stock	t	2025-12-26 01:38:39.808047
10	1	FACTURAS_VER	Ver facturas	FACTURACION	Listar facturas	t	2025-12-26 01:38:39.808047
21	1	AUTH.USUARIOS.VER	Ver usuarios	AUTH	Listar y ver detalle de usuarios	t	2026-02-05 11:17:01.189499
22	1	AUTH.USUARIOS.CREAR	Crear usuarios	AUTH	Crear usuarios	t	2026-02-05 11:17:01.189499
23	1	AUTH.USUARIOS.EDITAR	Editar usuarios	AUTH	Editar usuarios	t	2026-02-05 11:17:01.189499
24	1	AUTH.USUARIOS.DESACTIVAR	Desactivar usuarios	AUTH	Dar de baja usuarios	t	2026-02-05 11:17:01.189499
25	1	AUTH.ROLES.VER	Ver roles	AUTH	Listar roles	t	2026-02-05 11:17:01.189499
26	1	AUTH.ROLES.CREAR	Crear roles	AUTH	Crear roles	t	2026-02-05 11:17:01.189499
27	1	AUTH.ROLES.EDITAR	Editar roles	AUTH	Editar roles	t	2026-02-05 11:17:01.189499
28	1	AUTH.ROLES.ASIGNAR_PERMISOS	Asignar permisos a roles	AUTH	Gestionar permisos por rol	t	2026-02-05 11:17:01.189499
29	1	AUTH.PERMISOS.VER	Ver permisos	AUTH	Listar permisos	t	2026-02-05 11:17:01.189499
30	1	CFG.SEDES.VER	Ver sedes	CFG	Listar sedes	t	2026-02-05 11:17:01.189499
31	1	CFG.SEDES.EDITAR	Editar sedes	CFG	Crear/editar sedes	t	2026-02-05 11:17:01.189499
32	1	CFG.SERVICIOS.VER	Ver servicios	CFG	Listar servicios	t	2026-02-05 11:17:01.189499
33	1	CFG.SERVICIOS.EDITAR	Editar servicios	CFG	Crear/editar servicios	t	2026-02-05 11:17:01.189499
34	1	CFG.HORARIOS.EDITAR	Editar horarios	CFG	Configurar horarios y capacidad	t	2026-02-05 11:17:01.189499
35	1	CLI.PERFIL.VER	Ver mi perfil	CLI	Ver datos personales	t	2026-02-05 11:17:01.189499
36	1	CLI.PERFIL.EDITAR	Editar mi perfil	CLI	Editar datos personales	t	2026-02-05 11:17:01.189499
37	1	CLI.RESERVAS.CREAR	Reservar cupo	CLI	Crear una reserva	t	2026-02-05 11:17:01.189499
38	1	CLI.RESERVAS.CANCELAR	Cancelar reserva	CLI	Cancelar su reserva	t	2026-02-05 11:17:01.189499
39	1	CLI.PAGOS.VER	Ver mis pagos	CLI	Ver historial de pagos	t	2026-02-05 11:17:01.189499
40	1	CLI.PLANES.VER	Ver mi plan	CLI	Ver plan de entrenamiento/nutrición	t	2026-02-05 11:17:01.189499
41	1	ENT.CLIENTES.VER	Ver clientes asignados	ENT	Listar clientes/deportistas del entrenador	t	2026-02-05 11:17:01.189499
42	1	ENT.EVALUACIONES.CREAR	Registrar evaluación	ENT	Crear evaluación física	t	2026-02-05 11:17:01.189499
43	1	ENT.PLANES.CREAR	Crear plan	ENT	Crear/editar planes de entrenamiento	t	2026-02-05 11:17:01.189499
44	1	ENT.PLANES.CREAR_NUTRICION	Crear plan nutrición	ENT	Crear/editar plan nutricional	t	2026-02-05 11:17:01.189499
45	1	ENT.ASISTENCIA.VER	Ver asistencia	ENT	Consultar asistencia y reservas	t	2026-02-05 11:17:01.189499
46	1	CAJ.PAGOS.CREAR	Registrar pago	CAJ	Registrar pagos/membresías	t	2026-02-05 11:17:01.189499
47	1	CAJ.PAGOS.ANULAR	Anular pago	CAJ	Anular transacciones	t	2026-02-05 11:17:01.189499
48	1	CAJ.VENTAS.VER	Ver ventas	CAJ	Ver reportes de ventas	t	2026-02-05 11:17:01.189499
49	1	REP.GENERAL.VER	Ver reporte general	REP	Reporte general del gimnasio	t	2026-02-05 11:17:01.189499
50	1	REP.RESERVAS.VER	Ver reporte reservas	REP	Reporte de reservas y cupos	t	2026-02-05 11:17:01.189499
51	1	REP.INGRESOS.VER	Ver reporte ingresos	REP	Reporte de ingresos	t	2026-02-05 11:17:01.189499
\.


--
-- Data for Name: auth_rol_permisos; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.auth_rol_permisos (id, rol_id, permiso_id, created_at) FROM stdin;
1	1	7	2025-12-26 01:38:39.808047
2	1	10	2025-12-26 01:38:39.808047
3	1	9	2025-12-26 01:38:39.808047
4	1	8	2025-12-26 01:38:39.808047
5	1	6	2025-12-26 01:38:39.808047
6	1	5	2025-12-26 01:38:39.808047
7	1	4	2025-12-26 01:38:39.808047
8	1	2	2025-12-26 01:38:39.808047
9	1	3	2025-12-26 01:38:39.808047
10	1	1	2025-12-26 01:38:39.808047
11	2	10	2025-12-26 01:38:39.808047
12	2	9	2025-12-26 01:38:39.808047
13	3	8	2025-12-26 01:38:39.808047
14	3	1	2025-12-26 01:38:39.808047
39	1	21	2026-02-05 11:17:11.821184
40	1	22	2026-02-05 11:17:11.821184
41	1	23	2026-02-05 11:17:11.821184
42	1	24	2026-02-05 11:17:11.821184
43	1	25	2026-02-05 11:17:11.821184
44	1	26	2026-02-05 11:17:11.821184
45	1	27	2026-02-05 11:17:11.821184
46	1	28	2026-02-05 11:17:11.821184
47	1	29	2026-02-05 11:17:11.821184
48	1	30	2026-02-05 11:17:11.821184
49	1	31	2026-02-05 11:17:11.821184
50	1	32	2026-02-05 11:17:11.821184
51	1	33	2026-02-05 11:17:11.821184
52	1	34	2026-02-05 11:17:11.821184
53	1	35	2026-02-05 11:17:11.821184
54	1	36	2026-02-05 11:17:11.821184
55	1	37	2026-02-05 11:17:11.821184
56	1	38	2026-02-05 11:17:11.821184
57	1	39	2026-02-05 11:17:11.821184
58	1	40	2026-02-05 11:17:11.821184
59	1	41	2026-02-05 11:17:11.821184
60	1	42	2026-02-05 11:17:11.821184
61	1	43	2026-02-05 11:17:11.821184
62	1	44	2026-02-05 11:17:11.821184
63	1	45	2026-02-05 11:17:11.821184
64	1	46	2026-02-05 11:17:11.821184
65	1	47	2026-02-05 11:17:11.821184
66	1	48	2026-02-05 11:17:11.821184
67	1	49	2026-02-05 11:17:11.821184
68	1	50	2026-02-05 11:17:11.821184
69	1	51	2026-02-05 11:17:11.821184
70	2	46	2026-02-05 11:17:18.635787
71	2	47	2026-02-05 11:17:18.635787
72	2	48	2026-02-05 11:17:18.635787
73	2	51	2026-02-05 11:17:18.635787
74	3	41	2026-02-05 11:17:24.890123
75	3	42	2026-02-05 11:17:24.890123
76	3	43	2026-02-05 11:17:24.890123
77	3	44	2026-02-05 11:17:24.890123
78	3	45	2026-02-05 11:17:24.890123
79	3	50	2026-02-05 11:17:24.890123
80	4	35	2026-02-05 11:17:43.404789
81	4	36	2026-02-05 11:17:43.404789
82	4	37	2026-02-05 11:17:43.404789
83	4	38	2026-02-05 11:17:43.404789
84	4	39	2026-02-05 11:17:43.404789
85	4	40	2026-02-05 11:17:43.404789
92	10	35	2026-02-05 11:22:36.117123
93	10	36	2026-02-05 11:22:36.117123
94	10	37	2026-02-05 11:22:36.117123
95	10	38	2026-02-05 11:22:36.117123
96	10	39	2026-02-05 11:22:36.117123
97	10	40	2026-02-05 11:22:36.117123
\.


--
-- Data for Name: auth_roles; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.auth_roles (id, gimnasio_id, codigo, nombre, descripcion, activo, created_at, updated_at) FROM stdin;
1	1	ADMIN	Administrador	Acceso total	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
2	1	CAJERO	Cajero	Ventas y pagos	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
3	1	ENTRENADOR	Entrenador	Gestión de clientes y evaluaciones	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
4	1	CLIENTE	Cliente	Acceso a su perfil	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
10	1	DEPORTISTA	Deportista	Acceso a su perfil, reservas, pagos y planes	t	2026-02-05 11:22:21.698361	2026-02-05 11:22:21.698361
\.


--
-- Data for Name: auth_tokens_acceso; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.auth_tokens_acceso (id, gimnasio_id, usuario_id, nombre, token_hash, habilidades, ultimo_uso_en, expira_en, created_at, updated_at) FROM stdin;
1	1	1	Web Admin	HASH_TOKEN_DEMO_admin_revive.com	{"scope": ["admin"]}	\N	2026-01-25 01:38:39.808047	2025-12-26 01:38:39.808047	2025-12-26 01:38:39.808047
2	1	2	App	HASH_TOKEN_DEMO_cajero_revive.com	{"scope": ["user"]}	\N	2026-01-25 01:38:39.808047	2025-12-26 01:38:39.808047	2025-12-26 01:38:39.808047
3	1	5	App	HASH_TOKEN_DEMO_juan_revive.com	{"scope": ["user"]}	\N	2026-01-25 01:38:39.808047	2025-12-26 01:38:39.808047	2025-12-26 01:38:39.808047
4	1	4	App	HASH_TOKEN_DEMO_luis_revive.com	{"scope": ["user"]}	\N	2026-01-25 01:38:39.808047	2025-12-26 01:38:39.808047	2025-12-26 01:38:39.808047
5	1	3	App	HASH_TOKEN_DEMO_trainer_revive.com	{"scope": ["user"]}	\N	2026-01-25 01:38:39.808047	2025-12-26 01:38:39.808047	2025-12-26 01:38:39.808047
\.


--
-- Data for Name: auth_usuario_roles; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.auth_usuario_roles (id, usuario_id, rol_id, created_at) FROM stdin;
1	1	1	2025-12-26 01:38:39.808047
2	2	2	2025-12-26 01:38:39.808047
3	5	4	2025-12-26 01:38:39.808047
4	4	4	2025-12-26 01:38:39.808047
5	3	3	2025-12-26 01:38:39.808047
\.


--
-- Data for Name: auth_usuarios; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.auth_usuarios (id, gimnasio_id, persona_id, email, password_hash, estado, fecha_baja, foto_perfil_url, created_at, updated_at, created_id_user, updated_id_user, cedula) FROM stdin;
1	1	1	admin@revive.com	$2y$12$XPMlI5sbIayz/NjJCd.6SusC/z7HnCbzsJEjaT.znqXok7v1F5ayK	ACTIVO	\N	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432	\N	\N	\N
2	1	3	cajero@revive.com	HASH_CAJERO_DEMO	ACTIVO	\N	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432	\N	\N	\N
3	1	4	trainer@revive.com	HASH_TRAINER_DEMO	ACTIVO	\N	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432	\N	\N	\N
4	1	5	luis@revive.com	HASH_CLIENTE_DEMO	ACTIVO	\N	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432	\N	\N	\N
5	1	2	juan@revive.com	HASH_CLIENTE_DEMO	ACTIVO	\N	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432	\N	\N	\N
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.cache (key, value, expiration) FROM stdin;
train-gym-backend-cache-bcfb23d6f5240abe1b530c4f717cd558c3df7448:timer	i:1782837196;	1782837196
train-gym-backend-cache-bcfb23d6f5240abe1b530c4f717cd558c3df7448	i:1;	1782837196
train-gym-backend-cache-f28a9ee98a8d0d62674783b7ecc95317eed842ae:timer	i:1782871721;	1782871721
train-gym-backend-cache-f28a9ee98a8d0d62674783b7ecc95317eed842ae	i:1;	1782871721
train-gym-backend-cache-2ea55ddb1e19aef65263c0fc0909c76198e8eb4d:timer	i:1783049777;	1783049777
train-gym-backend-cache-2ea55ddb1e19aef65263c0fc0909c76198e8eb4d	i:1;	1783049777
train-gym-backend-cache-5c785c036466adea360111aa28563bfd556b5fba:timer	i:1783141204;	1783141204
train-gym-backend-cache-5c785c036466adea360111aa28563bfd556b5fba	i:1;	1783141204
train-gym-backend-cache-bda509b65d880aaf29162226cbee12849e765148:timer	i:1782338160;	1782338160
train-gym-backend-cache-bda509b65d880aaf29162226cbee12849e765148	i:1;	1782338160
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: categoria_servicios; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.categoria_servicios (id, nombre, descripcion, estado_id, user_id, created_at, updated_at) FROM stdin;
10	Entrenamiento físico	Rutinas de fuerza, resistencia, cardio y HIIT en el gimnasio	8	1	2025-10-01 16:17:40.874286	2026-02-05 10:55:00.302955
11	Entrenamiento funcional	Movimientos que mejoran la movilidad y el core con TRX y pesas rusas	9	1	2025-10-01 16:21:23.044032	2026-02-05 10:55:00.302955
12	Yoga	Posturas, respiración y meditación para flexibilidad y calma	9	1	2025-10-01 16:23:03.687842	2026-02-05 10:55:00.302955
13	Pilates	Ejercicios de core, alineación y respiración en colchoneta y aparatos	9	1	2025-10-01 16:24:25.957097	2026-02-05 10:55:00.302955
14	Spinning	Cardio en bicicleta estática con entrenamientos de intensidad variable	9	1	2025-10-01 16:25:14.062495	2026-02-05 10:55:00.302955
15	Bailoterapia	Baile-fitness con música latina y ritmos de alta energía	8	1	2025-10-01 16:27:02.947914	2026-02-05 10:55:00.302955
16	CrossFit	Entrenamiento funcional de alta intensidad con pesas, gimnasia y cardio	9	1	2025-10-01 16:27:59.24903	2026-02-05 10:55:00.302955
17	Nutrición	Asesoría dietética, planes de comidas y seguimiento de objetivos	9	1	2025-10-01 16:29:21.499668	2026-02-05 10:55:00.302955
18	Rehabilitación	Terapia física y movilización post-operatoria y de lesiones	9	1	2025-10-01 16:30:28.686279	2026-02-05 10:55:00.302955
19	Masajes	Masajes terapéuticos, relajantes y deportivos	8	1	2025-10-01 16:32:49.854967	2026-02-05 10:55:00.302955
\.


--
-- Data for Name: estados; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.estados (id, gimnasio_id, tipo, codigo, nombre, descripcion, activo, created_at, updated_at) FROM stdin;
1	1	RESERVAS	PENDIENTE	Pendiente	Reserva creada, pendiente de confirmación	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
2	1	RESERVAS	RESERVADA	Reservada	Reserva registrada	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
3	1	RESERVAS	CONFIRMADA	Confirmada	Reserva confirmada/validada	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
4	1	RESERVAS	ASISTIO	Asistió	El cliente asistió al turno	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
5	1	RESERVAS	NO_ASISTIO	No asistió	El cliente no asistió	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
6	1	RESERVAS	CANCELADA	Cancelada	Reserva cancelada por cliente o staff	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
7	1	RESERVAS	VENCIDA	Vencida	Turno pasado sin validación	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
8	1	PAGOS	PENDIENTE	Pendiente	Pago pendiente	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
9	1	PAGOS	PAGADO	Pagado	Pago registrado	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
10	1	PAGOS	ANULADO	Anulado	Pago anulado	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
11	1	MEMBRESIAS	ACTIVA	Activa	Membresía vigente	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
12	1	MEMBRESIAS	SUSPENDIDA	Suspendida	Membresía suspendida	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
13	1	MEMBRESIAS	VENCIDA	Vencida	Membresía vencida	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
14	1	MEMBRESIAS	CANCELADA	Cancelada	Membresía cancelada	t	2026-02-05 11:33:54.012501	2026-02-05 11:33:54.012501
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: gimnasios; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.gimnasios (id, nombre, ruc, activo, created_at, updated_at) FROM stdin;
1	Revive	\N	t	2025-12-26 01:38:39.808047	2025-12-26 01:38:39.808047
2	TrainRevive Demo	\N	t	2025-12-26 01:38:39.808047	2025-12-26 01:38:39.808047
3	Revive	\N	t	2025-12-26 01:50:38.381432	2025-12-26 01:50:38.381432
4	TrainRevive Demo	\N	t	2025-12-26 01:50:38.381432	2025-12-26 01:50:38.381432
\.


--
-- Data for Name: horarios_gym; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.horarios_gym (id, sede_id, tipo_servicio_id, hora_apertura, hora_cierre, capacidad_maxima, tiempo_turno_min, tipo_usuario, activo, created_at, updated_at) FROM stdin;
1	1	13	06:00:00	22:00:00	30	60	4	t	2026-02-05 11:25:17.106511	2026-02-05 11:25:17.106511
2	1	13	08:00:00	14:00:00	25	60	4	t	2026-02-05 11:25:17.106511	2026-02-05 11:25:17.106511
3	1	13	08:00:00	12:00:00	20	60	4	t	2026-02-05 11:25:17.106511	2026-02-05 11:25:17.106511
4	1	13	05:00:00	22:00:00	40	60	5	t	2026-02-05 11:25:17.106511	2026-02-05 11:25:17.106511
5	1	13	07:00:00	15:00:00	35	60	5	t	2026-02-05 11:25:17.106511	2026-02-05 11:25:17.106511
6	1	13	07:00:00	13:00:00	30	60	5	t	2026-02-05 11:25:17.106511	2026-02-05 11:25:17.106511
7	1	13	06:00:00	22:00:00	25	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
8	1	14	06:00:00	22:00:00	20	45	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
9	1	15	06:00:00	22:00:00	25	45	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
10	1	16	07:00:00	21:00:00	18	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
11	1	17	07:00:00	21:00:00	16	45	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
12	1	18	07:00:00	21:00:00	12	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
13	1	19	06:30:00	20:30:00	20	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
14	1	20	06:30:00	20:30:00	20	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
15	1	21	06:30:00	20:30:00	18	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
16	1	22	06:00:00	21:00:00	14	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
17	1	23	06:00:00	21:00:00	10	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
18	1	24	06:00:00	21:00:00	12	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
19	1	25	06:00:00	21:00:00	22	45	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
20	1	26	06:00:00	21:00:00	18	45	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
21	1	27	06:00:00	21:00:00	18	45	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
22	1	28	08:00:00	20:00:00	30	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
23	1	29	08:00:00	20:00:00	30	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
24	1	30	08:00:00	20:00:00	25	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
25	1	31	06:00:00	21:00:00	20	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
26	1	32	06:00:00	21:00:00	20	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
27	1	33	06:00:00	21:00:00	16	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
28	1	34	00:00:00	23:59:00	9999	1440	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
29	1	35	00:00:00	23:59:00	9999	1440	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
30	1	36	00:00:00	23:59:00	9999	1440	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
31	1	37	07:00:00	19:00:00	10	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
32	1	38	07:00:00	19:00:00	10	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
33	1	39	07:00:00	19:00:00	10	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
34	1	40	09:00:00	19:00:00	6	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
35	1	41	09:00:00	19:00:00	6	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
36	1	42	09:00:00	19:00:00	6	60	4	t	2026-02-05 11:35:51.357704	2026-02-05 11:35:51.357704
\.


--
-- Data for Name: horarios_gym_dias; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.horarios_gym_dias (horario_id, dia_semana) FROM stdin;
1	1
1	2
1	3
1	4
1	5
2	6
3	7
4	1
4	2
4	3
4	4
4	5
5	6
6	7
7	1
7	2
7	3
7	4
7	5
7	6
8	1
8	2
8	3
8	4
8	5
8	6
9	1
9	2
9	3
9	4
9	5
9	6
10	1
10	2
10	3
10	4
10	5
10	6
11	1
11	2
11	3
11	4
11	5
11	6
12	1
12	2
12	3
12	4
12	5
12	6
13	1
13	2
13	3
13	4
13	5
13	6
14	1
14	2
14	3
14	4
14	5
14	6
15	1
15	2
15	3
15	4
15	5
15	6
16	1
16	2
16	3
16	4
16	5
16	6
17	1
17	2
17	3
17	4
17	5
17	6
18	1
18	2
18	3
18	4
18	5
18	6
19	1
19	2
19	3
19	4
19	5
19	6
20	1
20	2
20	3
20	4
20	5
20	6
21	1
21	2
21	3
21	4
21	5
21	6
22	1
22	2
22	3
22	4
22	5
22	6
23	1
23	2
23	3
23	4
23	5
23	6
24	1
24	2
24	3
24	4
24	5
24	6
25	1
25	2
25	3
25	4
25	5
25	6
26	1
26	2
26	3
26	4
26	5
26	6
27	1
27	2
27	3
27	4
27	5
27	6
28	1
28	2
28	3
28	4
28	5
28	6
29	1
29	2
29	3
29	4
29	5
29	6
30	1
30	2
30	3
30	4
30	5
30	6
31	1
31	2
31	3
31	4
31	5
31	6
32	1
32	2
32	3
32	4
32	5
32	6
33	1
33	2
33	3
33	4
33	5
33	6
34	1
34	2
34	3
34	4
34	5
34	6
35	1
35	2
35	3
35	4
35	5
35	6
36	1
36	2
36	3
36	4
36	5
36	6
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.migrations (id, migration, batch) FROM stdin;
1	2026_05_20_200000_allow_socio_in_producto_precios_type_check	1
2	0001_01_01_000000_create_users_table	2
3	0001_01_01_000001_create_cache_table	2
4	0001_01_01_000002_create_jobs_table	2
5	2026_02_04_154345_create_personal_access_tokens_table	2
6	2026_05_31_054343_create_inventarios_proveedores_table	2
7	2026_05_31_220000_create_core_domain_architecture	3
8	2026_06_01_100000_create_entrenamiento_ejercicios_table	4
9	2026_06_03_180000_create_entrenamiento_evaluaciones_rm_tables	5
10	2026_06_03_200000_create_entrenamiento_planes_rutinas_tables	5
11	2026_06_03_210000_extend_rutinas_and_create_templates_tables	6
12	2026_06_03_220000_add_bloque_orden_to_rutinas	7
13	2026_06_03_230000_create_entrenamiento_ejecuciones_table	8
14	2026_06_12_162552_add_nivel_resultado_to_evaluaciones_table	9
15	2026_06_12_163733_create_catalogos_table	10
16	2026_06_12_165127_add_fecha_proxima_evaluacion_to_evaluaciones_table	11
17	2026_06_12_202654_add_tipo_entrenamiento_to_ejercicios_table	12
18	2026_06_15_133700_add_transferencia_and_series_detalles_to_rutinas_tables	13
19	2026_06_16_120000_create_entrenamiento_planificacion_detallada_tables	14
20	2026_06_16_150000_normalize_entrenamiento_ejercicios_catalog	15
21	2026_06_16_151000_finalize_entrenamiento_ejercicios_cleanup	16
22	2026_06_16_160000_make_plan_persona_optional	17
23	2026_06_16_170000_add_tipo_to_entrenamiento_planes	18
24	2026_06_16_180000_add_estructura_to_entrenamiento_planes	19
25	2026_06_16_140000_create_entrenamiento_plantillas_semanales_tables	20
26	2026_06_19_130000_add_alcance_to_entrenamiento_planes	20
27	2026_06_20_100000_create_plan_entrenamiento_asignaciones_table	21
28	2026_06_20_110000_create_plan_ejecuciones_table	22
29	2026_06_22_150000_add_cedula_to_seguridad_usuarios	23
30	2026_06_24_051817_alter_repeticiones_reales_in_plan_ejecuciones	24
31	2026_06_27_120000_add_pos_debt_and_membership_fields_to_ventas	25
32	2026_06_27_140000_create_ventas_punto_venta_borradores_table	25
33	2026_06_28_220000_add_tipo_detalle_to_ventas_venta_detalles	26
34	2026_06_30_120000_create_seguridad_usuario_sedes_table	27
35	2026_07_01_120000_create_ventas_devoluciones_tables	28
36	2026_07_03_120000_create_membresia_precios_sede_table	28
37	2026_07_03_121000_alter_membresia_precios_sede_for_history	28
38	2026_07_03_122000_add_sede_precio_to_socio_membresias	29
39	2026_07_03_123000_add_cedula_paciente_to_egresos	30
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: personal_access_tokens; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.personal_access_tokens (id, tokenable_type, tokenable_id, name, token, abilities, last_used_at, expires_at, created_at, updated_at) FROM stdin;
13	App\\Models\\AuthUsuario	1	train-gym-web	8d067a0ab6dff328972f78deaebc74f14ba917f81e519ab782020b726d625f9a	["*"]	2026-06-29 02:27:45	\N	2026-06-28 05:30:09	2026-06-29 02:27:45
4	App\\Models\\AuthUsuario	1	train-gym-web	281ad18b4a170572bc5c3641a2d32b13eacbabf79a7c24928795009ea7bedcf7	["*"]	2026-06-19 13:29:06	\N	2026-06-19 04:14:05	2026-06-19 13:29:06
12	App\\Models\\AuthUsuario	1	train-gym-web	b509077948136cb616ddf076761d04aa39bd79aef0d6a50d0e3a84dfe16f0934	["*"]	2026-07-02 05:01:12	\N	2026-06-24 21:55:00	2026-07-02 05:01:12
14	App\\Models\\AuthUsuario	1	train-gym-web	7b99dcffefe286b7b600cb122d8d8b803859705f976f7a652a17aa2e5f87d045	["*"]	2026-07-02 16:28:51	\N	2026-06-29 04:24:46	2026-07-02 16:28:51
21	App\\Models\\AuthUsuario	1	train-gym-web	ba75175c946c0b94fc7bfc6407565a46b14cb8b509ef907545cf97eb333ab591	["*"]	2026-06-30 23:16:31	\N	2026-06-30 21:07:42	2026-06-30 23:16:31
19	App\\Models\\AuthUsuario	4	train-gym-web	f4b56a1d1674fa7289b3493b279183e611d8beaa807329162501cbe908ca619f	["*"]	2026-06-29 22:55:33	\N	2026-06-29 21:53:12	2026-06-29 22:55:33
1	App\\Models\\AuthUsuario	1	train-gym-web	27caec02b11b5ec36e733296375c879831532bb5a883d059bef497e723e4a6f6	["*"]	2026-06-01 02:54:39	\N	2026-05-31 07:14:33	2026-06-01 02:54:39
6	App\\Models\\AuthUsuario	1	train-gym-web	5a6e0640c75cf9ada20f2c985c982b3391a2e40f1fc277b110fb0c367cfe5a60	["*"]	2026-06-23 17:55:00	\N	2026-06-23 17:38:13	2026-06-23 17:55:00
22	App\\Models\\AuthUsuario	1	train-gym-web	019868fe552607a0af7e45d8baa7fdd1737332896b13e1320c3a002772802593	["*"]	2026-07-01 13:37:44	\N	2026-06-30 23:57:40	2026-07-01 13:37:44
23	App\\Models\\AuthUsuario	1	train-gym-web	df6bbce6ba9d9587183c7656a6be679798ee2722abf139da17fa969b1a8229dd	["*"]	2026-07-02 22:36:07	\N	2026-07-02 22:35:17	2026-07-02 22:36:07
18	App\\Models\\AuthUsuario	2	train-gym-web	526a63bcf50416ef8b54cb22df0baa75232ab83bf819da49053ffbccc9fe52cc	["*"]	2026-07-03 09:23:40	\N	2026-06-29 21:28:55	2026-07-03 09:23:40
3	App\\Models\\AuthUsuario	1	train-gym-web	fcb1f39a48c21c54a893da9087a057f0ebe5e732d130bb0a755c795e0c3c9437	["*"]	2026-06-17 14:20:23	\N	2026-06-01 19:55:57	2026-06-17 14:20:23
26	App\\Models\\AuthUsuario	1	train-gym-web	232a1bb9f5001771e4fa9201280bbc93503b13ad614054fb4b047865ee7d2006	["*"]	2026-07-03 23:04:50	\N	2026-07-03 22:06:54	2026-07-03 23:04:50
27	App\\Models\\AuthUsuario	1	train-gym-web	901a9656e16b3c0238b3266d702d0045a015291e3dbd6a47ae8392a29425e96f	["*"]	2026-07-04 00:08:20	\N	2026-07-03 23:59:05	2026-07-04 00:08:20
\.


--
-- Data for Name: personas; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.personas (id, gimnasio_id, cedula, nombres, apellidos, fecha_nacimiento, sexo, nacionalidad, provincia, ciudad, parroquia, direccion, celular, email_contacto, imagen_url, created_at, updated_at) FROM stdin;
1	1	1300000001	María	Admin	1995-01-15	F	Ecuatoriana	Manabí	Manta	Tarqui	Manta	098000222	admin@revive.com	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
3	1	1300000002	Carlos	Cajero	1998-08-20	M	Ecuatoriana	Manabí	Manta	Centro	Manta	097000333	cajero@revive.com	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
4	1	1300000003	Ana	Trainer	1997-03-12	F	Ecuatoriana	Manabí	Manta	Uleam	Manta	096000444	trainer@revive.com	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
5	1	1300000004	Luis	Cliente	2002-11-02	M	Ecuatoriana	Manabí	Manta	Xpadel	Manta	095000555	luis@revive.com	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
2	1	1312345678	Juan	Pérez	2000-05-10	M	Ecuatoriana	Manabí	Manta	Los Esteros	Manta	099111999	juan@revive.com	\N	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
\.


--
-- Data for Name: reservas_gym; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.reservas_gym (id, fecha, hora, horario_id, sede_id, tipo_servicio_id, user_id, cedula, estado_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: sedes; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.sedes (id, gimnasio_id, nombre, direccion, telefono, activa, created_at, updated_at) FROM stdin;
1	1	Revive Home	Dirección Home	0999999999	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
2	1	Revive Xpadel	Dirección Xpadel	0988888888	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
3	1	Revive Centro	Dirección Centro	0977777777	t	2025-12-26 01:38:39.808047	2025-12-26 01:50:38.381432
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
\.


--
-- Data for Name: tipos_servicios; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.tipos_servicios (id, nombre, descripcion, breve_desc, categoria_id, estado_id, user_id, created_at, updated_at) FROM stdin;
13	Rutina de fuerza	Programa de fuerza con pesas y máquinas	Fuerza	10	1	1	2025-10-01 16:17:40.874286	2026-02-05 10:55:07.812922
14	HIIT	Entrenamiento de alta intensidad en 20-30 min	HIIT	10	1	1	2025-10-01 16:17:40.874286	2026-02-05 10:55:07.812922
15	Circuitos de cardio	Combina cardio y fuerza en un solo circuito	Circuito	10	1	1	2025-10-01 16:17:40.874286	2026-02-05 10:55:07.812922
16	Calistenia	Entrenamiento con bandas de suspensión	TRX	11	1	1	2025-10-01 16:21:23.044032	2026-02-05 10:55:07.812922
17	Pesas Rusas	Ejercicios funcionales con pesas rusas	Pesas	11	1	1	2025-10-01 16:21:23.044032	2026-02-05 10:55:07.812922
18	Gymnastics	Ejercicios con calistenia y movimientos dinámicos	Gymnastics	11	1	1	2025-10-01 16:21:23.044032	2026-02-05 10:55:07.812922
19	Yoga Restaurador	Posturas suaves y respiración consciente	Restaurador	12	1	1	2025-10-01 16:23:03.687842	2026-02-05 10:55:07.812922
20	Vinyasa	Secuencia fluida de posturas y movimiento	Vinyasa	12	1	1	2025-10-01 16:23:03.687842	2026-02-05 10:55:07.812922
21	Power Yoga	Yoga de alta intensidad	Power	12	1	1	2025-10-01 16:23:03.687842	2026-02-05 10:55:07.812922
22	Pilates Mat	Rutinas en colchoneta para fuerza y flexibilidad	Mat	13	1	1	2025-10-01 16:24:25.957097	2026-02-05 10:55:07.812922
23	Pilates Reformer	Ejercicios con aparato Reformer	Reformer	13	1	1	2025-10-01 16:24:25.957097	2026-02-05 10:55:07.812922
24	Pilates en suspensión	Pilates en barra de suspensión	Suspensión	13	1	1	2025-10-01 16:24:25.957097	2026-02-05 10:55:07.812922
25	Spinning 45	Sesión de 45 min a ritmo moderado-intenso	45	14	1	1	2025-10-01 16:25:14.062495	2026-02-05 10:55:07.812922
26	Spinning avanzado	Intensidad progresiva con resistencia alta	Avanzado	14	1	1	2025-10-01 16:25:14.062495	2026-02-05 10:55:07.812922
27	Spinning + HIIT	Mezcla de ciclismo y entrenamientos cortos de fuerza	Mix	14	1	1	2025-10-01 16:25:14.062495	2026-02-05 10:55:07.812922
28	Bailoterapia Básica	Clases para principiantes y nivel intermedio	Básica	15	1	1	2025-10-01 16:27:02.947914	2026-02-05 10:55:07.812922
29	Zumba Cardio	Sesiones de alta energía con ritmo rápido	Cardio	15	1	1	2025-10-01 16:27:02.947914	2026-02-05 10:55:07.812922
30	Bailoterapia + HIIT	Mezcla de baile y entrenamientos cortos de fuerza	HIIT	15	1	1	2025-10-01 16:27:02.947914	2026-02-05 10:55:07.812922
31	CrossFit	Rutina de CrossFit (WOD – workout of the day)	WOD	16	1	1	2025-10-01 16:27:59.24903	2026-02-05 10:55:07.812922
32	CrossFit Básica	Entrenamiento de fuerza y cardio para principiantes	Básica	16	1	1	2025-10-01 16:27:59.24903	2026-02-05 10:55:07.812922
33	CrossFit Avanzado	Rutina de alta intensidad para atletas	Avanzado	16	1	1	2025-10-01 16:27:59.24903	2026-02-05 10:55:07.812922
34	Plan de comidas	Plan personalizado de comidas diarias	Plan	17	1	1	2025-10-01 16:29:21.499668	2026-02-05 10:55:07.812922
35	Seguimiento nutricional	Control de macronutrientes y calorías	Seguimiento	17	1	1	2025-10-01 16:29:21.499668	2026-02-05 10:55:07.812922
36	Terapia nutricional	Apoyo para problemas de salud (diabetes, hipertensión…)	Terapia	17	1	1	2025-10-01 16:29:21.499668	2026-02-05 10:55:07.812922
37	Rehabilitación ortopédica	Terapia post-operatoria de articulaciones	Ortopédica	18	1	1	2025-10-01 16:30:28.686279	2026-02-05 10:55:07.812922
38	Terapia miofascial	Masaje y estiramientos para tejidos blandos	Miofascial	18	1	1	2025-10-01 16:30:28.686279	2026-02-05 10:55:07.812922
39	Rehabilitación cardiovascular	Ejercicios para recuperar resistencia	Cardio	18	1	1	2025-10-01 16:30:28.686279	2026-02-05 10:55:07.812922
40	Masaje deportivo	Masaje de tejido profundo para atletas	Deportivo	19	1	1	2025-10-01 16:32:49.854967	2026-02-05 10:55:07.812922
41	Masaje relajante	Técnicas de relajación profunda	Relajante	19	1	1	2025-10-01 16:32:49.854967	2026-02-05 10:55:07.812922
42	Masaje con aromaterapia	Masaje con aceites esenciales	\N	19	1	1	2025-10-01 16:32:49.854967	2026-02-05 10:55:07.812922
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: usuario_sedes; Type: TABLE DATA; Schema: train_gimnasio; Owner: -
--

COPY train_gimnasio.usuario_sedes (id, usuario_id, sede_id, es_principal, activo, created_at) FROM stdin;
1	1	1	t	t	2025-12-26 01:38:39.808047
2	2	1	t	t	2025-12-26 01:38:39.808047
3	5	1	t	t	2025-12-26 01:38:39.808047
4	4	1	t	t	2025-12-26 01:38:39.808047
5	3	2	t	t	2025-12-26 01:38:39.808047
6	1	3	f	t	2025-12-26 01:38:39.808047
8	1	2	f	t	2025-12-26 01:38:39.808047
\.


--
-- Data for Name: devolucion_detalles; Type: TABLE DATA; Schema: ventas; Owner: -
--

COPY ventas.devolucion_detalles (id, devolucion_id, venta_detalle_id, producto_id, membresia_id, tipo_detalle, descripcion, cantidad, precio_unitario, subtotal, reintegra_stock, movimiento_inventario_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: devoluciones; Type: TABLE DATA; Schema: ventas; Owner: -
--

COPY ventas.devoluciones (id, venta_id, tipo, motivo, observacion, reintegra_stock, monto_total, estado, metadata, created_by, updated_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: venta_detalles; Type: TABLE DATA; Schema: ventas; Owner: -
--

COPY ventas.venta_detalles (id, venta_id, producto_id, cantidad, precio_unitario, subtotal, created_at, updated_at, membresia_id, tipo_detalle, descripcion) FROM stdin;
13	9	36	1.00	1.50	1.50	2026-06-29 03:01:04	2026-06-29 03:01:04	\N	PRODUCTO	\N
14	10	\N	1.00	5.00	5.00	2026-06-30 02:01:39	2026-06-30 02:01:39	3	MEMBRESIA	Pase Diario
15	10	34	1.00	25.00	25.00	2026-06-30 02:01:39	2026-06-30 02:01:39	\N	PRODUCTO	Creatina Monohidratada Platinum - 400g
16	10	36	1.00	1.50	1.50	2026-06-30 02:01:39	2026-06-30 02:01:39	\N	PRODUCTO	Gatorade - 750ml
\.


--
-- Data for Name: venta_pagos; Type: TABLE DATA; Schema: ventas; Owner: -
--

COPY ventas.venta_pagos (id, venta_id, forma_pago, monto, referencia_pago, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: ventas; Type: TABLE DATA; Schema: ventas; Owner: -
--

COPY ventas.ventas (id, sede_id, cliente_id, vendedor_id, total, estado, fecha, created_by, updated_by, created_at, updated_at, persona_id, vendedor_usuario_id, referencia, forma_pago, observacion, subtotal, iva, tipo_venta, estado_pago, saldo_pendiente, fecha_consumo, membresia_id, metadata, estado_devolucion, monto_devuelto, anulada_at, anulada_by) FROM stdin;
9	2	1	1	1.50	1	2026-06-29 03:01:04	1	1	2026-06-29 03:01:04	2026-06-29 03:01:04	1	1	POS-1782701982456-CON	PENDIENTE	Borrador POS | Socio S-001	1.50	0.00	CONSUMO	PENDIENTE	1.50	2026-06-29	\N	{"tipo": "CONSUMO", "items": [{"nombre": "Gatorade - 750ml", "cantidad": 1, "producto_id": 36, "precio_unitario": 1.5}], "origen": "POS"}	SIN_DEVOLUCION	0.00	\N	\N
10	3	2	2	31.50	1	2026-06-29 21:01:39	2	2	2026-06-29 21:01:39	2026-06-29 21:01:39	2	2	POS-REVIVE-1782784899661	PENDIENTE	Venta POS Revive | Membresia Pase Diario y consumo POS	31.50	0.00	COMPUESTA	PENDIENTE	31.50	2026-06-29	3	{"tipo": "COMPUESTA", "items": [{"codigo": "CREA-001", "nombre": "Creatina Monohidratada Platinum - 400g", "cantidad": 1, "subtotal": 25, "producto_id": 34, "tipo_detalle": "PRODUCTO", "precio_unitario": 25}, {"codigo": "GATO-001", "nombre": "Gatorade - 750ml", "cantidad": 1, "subtotal": 1.5, "producto_id": 36, "tipo_detalle": "PRODUCTO", "precio_unitario": 1.5}], "origen": "POS", "socio_id": 4, "fecha_fin": "2026-06-30", "membresia": {"id": 3, "nombre": "Pase Diario", "precio": 5, "descripcion": "Acceso por 1 día a las instalaciones.", "duracion_dias": 1, "cliente_nombre": "Juan Pérez"}, "fecha_inicio": "2026-06-30", "asignacion_id": 12, "detalle_tipos": ["MEMBRESIA", "CONSUMO"]}	SIN_DEVOLUCION	0.00	\N	\N
\.


--
-- Name: aud_cambios_id_seq; Type: SEQUENCE SET; Schema: auditoria; Owner: -
--

SELECT pg_catalog.setval('auditoria.aud_cambios_id_seq', 606, true);


--
-- Name: estados_id_seq; Type: SEQUENCE SET; Schema: core; Owner: -
--

SELECT pg_catalog.setval('core.estados_id_seq', 3, true);


--
-- Name: persona_tipo_detalle_id_seq; Type: SEQUENCE SET; Schema: core; Owner: -
--

SELECT pg_catalog.setval('core.persona_tipo_detalle_id_seq', 14, true);


--
-- Name: persona_tipos_id_seq; Type: SEQUENCE SET; Schema: core; Owner: -
--

SELECT pg_catalog.setval('core.persona_tipos_id_seq', 5, true);


--
-- Name: personas_id_seq; Type: SEQUENCE SET; Schema: core; Owner: -
--

SELECT pg_catalog.setval('core.personas_id_seq', 11, true);


--
-- Name: sedes_id_seq; Type: SEQUENCE SET; Schema: core; Owner: -
--

SELECT pg_catalog.setval('core.sedes_id_seq', 3, true);


--
-- Name: ejecuciones_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.ejecuciones_id_seq', 1, false);


--
-- Name: ejercicios_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.ejercicios_id_seq', 83, true);


--
-- Name: evaluaciones_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.evaluaciones_id_seq', 3, true);


--
-- Name: plan_asignaciones_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plan_asignaciones_id_seq', 5, true);


--
-- Name: plan_bloques_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plan_bloques_id_seq', 118, true);


--
-- Name: plan_dias_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plan_dias_id_seq', 38, true);


--
-- Name: plan_ejecuciones_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plan_ejecuciones_id_seq', 2, true);


--
-- Name: plan_ejercicio_series_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plan_ejercicio_series_id_seq', 580, true);


--
-- Name: plan_ejercicio_transferencias_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plan_ejercicio_transferencias_id_seq', 24, true);


--
-- Name: plan_ejercicios_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plan_ejercicios_id_seq', 152, true);


--
-- Name: plan_transferencia_series_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plan_transferencia_series_id_seq', 88, true);


--
-- Name: planes_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.planes_id_seq', 3, true);


--
-- Name: plantilla_semana_bloques_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plantilla_semana_bloques_id_seq', 1, false);


--
-- Name: plantilla_semana_dias_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plantilla_semana_dias_id_seq', 1, false);


--
-- Name: plantilla_semana_ejercicio_series_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plantilla_semana_ejercicio_series_id_seq', 1, false);


--
-- Name: plantilla_semana_ejercicio_transferencias_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plantilla_semana_ejercicio_transferencias_id_seq', 1, false);


--
-- Name: plantilla_semana_ejercicios_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plantilla_semana_ejercicios_id_seq', 1, false);


--
-- Name: plantilla_semana_transferencia_series_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plantilla_semana_transferencia_series_id_seq', 1, false);


--
-- Name: plantillas_semanales_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.plantillas_semanales_id_seq', 1, false);


--
-- Name: rm_registros_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.rm_registros_id_seq', 3, true);


--
-- Name: rutina_plantilla_detalles_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.rutina_plantilla_detalles_id_seq', 1, false);


--
-- Name: rutina_plantillas_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.rutina_plantillas_id_seq', 1, false);


--
-- Name: rutinas_id_seq; Type: SEQUENCE SET; Schema: entrenamiento; Owner: -
--

SELECT pg_catalog.setval('entrenamiento.rutinas_id_seq', 153, true);


--
-- Name: categorias_producto_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: -
--

SELECT pg_catalog.setval('inventario.categorias_producto_id_seq', 14, true);


--
-- Name: movimientos_inventario_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: -
--

SELECT pg_catalog.setval('inventario.movimientos_inventario_id_seq', 72, true);


--
-- Name: producto_lotes_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: -
--

SELECT pg_catalog.setval('inventario.producto_lotes_id_seq', 8, true);


--
-- Name: producto_precios_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: -
--

SELECT pg_catalog.setval('inventario.producto_precios_id_seq', 67, true);


--
-- Name: producto_stock_sede_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: -
--

SELECT pg_catalog.setval('inventario.producto_stock_sede_id_seq', 86, true);


--
-- Name: productos_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: -
--

SELECT pg_catalog.setval('inventario.productos_id_seq', 42, true);


--
-- Name: proveedores_prov_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: -
--

SELECT pg_catalog.setval('inventario.proveedores_prov_id_seq', 2, true);


--
-- Name: transferencia_detalle_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: -
--

SELECT pg_catalog.setval('inventario.transferencia_detalle_id_seq', 1, false);


--
-- Name: transferencias_inventario_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: -
--

SELECT pg_catalog.setval('inventario.transferencias_inventario_id_seq', 1, false);


--
-- Name: catalogos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.catalogos_id_seq', 22, true);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.jobs_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 4, true);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.personal_access_tokens_id_seq', 14, true);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.users_id_seq', 1, false);


--
-- Name: catalogo_patologias_id_seq; Type: SEQUENCE SET; Schema: salud; Owner: -
--

SELECT pg_catalog.setval('salud.catalogo_patologias_id_seq', 4, true);


--
-- Name: ficha_mediciones_id_seq; Type: SEQUENCE SET; Schema: salud; Owner: -
--

SELECT pg_catalog.setval('salud.ficha_mediciones_id_seq', 9, true);


--
-- Name: ficha_patologias_id_seq; Type: SEQUENCE SET; Schema: salud; Owner: -
--

SELECT pg_catalog.setval('salud.ficha_patologias_id_seq', 1, true);


--
-- Name: fichas_tecnicas_id_seq; Type: SEQUENCE SET; Schema: salud; Owner: -
--

SELECT pg_catalog.setval('salud.fichas_tecnicas_id_seq', 8, true);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: seguridad; Owner: -
--

SELECT pg_catalog.setval('seguridad.roles_id_seq', 10, true);


--
-- Name: usuario_roles_id_seq; Type: SEQUENCE SET; Schema: seguridad; Owner: -
--

SELECT pg_catalog.setval('seguridad.usuario_roles_id_seq', 11, true);


--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE SET; Schema: seguridad; Owner: -
--

SELECT pg_catalog.setval('seguridad.usuario_sedes_id_seq', 23, true);


--
-- Name: usuarios_id_seq; Type: SEQUENCE SET; Schema: seguridad; Owner: -
--

SELECT pg_catalog.setval('seguridad.usuarios_id_seq', 5, true);


--
-- Name: membresia_precios_sede_id_seq; Type: SEQUENCE SET; Schema: socios; Owner: -
--

SELECT pg_catalog.setval('socios.membresia_precios_sede_id_seq', 3, true);


--
-- Name: membresias_id_seq; Type: SEQUENCE SET; Schema: socios; Owner: -
--

SELECT pg_catalog.setval('socios.membresias_id_seq', 10, true);


--
-- Name: socio_membresias_id_seq; Type: SEQUENCE SET; Schema: socios; Owner: -
--

SELECT pg_catalog.setval('socios.socio_membresias_id_seq', 16, true);


--
-- Name: socios_id_seq; Type: SEQUENCE SET; Schema: socios; Owner: -
--

SELECT pg_catalog.setval('socios.socios_id_seq', 4, true);


--
-- Name: auth_menu_items_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.auth_menu_items_id_seq', 20, true);


--
-- Name: auth_permisos_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.auth_permisos_id_seq', 57, true);


--
-- Name: auth_rol_permisos_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.auth_rol_permisos_id_seq', 97, true);


--
-- Name: auth_roles_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.auth_roles_id_seq', 10, true);


--
-- Name: auth_tokens_acceso_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.auth_tokens_acceso_id_seq', 10, true);


--
-- Name: auth_usuario_roles_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.auth_usuario_roles_id_seq', 10, true);


--
-- Name: auth_usuarios_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.auth_usuarios_id_seq', 10, true);


--
-- Name: categoria_servicios_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.categoria_servicios_id_seq', 1, false);


--
-- Name: estados_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.estados_id_seq', 14, true);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.failed_jobs_id_seq', 1, false);


--
-- Name: gimnasios_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.gimnasios_id_seq', 4, true);


--
-- Name: horarios_gym_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.horarios_gym_id_seq', 36, true);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.jobs_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.migrations_id_seq', 39, true);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.personal_access_tokens_id_seq', 27, true);


--
-- Name: personas_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.personas_id_seq', 10, true);


--
-- Name: reservas_gym_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.reservas_gym_id_seq', 1, false);


--
-- Name: sedes_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.sedes_id_seq', 6, true);


--
-- Name: tipos_servicios_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.tipos_servicios_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.users_id_seq', 1, false);


--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: -
--

SELECT pg_catalog.setval('train_gimnasio.usuario_sedes_id_seq', 16, true);


--
-- Name: devolucion_detalles_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: -
--

SELECT pg_catalog.setval('ventas.devolucion_detalles_id_seq', 1, false);


--
-- Name: devoluciones_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: -
--

SELECT pg_catalog.setval('ventas.devoluciones_id_seq', 1, false);


--
-- Name: venta_detalles_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: -
--

SELECT pg_catalog.setval('ventas.venta_detalles_id_seq', 16, true);


--
-- Name: venta_pagos_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: -
--

SELECT pg_catalog.setval('ventas.venta_pagos_id_seq', 1, false);


--
-- Name: ventas_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: -
--

SELECT pg_catalog.setval('ventas.ventas_id_seq', 10, true);


--
-- Name: aud_cambios aud_cambios_pkey; Type: CONSTRAINT; Schema: auditoria; Owner: -
--

ALTER TABLE ONLY auditoria.aud_cambios
    ADD CONSTRAINT aud_cambios_pkey PRIMARY KEY (id);


--
-- Name: estados estados_codigo_key; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.estados
    ADD CONSTRAINT estados_codigo_key UNIQUE (codigo);


--
-- Name: estados estados_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.estados
    ADD CONSTRAINT estados_pkey PRIMARY KEY (id);


--
-- Name: persona_tipo_detalle persona_tipo_detalle_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.persona_tipo_detalle
    ADD CONSTRAINT persona_tipo_detalle_pkey PRIMARY KEY (id);


--
-- Name: persona_tipos persona_tipos_codigo_key; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.persona_tipos
    ADD CONSTRAINT persona_tipos_codigo_key UNIQUE (codigo);


--
-- Name: persona_tipos persona_tipos_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.persona_tipos
    ADD CONSTRAINT persona_tipos_pkey PRIMARY KEY (id);


--
-- Name: personas personas_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.personas
    ADD CONSTRAINT personas_pkey PRIMARY KEY (id);


--
-- Name: sedes sedes_pkey; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.sedes
    ADD CONSTRAINT sedes_pkey PRIMARY KEY (id);


--
-- Name: persona_tipo_detalle uq_core_persona_tipo; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.persona_tipo_detalle
    ADD CONSTRAINT uq_core_persona_tipo UNIQUE (persona_id, tipo_id);


--
-- Name: personas uq_core_personas_identificacion; Type: CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.personas
    ADD CONSTRAINT uq_core_personas_identificacion UNIQUE (tipo_identificacion, numero_identificacion);


--
-- Name: ejecuciones ejecuciones_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.ejecuciones
    ADD CONSTRAINT ejecuciones_pkey PRIMARY KEY (id);


--
-- Name: ejecuciones ejecuciones_unique_rutina_fecha; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.ejecuciones
    ADD CONSTRAINT ejecuciones_unique_rutina_fecha UNIQUE (rutina_id, fecha_ejecucion);


--
-- Name: ejercicios ejercicios_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.ejercicios
    ADD CONSTRAINT ejercicios_pkey PRIMARY KEY (id);


--
-- Name: evaluaciones evaluaciones_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.evaluaciones
    ADD CONSTRAINT evaluaciones_pkey PRIMARY KEY (id);


--
-- Name: plan_asignaciones plan_asignaciones_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_asignaciones
    ADD CONSTRAINT plan_asignaciones_pkey PRIMARY KEY (id);


--
-- Name: plan_bloques plan_bloques_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_bloques
    ADD CONSTRAINT plan_bloques_pkey PRIMARY KEY (id);


--
-- Name: plan_dias plan_dias_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_dias
    ADD CONSTRAINT plan_dias_pkey PRIMARY KEY (id);


--
-- Name: plan_dias plan_dias_plan_semana_dia_unique; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_dias
    ADD CONSTRAINT plan_dias_plan_semana_dia_unique UNIQUE (plan_id, semana, dia);


--
-- Name: plan_ejecuciones plan_ejecuciones_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones
    ADD CONSTRAINT plan_ejecuciones_pkey PRIMARY KEY (id);


--
-- Name: plan_ejecuciones plan_ejecuciones_unique_ejercicio_fecha; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones
    ADD CONSTRAINT plan_ejecuciones_unique_ejercicio_fecha UNIQUE (plan_ejercicio_id, fecha_ejecucion);


--
-- Name: plan_ejercicio_series plan_ejercicio_series_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_series
    ADD CONSTRAINT plan_ejercicio_series_pkey PRIMARY KEY (id);


--
-- Name: plan_ejercicio_transferencias plan_ejercicio_transferencias_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_transferencias
    ADD CONSTRAINT plan_ejercicio_transferencias_pkey PRIMARY KEY (id);


--
-- Name: plan_ejercicios plan_ejercicios_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios
    ADD CONSTRAINT plan_ejercicios_pkey PRIMARY KEY (id);


--
-- Name: plan_transferencia_series plan_transferencia_series_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_transferencia_series
    ADD CONSTRAINT plan_transferencia_series_pkey PRIMARY KEY (id);


--
-- Name: planes planes_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.planes
    ADD CONSTRAINT planes_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_bloques plantilla_semana_bloques_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_bloques
    ADD CONSTRAINT plantilla_semana_bloques_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_dias plantilla_semana_dias_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_dias
    ADD CONSTRAINT plantilla_semana_dias_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_dias plantilla_semana_dias_unique; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_dias
    ADD CONSTRAINT plantilla_semana_dias_unique UNIQUE (plantilla_id, orden_dia, dia);


--
-- Name: plantilla_semana_ejercicio_series plantilla_semana_ejercicio_series_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_series
    ADD CONSTRAINT plantilla_semana_ejercicio_series_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_ejercicio_transferencias plantilla_semana_ejercicio_transferencias_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_transferencias
    ADD CONSTRAINT plantilla_semana_ejercicio_transferencias_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_ejercicios plantilla_semana_ejercicios_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicios
    ADD CONSTRAINT plantilla_semana_ejercicios_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_transferencia_series plantilla_semana_transferencia_series_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_transferencia_series
    ADD CONSTRAINT plantilla_semana_transferencia_series_pkey PRIMARY KEY (id);


--
-- Name: plantillas_semanales plantillas_semanales_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantillas_semanales
    ADD CONSTRAINT plantillas_semanales_pkey PRIMARY KEY (id);


--
-- Name: rm_registros rm_registros_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rm_registros
    ADD CONSTRAINT rm_registros_pkey PRIMARY KEY (id);


--
-- Name: rutina_plantilla_detalles rutina_plantilla_detalles_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles
    ADD CONSTRAINT rutina_plantilla_detalles_pkey PRIMARY KEY (id);


--
-- Name: rutina_plantillas rutina_plantillas_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutina_plantillas
    ADD CONSTRAINT rutina_plantillas_pkey PRIMARY KEY (id);


--
-- Name: rutinas rutinas_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutinas
    ADD CONSTRAINT rutinas_pkey PRIMARY KEY (id);


--
-- Name: categorias_producto categorias_producto_pkey; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.categorias_producto
    ADD CONSTRAINT categorias_producto_pkey PRIMARY KEY (id);


--
-- Name: movimientos_inventario movimientos_inventario_pkey; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_pkey PRIMARY KEY (id);


--
-- Name: producto_lotes producto_lotes_pkey; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.producto_lotes
    ADD CONSTRAINT producto_lotes_pkey PRIMARY KEY (id);


--
-- Name: producto_precios producto_precios_pkey; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.producto_precios
    ADD CONSTRAINT producto_precios_pkey PRIMARY KEY (id);


--
-- Name: producto_stock_sede producto_stock_sede_pkey; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.producto_stock_sede
    ADD CONSTRAINT producto_stock_sede_pkey PRIMARY KEY (id);


--
-- Name: productos productos_codigo_key; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.productos
    ADD CONSTRAINT productos_codigo_key UNIQUE (codigo);


--
-- Name: productos productos_pkey; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.productos
    ADD CONSTRAINT productos_pkey PRIMARY KEY (id);


--
-- Name: proveedores proveedores_pkey; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.proveedores
    ADD CONSTRAINT proveedores_pkey PRIMARY KEY (prov_id);


--
-- Name: transferencia_detalle transferencia_detalle_pkey; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.transferencia_detalle
    ADD CONSTRAINT transferencia_detalle_pkey PRIMARY KEY (id);


--
-- Name: transferencias_inventario transferencias_inventario_pkey; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.transferencias_inventario
    ADD CONSTRAINT transferencias_inventario_pkey PRIMARY KEY (id);


--
-- Name: producto_lotes uq_producto_lotes; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.producto_lotes
    ADD CONSTRAINT uq_producto_lotes UNIQUE (producto_id, sede_id, codigo_lote);


--
-- Name: producto_stock_sede uq_producto_stock_sede; Type: CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.producto_stock_sede
    ADD CONSTRAINT uq_producto_stock_sede UNIQUE (producto_id, sede_id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: catalogos catalogos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogos
    ADD CONSTRAINT catalogos_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: catalogos public_catalogos_grupo_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.catalogos
    ADD CONSTRAINT public_catalogos_grupo_codigo_unique UNIQUE (grupo, codigo);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: catalogo_patologias catalogo_patologias_nombre_key; Type: CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.catalogo_patologias
    ADD CONSTRAINT catalogo_patologias_nombre_key UNIQUE (nombre);


--
-- Name: catalogo_patologias catalogo_patologias_pkey; Type: CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.catalogo_patologias
    ADD CONSTRAINT catalogo_patologias_pkey PRIMARY KEY (id);


--
-- Name: ficha_mediciones ficha_mediciones_pkey; Type: CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.ficha_mediciones
    ADD CONSTRAINT ficha_mediciones_pkey PRIMARY KEY (id);


--
-- Name: ficha_patologias ficha_patologias_pkey; Type: CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.ficha_patologias
    ADD CONSTRAINT ficha_patologias_pkey PRIMARY KEY (id);


--
-- Name: fichas_tecnicas fichas_tecnicas_pkey; Type: CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.fichas_tecnicas
    ADD CONSTRAINT fichas_tecnicas_pkey PRIMARY KEY (id);


--
-- Name: roles roles_codigo_key; Type: CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.roles
    ADD CONSTRAINT roles_codigo_key UNIQUE (codigo);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: usuario_roles uq_seguridad_usuario_rol; Type: CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_roles
    ADD CONSTRAINT uq_seguridad_usuario_rol UNIQUE (usuario_id, rol_id);


--
-- Name: usuario_sedes uq_seguridad_usuario_sede; Type: CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_sedes
    ADD CONSTRAINT uq_seguridad_usuario_sede UNIQUE (usuario_id, sede_id);


--
-- Name: usuario_roles usuario_roles_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_roles
    ADD CONSTRAINT usuario_roles_pkey PRIMARY KEY (id);


--
-- Name: usuario_sedes usuario_sedes_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_sedes
    ADD CONSTRAINT usuario_sedes_pkey PRIMARY KEY (id);


--
-- Name: usuarios usuarios_email_key; Type: CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT usuarios_email_key UNIQUE (email);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id);


--
-- Name: membresia_precios_sede membresia_precios_sede_pkey; Type: CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.membresia_precios_sede
    ADD CONSTRAINT membresia_precios_sede_pkey PRIMARY KEY (id);


--
-- Name: membresias membresias_pkey; Type: CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.membresias
    ADD CONSTRAINT membresias_pkey PRIMARY KEY (id);


--
-- Name: socio_membresias socio_membresias_pkey; Type: CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_pkey PRIMARY KEY (id);


--
-- Name: socios socios_codigo_socio_key; Type: CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_codigo_socio_key UNIQUE (codigo_socio);


--
-- Name: socios socios_persona_id_key; Type: CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_persona_id_key UNIQUE (persona_id);


--
-- Name: socios socios_pkey; Type: CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_pkey PRIMARY KEY (id);


--
-- Name: auth_menu_items auth_menu_items_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT auth_menu_items_pkey PRIMARY KEY (id);


--
-- Name: auth_permisos auth_permisos_gimnasio_id_codigo_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_permisos
    ADD CONSTRAINT auth_permisos_gimnasio_id_codigo_key UNIQUE (gimnasio_id, codigo);


--
-- Name: auth_permisos auth_permisos_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_permisos
    ADD CONSTRAINT auth_permisos_pkey PRIMARY KEY (id);


--
-- Name: auth_rol_permisos auth_rol_permisos_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos
    ADD CONSTRAINT auth_rol_permisos_pkey PRIMARY KEY (id);


--
-- Name: auth_rol_permisos auth_rol_permisos_rol_id_permiso_id_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos
    ADD CONSTRAINT auth_rol_permisos_rol_id_permiso_id_key UNIQUE (rol_id, permiso_id);


--
-- Name: auth_roles auth_roles_gimnasio_id_codigo_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_roles
    ADD CONSTRAINT auth_roles_gimnasio_id_codigo_key UNIQUE (gimnasio_id, codigo);


--
-- Name: auth_roles auth_roles_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_roles
    ADD CONSTRAINT auth_roles_pkey PRIMARY KEY (id);


--
-- Name: auth_tokens_acceso auth_tokens_acceso_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso
    ADD CONSTRAINT auth_tokens_acceso_pkey PRIMARY KEY (id);


--
-- Name: auth_tokens_acceso auth_tokens_acceso_token_hash_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso
    ADD CONSTRAINT auth_tokens_acceso_token_hash_key UNIQUE (token_hash);


--
-- Name: auth_usuario_roles auth_usuario_roles_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles
    ADD CONSTRAINT auth_usuario_roles_pkey PRIMARY KEY (id);


--
-- Name: auth_usuario_roles auth_usuario_roles_usuario_id_rol_id_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles
    ADD CONSTRAINT auth_usuario_roles_usuario_id_rol_id_key UNIQUE (usuario_id, rol_id);


--
-- Name: auth_usuarios auth_usuarios_gimnasio_id_email_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_gimnasio_id_email_key UNIQUE (gimnasio_id, email);


--
-- Name: auth_usuarios auth_usuarios_gimnasio_id_persona_id_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_gimnasio_id_persona_id_key UNIQUE (gimnasio_id, persona_id);


--
-- Name: auth_usuarios auth_usuarios_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: categoria_servicios categoria_servicios_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.categoria_servicios
    ADD CONSTRAINT categoria_servicios_pkey PRIMARY KEY (id);


--
-- Name: estados estados_gimnasio_tipo_codigo_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.estados
    ADD CONSTRAINT estados_gimnasio_tipo_codigo_key UNIQUE (gimnasio_id, tipo, codigo);


--
-- Name: estados estados_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.estados
    ADD CONSTRAINT estados_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: gimnasios gimnasios_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.gimnasios
    ADD CONSTRAINT gimnasios_pkey PRIMARY KEY (id);


--
-- Name: horarios_gym_dias horarios_gym_dias_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.horarios_gym_dias
    ADD CONSTRAINT horarios_gym_dias_pkey PRIMARY KEY (horario_id, dia_semana);


--
-- Name: horarios_gym horarios_gym_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.horarios_gym
    ADD CONSTRAINT horarios_gym_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: personas personas_gimnasio_id_cedula_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.personas
    ADD CONSTRAINT personas_gimnasio_id_cedula_key UNIQUE (gimnasio_id, cedula);


--
-- Name: personas personas_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.personas
    ADD CONSTRAINT personas_pkey PRIMARY KEY (id);


--
-- Name: reservas_gym reservas_gym_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT reservas_gym_pkey PRIMARY KEY (id);


--
-- Name: sedes sedes_gimnasio_id_nombre_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.sedes
    ADD CONSTRAINT sedes_gimnasio_id_nombre_key UNIQUE (gimnasio_id, nombre);


--
-- Name: sedes sedes_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.sedes
    ADD CONSTRAINT sedes_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: tipos_servicios tipos_servicios_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.tipos_servicios
    ADD CONSTRAINT tipos_servicios_pkey PRIMARY KEY (id);


--
-- Name: categoria_servicios uq_categoria_servicios_nombre; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.categoria_servicios
    ADD CONSTRAINT uq_categoria_servicios_nombre UNIQUE (nombre);


--
-- Name: auth_menu_items uq_menu_gym_ruta; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT uq_menu_gym_ruta UNIQUE (gimnasio_id, ruta);


--
-- Name: auth_menu_items uq_menu_item; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT uq_menu_item UNIQUE (gimnasio_id, parent_id, ruta);


--
-- Name: auth_menu_items uq_menu_ruta; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT uq_menu_ruta UNIQUE (gimnasio_id, ruta);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: usuario_sedes usuario_sedes_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes
    ADD CONSTRAINT usuario_sedes_pkey PRIMARY KEY (id);


--
-- Name: usuario_sedes usuario_sedes_usuario_id_sede_id_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes
    ADD CONSTRAINT usuario_sedes_usuario_id_sede_id_key UNIQUE (usuario_id, sede_id);


--
-- Name: devolucion_detalles devolucion_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_pkey PRIMARY KEY (id);


--
-- Name: devoluciones devoluciones_pkey; Type: CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_pkey PRIMARY KEY (id);


--
-- Name: venta_detalles venta_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.venta_detalles
    ADD CONSTRAINT venta_detalles_pkey PRIMARY KEY (id);


--
-- Name: venta_pagos venta_pagos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.venta_pagos
    ADD CONSTRAINT venta_pagos_pkey PRIMARY KEY (id);


--
-- Name: ventas ventas_pkey; Type: CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT ventas_pkey PRIMARY KEY (id);


--
-- Name: idx_aud_cambios_accion_fecha; Type: INDEX; Schema: auditoria; Owner: -
--

CREATE INDEX idx_aud_cambios_accion_fecha ON auditoria.aud_cambios USING btree (accion, created_at DESC);


--
-- Name: idx_aud_cambios_actor_fecha; Type: INDEX; Schema: auditoria; Owner: -
--

CREATE INDEX idx_aud_cambios_actor_fecha ON auditoria.aud_cambios USING btree (actor_usuario_id, created_at DESC);


--
-- Name: idx_aud_cambios_actor_persona_fecha; Type: INDEX; Schema: auditoria; Owner: -
--

CREATE INDEX idx_aud_cambios_actor_persona_fecha ON auditoria.aud_cambios USING btree (actor_persona_id, created_at DESC);


--
-- Name: idx_aud_cambios_created_at; Type: INDEX; Schema: auditoria; Owner: -
--

CREATE INDEX idx_aud_cambios_created_at ON auditoria.aud_cambios USING btree (created_at DESC);


--
-- Name: idx_aud_cambios_modulo_fecha; Type: INDEX; Schema: auditoria; Owner: -
--

CREATE INDEX idx_aud_cambios_modulo_fecha ON auditoria.aud_cambios USING btree (modulo, created_at DESC);


--
-- Name: idx_aud_cambios_operacion_fecha; Type: INDEX; Schema: auditoria; Owner: -
--

CREATE INDEX idx_aud_cambios_operacion_fecha ON auditoria.aud_cambios USING btree (operacion, created_at DESC);


--
-- Name: idx_aud_cambios_request_id; Type: INDEX; Schema: auditoria; Owner: -
--

CREATE INDEX idx_aud_cambios_request_id ON auditoria.aud_cambios USING btree (request_id);


--
-- Name: idx_aud_cambios_tabla_registro_fecha; Type: INDEX; Schema: auditoria; Owner: -
--

CREATE INDEX idx_aud_cambios_tabla_registro_fecha ON auditoria.aud_cambios USING btree (tabla, registro_id, created_at DESC);


--
-- Name: entrenamiento_ejercicios_nombre_unique_idx; Type: INDEX; Schema: entrenamiento; Owner: -
--

CREATE UNIQUE INDEX entrenamiento_ejercicios_nombre_unique_idx ON entrenamiento.ejercicios USING btree (lower(btrim((nombre)::text)));


--
-- Name: plan_bloques_plan_dia_idx; Type: INDEX; Schema: entrenamiento; Owner: -
--

CREATE INDEX plan_bloques_plan_dia_idx ON entrenamiento.plan_bloques USING btree (plan_dia_id, orden);


--
-- Name: plan_dias_plan_idx; Type: INDEX; Schema: entrenamiento; Owner: -
--

CREATE INDEX plan_dias_plan_idx ON entrenamiento.plan_dias USING btree (plan_id, semana, dia);


--
-- Name: plan_ejercicio_series_ejercicio_idx; Type: INDEX; Schema: entrenamiento; Owner: -
--

CREATE INDEX plan_ejercicio_series_ejercicio_idx ON entrenamiento.plan_ejercicio_series USING btree (plan_ejercicio_id, numero_serie);


--
-- Name: plan_ejercicio_transferencias_ejercicio_idx; Type: INDEX; Schema: entrenamiento; Owner: -
--

CREATE INDEX plan_ejercicio_transferencias_ejercicio_idx ON entrenamiento.plan_ejercicio_transferencias USING btree (plan_ejercicio_id, orden);


--
-- Name: plan_ejercicios_bloque_idx; Type: INDEX; Schema: entrenamiento; Owner: -
--

CREATE INDEX plan_ejercicios_bloque_idx ON entrenamiento.plan_ejercicios USING btree (plan_bloque_id, orden);


--
-- Name: plan_ejercicios_rm_idx; Type: INDEX; Schema: entrenamiento; Owner: -
--

CREATE INDEX plan_ejercicios_rm_idx ON entrenamiento.plan_ejercicios USING btree (rm_registro_id);


--
-- Name: plan_transferencia_series_transferencia_idx; Type: INDEX; Schema: entrenamiento; Owner: -
--

CREATE INDEX plan_transferencia_series_transferencia_idx ON entrenamiento.plan_transferencia_series USING btree (transferencia_id, numero_serie);


--
-- Name: plantilla_semana_dias_idx; Type: INDEX; Schema: entrenamiento; Owner: -
--

CREATE INDEX plantilla_semana_dias_idx ON entrenamiento.plantilla_semana_dias USING btree (plantilla_id, orden_dia);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: idx_seguridad_usuario_sedes_usuario; Type: INDEX; Schema: seguridad; Owner: -
--

CREATE INDEX idx_seguridad_usuario_sedes_usuario ON seguridad.usuario_sedes USING btree (usuario_id, activo);


--
-- Name: uq_seguridad_usuarios_cedula; Type: INDEX; Schema: seguridad; Owner: -
--

CREATE UNIQUE INDEX uq_seguridad_usuarios_cedula ON seguridad.usuarios USING btree (cedula);


--
-- Name: idx_membresia_precios_sede_lookup; Type: INDEX; Schema: socios; Owner: -
--

CREATE INDEX idx_membresia_precios_sede_lookup ON socios.membresia_precios_sede USING btree (membresia_id, sede_id, activa);


--
-- Name: idx_socio_membresias_cedula; Type: INDEX; Schema: socios; Owner: -
--

CREATE INDEX idx_socio_membresias_cedula ON socios.socio_membresias USING btree (cedula);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX cache_expiration_index ON train_gimnasio.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON train_gimnasio.cache_locks USING btree (expiration);


--
-- Name: idx_auth_usuarios_gym_email; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_auth_usuarios_gym_email ON train_gimnasio.auth_usuarios USING btree (gimnasio_id, email);


--
-- Name: idx_estados_activo; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_estados_activo ON train_gimnasio.estados USING btree (activo);


--
-- Name: idx_estados_gym_tipo; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_estados_gym_tipo ON train_gimnasio.estados USING btree (gimnasio_id, tipo);


--
-- Name: idx_horarios_gym_activo; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_horarios_gym_activo ON train_gimnasio.horarios_gym USING btree (activo);


--
-- Name: idx_horarios_gym_dias_dia; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_horarios_gym_dias_dia ON train_gimnasio.horarios_gym_dias USING btree (dia_semana);


--
-- Name: idx_horarios_gym_sede_servicio; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_horarios_gym_sede_servicio ON train_gimnasio.horarios_gym USING btree (sede_id, tipo_servicio_id);


--
-- Name: idx_horarios_gym_tipo_usuario; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_horarios_gym_tipo_usuario ON train_gimnasio.horarios_gym USING btree (tipo_usuario);


--
-- Name: idx_menu_gym_parent_orden; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_menu_gym_parent_orden ON train_gimnasio.auth_menu_items USING btree (gimnasio_id, parent_id, orden);


--
-- Name: idx_personas_gym_cedula; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_personas_gym_cedula ON train_gimnasio.personas USING btree (gimnasio_id, cedula);


--
-- Name: idx_reservas_cedula; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_reservas_cedula ON train_gimnasio.reservas_gym USING btree (cedula);


--
-- Name: idx_reservas_por_slot; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_reservas_por_slot ON train_gimnasio.reservas_gym USING btree (tipo_servicio_id, horario_id, fecha, hora, estado_id);


--
-- Name: idx_tipos_servicios_categoria; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_tipos_servicios_categoria ON train_gimnasio.tipos_servicios USING btree (categoria_id);


--
-- Name: idx_tipos_servicios_estado; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_tipos_servicios_estado ON train_gimnasio.tipos_servicios USING btree (estado_id);


--
-- Name: idx_tokens_usuario; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX idx_tokens_usuario ON train_gimnasio.auth_tokens_acceso USING btree (usuario_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX jobs_queue_index ON train_gimnasio.jobs USING btree (queue);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON train_gimnasio.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON train_gimnasio.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX sessions_last_activity_index ON train_gimnasio.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE INDEX sessions_user_id_index ON train_gimnasio.sessions USING btree (user_id);


--
-- Name: uq_reserva_cedula_slot; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE UNIQUE INDEX uq_reserva_cedula_slot ON train_gimnasio.reservas_gym USING btree (cedula, tipo_servicio_id, horario_id, fecha, hora) WHERE (cedula IS NOT NULL);


--
-- Name: uq_reserva_usuario_slot; Type: INDEX; Schema: train_gimnasio; Owner: -
--

CREATE UNIQUE INDEX uq_reserva_usuario_slot ON train_gimnasio.reservas_gym USING btree (user_id, tipo_servicio_id, horario_id, fecha, hora) WHERE (user_id IS NOT NULL);


--
-- Name: idx_ventas_devolucion_detalles_devolucion; Type: INDEX; Schema: ventas; Owner: -
--

CREATE INDEX idx_ventas_devolucion_detalles_devolucion ON ventas.devolucion_detalles USING btree (devolucion_id);


--
-- Name: idx_ventas_devolucion_detalles_venta_detalle; Type: INDEX; Schema: ventas; Owner: -
--

CREATE INDEX idx_ventas_devolucion_detalles_venta_detalle ON ventas.devolucion_detalles USING btree (venta_detalle_id);


--
-- Name: idx_ventas_devoluciones_tipo; Type: INDEX; Schema: ventas; Owner: -
--

CREATE INDEX idx_ventas_devoluciones_tipo ON ventas.devoluciones USING btree (tipo, created_at DESC);


--
-- Name: idx_ventas_devoluciones_venta; Type: INDEX; Schema: ventas; Owner: -
--

CREATE INDEX idx_ventas_devoluciones_venta ON ventas.devoluciones USING btree (venta_id, created_at DESC);


--
-- Name: idx_ventas_persona_estado_pago; Type: INDEX; Schema: ventas; Owner: -
--

CREATE INDEX idx_ventas_persona_estado_pago ON ventas.ventas USING btree (persona_id, estado_pago, fecha_consumo DESC);


--
-- Name: idx_ventas_tipo_venta; Type: INDEX; Schema: ventas; Owner: -
--

CREATE INDEX idx_ventas_tipo_venta ON ventas.ventas USING btree (tipo_venta, fecha_consumo DESC);


--
-- Name: idx_ventas_venta_detalles_tipo_detalle; Type: INDEX; Schema: ventas; Owner: -
--

CREATE INDEX idx_ventas_venta_detalles_tipo_detalle ON ventas.venta_detalles USING btree (tipo_detalle);


--
-- Name: categoria_servicios trg_set_updated_at_categoria_servicios; Type: TRIGGER; Schema: train_gimnasio; Owner: -
--

CREATE TRIGGER trg_set_updated_at_categoria_servicios BEFORE UPDATE ON train_gimnasio.categoria_servicios FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: estados trg_set_updated_at_estados; Type: TRIGGER; Schema: train_gimnasio; Owner: -
--

CREATE TRIGGER trg_set_updated_at_estados BEFORE UPDATE ON train_gimnasio.estados FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: horarios_gym trg_set_updated_at_horarios_gym; Type: TRIGGER; Schema: train_gimnasio; Owner: -
--

CREATE TRIGGER trg_set_updated_at_horarios_gym BEFORE UPDATE ON train_gimnasio.horarios_gym FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: reservas_gym trg_set_updated_at_reservas_gym; Type: TRIGGER; Schema: train_gimnasio; Owner: -
--

CREATE TRIGGER trg_set_updated_at_reservas_gym BEFORE UPDATE ON train_gimnasio.reservas_gym FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: tipos_servicios trg_set_updated_at_tipos_servicios; Type: TRIGGER; Schema: train_gimnasio; Owner: -
--

CREATE TRIGGER trg_set_updated_at_tipos_servicios BEFORE UPDATE ON train_gimnasio.tipos_servicios FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: persona_tipo_detalle persona_tipo_detalle_persona_id_fkey; Type: FK CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.persona_tipo_detalle
    ADD CONSTRAINT persona_tipo_detalle_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: persona_tipo_detalle persona_tipo_detalle_tipo_id_fkey; Type: FK CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.persona_tipo_detalle
    ADD CONSTRAINT persona_tipo_detalle_tipo_id_fkey FOREIGN KEY (tipo_id) REFERENCES core.persona_tipos(id) ON DELETE CASCADE;


--
-- Name: personas personas_estado_id_fkey; Type: FK CONSTRAINT; Schema: core; Owner: -
--

ALTER TABLE ONLY core.personas
    ADD CONSTRAINT personas_estado_id_fkey FOREIGN KEY (estado_id) REFERENCES core.estados(id);


--
-- Name: ejecuciones ejecuciones_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.ejecuciones
    ADD CONSTRAINT ejecuciones_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: ejecuciones ejecuciones_rutina_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.ejecuciones
    ADD CONSTRAINT ejecuciones_rutina_id_fkey FOREIGN KEY (rutina_id) REFERENCES entrenamiento.rutinas(id) ON DELETE CASCADE;


--
-- Name: evaluaciones evaluaciones_persona_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.evaluaciones
    ADD CONSTRAINT evaluaciones_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: plan_asignaciones plan_asignaciones_persona_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_asignaciones
    ADD CONSTRAINT plan_asignaciones_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE SET NULL;


--
-- Name: plan_asignaciones plan_asignaciones_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_asignaciones
    ADD CONSTRAINT plan_asignaciones_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: plan_bloques plan_bloques_plan_dia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_bloques
    ADD CONSTRAINT plan_bloques_plan_dia_id_fkey FOREIGN KEY (plan_dia_id) REFERENCES entrenamiento.plan_dias(id) ON DELETE CASCADE;


--
-- Name: plan_dias plan_dias_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_dias
    ADD CONSTRAINT plan_dias_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: plan_ejecuciones plan_ejecuciones_plan_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones
    ADD CONSTRAINT plan_ejecuciones_plan_ejercicio_id_fkey FOREIGN KEY (plan_ejercicio_id) REFERENCES entrenamiento.plan_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plan_ejecuciones plan_ejecuciones_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones
    ADD CONSTRAINT plan_ejecuciones_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: plan_ejercicio_series plan_ejercicio_series_plan_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_series
    ADD CONSTRAINT plan_ejercicio_series_plan_ejercicio_id_fkey FOREIGN KEY (plan_ejercicio_id) REFERENCES entrenamiento.plan_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plan_ejercicio_transferencias plan_ejercicio_transferencias_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_transferencias
    ADD CONSTRAINT plan_ejercicio_transferencias_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: plan_ejercicio_transferencias plan_ejercicio_transferencias_plan_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_transferencias
    ADD CONSTRAINT plan_ejercicio_transferencias_plan_ejercicio_id_fkey FOREIGN KEY (plan_ejercicio_id) REFERENCES entrenamiento.plan_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plan_ejercicios plan_ejercicios_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios
    ADD CONSTRAINT plan_ejercicios_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: plan_ejercicios plan_ejercicios_plan_bloque_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios
    ADD CONSTRAINT plan_ejercicios_plan_bloque_id_fkey FOREIGN KEY (plan_bloque_id) REFERENCES entrenamiento.plan_bloques(id) ON DELETE CASCADE;


--
-- Name: plan_ejercicios plan_ejercicios_rm_registro_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios
    ADD CONSTRAINT plan_ejercicios_rm_registro_id_fkey FOREIGN KEY (rm_registro_id) REFERENCES entrenamiento.rm_registros(id) ON DELETE SET NULL;


--
-- Name: plan_transferencia_series plan_transferencia_series_transferencia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plan_transferencia_series
    ADD CONSTRAINT plan_transferencia_series_transferencia_id_fkey FOREIGN KEY (transferencia_id) REFERENCES entrenamiento.plan_ejercicio_transferencias(id) ON DELETE CASCADE;


--
-- Name: planes planes_persona_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.planes
    ADD CONSTRAINT planes_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_bloques plantilla_semana_bloques_plantilla_dia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_bloques
    ADD CONSTRAINT plantilla_semana_bloques_plantilla_dia_id_fkey FOREIGN KEY (plantilla_dia_id) REFERENCES entrenamiento.plantilla_semana_dias(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_dias plantilla_semana_dias_plantilla_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_dias
    ADD CONSTRAINT plantilla_semana_dias_plantilla_id_fkey FOREIGN KEY (plantilla_id) REFERENCES entrenamiento.plantillas_semanales(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_ejercicio_series plantilla_semana_ejercicio_series_plantilla_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_series
    ADD CONSTRAINT plantilla_semana_ejercicio_series_plantilla_ejercicio_id_fkey FOREIGN KEY (plantilla_ejercicio_id) REFERENCES entrenamiento.plantilla_semana_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_ejercicio_transferencias plantilla_semana_ejercicio_transfer_plantilla_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_transferencias
    ADD CONSTRAINT plantilla_semana_ejercicio_transfer_plantilla_ejercicio_id_fkey FOREIGN KEY (plantilla_ejercicio_id) REFERENCES entrenamiento.plantilla_semana_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_ejercicio_transferencias plantilla_semana_ejercicio_transferencias_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_transferencias
    ADD CONSTRAINT plantilla_semana_ejercicio_transferencias_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL;


--
-- Name: plantilla_semana_ejercicios plantilla_semana_ejercicios_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicios
    ADD CONSTRAINT plantilla_semana_ejercicios_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL;


--
-- Name: plantilla_semana_ejercicios plantilla_semana_ejercicios_plantilla_bloque_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicios
    ADD CONSTRAINT plantilla_semana_ejercicios_plantilla_bloque_id_fkey FOREIGN KEY (plantilla_bloque_id) REFERENCES entrenamiento.plantilla_semana_bloques(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_transferencia_series plantilla_semana_transferencia_series_transferencia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_transferencia_series
    ADD CONSTRAINT plantilla_semana_transferencia_series_transferencia_id_fkey FOREIGN KEY (transferencia_id) REFERENCES entrenamiento.plantilla_semana_ejercicio_transferencias(id) ON DELETE CASCADE;


--
-- Name: rm_registros rm_registros_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rm_registros
    ADD CONSTRAINT rm_registros_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: rm_registros rm_registros_persona_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rm_registros
    ADD CONSTRAINT rm_registros_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: rutina_plantilla_detalles rutina_plantilla_detalles_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles
    ADD CONSTRAINT rutina_plantilla_detalles_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: rutina_plantilla_detalles rutina_plantilla_detalles_ejercicio_transferencia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles
    ADD CONSTRAINT rutina_plantilla_detalles_ejercicio_transferencia_id_fkey FOREIGN KEY (ejercicio_transferencia_id) REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL;


--
-- Name: rutina_plantilla_detalles rutina_plantilla_detalles_plantilla_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles
    ADD CONSTRAINT rutina_plantilla_detalles_plantilla_id_fkey FOREIGN KEY (plantilla_id) REFERENCES entrenamiento.rutina_plantillas(id) ON DELETE CASCADE;


--
-- Name: rutinas rutinas_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutinas
    ADD CONSTRAINT rutinas_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: rutinas rutinas_ejercicio_transferencia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutinas
    ADD CONSTRAINT rutinas_ejercicio_transferencia_id_fkey FOREIGN KEY (ejercicio_transferencia_id) REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL;


--
-- Name: rutinas rutinas_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: -
--

ALTER TABLE ONLY entrenamiento.rutinas
    ADD CONSTRAINT rutinas_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: movimientos_inventario fk_movimientos_inventario_lote; Type: FK CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.movimientos_inventario
    ADD CONSTRAINT fk_movimientos_inventario_lote FOREIGN KEY (lote_id) REFERENCES inventario.producto_lotes(id);


--
-- Name: movimientos_inventario fk_movimientos_inventario_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.movimientos_inventario
    ADD CONSTRAINT fk_movimientos_inventario_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: producto_lotes fk_producto_lotes_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.producto_lotes
    ADD CONSTRAINT fk_producto_lotes_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: producto_precios fk_producto_precios_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.producto_precios
    ADD CONSTRAINT fk_producto_precios_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: producto_stock_sede fk_producto_stock_sede_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.producto_stock_sede
    ADD CONSTRAINT fk_producto_stock_sede_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: productos fk_productos_categoria; Type: FK CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.productos
    ADD CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id) REFERENCES inventario.categorias_producto(id);


--
-- Name: transferencia_detalle fk_transferencia_detalle_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.transferencia_detalle
    ADD CONSTRAINT fk_transferencia_detalle_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: transferencia_detalle fk_transferencia_detalle_transferencia; Type: FK CONSTRAINT; Schema: inventario; Owner: -
--

ALTER TABLE ONLY inventario.transferencia_detalle
    ADD CONSTRAINT fk_transferencia_detalle_transferencia FOREIGN KEY (transferencia_id) REFERENCES inventario.transferencias_inventario(id) ON DELETE CASCADE;


--
-- Name: ficha_mediciones ficha_mediciones_ficha_tecnica_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.ficha_mediciones
    ADD CONSTRAINT ficha_mediciones_ficha_tecnica_id_fkey FOREIGN KEY (ficha_tecnica_id) REFERENCES salud.fichas_tecnicas(id) ON DELETE CASCADE;


--
-- Name: ficha_patologias ficha_patologias_ficha_tecnica_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.ficha_patologias
    ADD CONSTRAINT ficha_patologias_ficha_tecnica_id_fkey FOREIGN KEY (ficha_tecnica_id) REFERENCES salud.fichas_tecnicas(id) ON DELETE CASCADE;


--
-- Name: ficha_patologias ficha_patologias_patologia_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.ficha_patologias
    ADD CONSTRAINT ficha_patologias_patologia_id_fkey FOREIGN KEY (patologia_id) REFERENCES salud.catalogo_patologias(id);


--
-- Name: fichas_tecnicas fichas_tecnicas_persona_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.fichas_tecnicas
    ADD CONSTRAINT fichas_tecnicas_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: fichas_tecnicas fichas_tecnicas_registrado_por_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.fichas_tecnicas
    ADD CONSTRAINT fichas_tecnicas_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES seguridad.usuarios(id);


--
-- Name: fichas_tecnicas fichas_tecnicas_sede_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: -
--

ALTER TABLE ONLY salud.fichas_tecnicas
    ADD CONSTRAINT fichas_tecnicas_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id);


--
-- Name: usuario_roles usuario_roles_rol_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_roles
    ADD CONSTRAINT usuario_roles_rol_id_fkey FOREIGN KEY (rol_id) REFERENCES seguridad.roles(id) ON DELETE CASCADE;


--
-- Name: usuario_roles usuario_roles_usuario_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_roles
    ADD CONSTRAINT usuario_roles_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(id) ON DELETE CASCADE;


--
-- Name: usuario_sedes usuario_sedes_sede_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_sedes
    ADD CONSTRAINT usuario_sedes_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id) ON DELETE CASCADE;


--
-- Name: usuario_sedes usuario_sedes_usuario_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuario_sedes
    ADD CONSTRAINT usuario_sedes_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(id) ON DELETE CASCADE;


--
-- Name: usuarios usuarios_persona_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: -
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT usuarios_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id);


--
-- Name: membresia_precios_sede membresia_precios_sede_membresia_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.membresia_precios_sede
    ADD CONSTRAINT membresia_precios_sede_membresia_id_fkey FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id) ON DELETE CASCADE;


--
-- Name: membresia_precios_sede membresia_precios_sede_sede_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.membresia_precios_sede
    ADD CONSTRAINT membresia_precios_sede_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id) ON DELETE CASCADE;


--
-- Name: socio_membresias socio_membresias_estado_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_estado_id_fkey FOREIGN KEY (estado_id) REFERENCES core.estados(id);


--
-- Name: socio_membresias socio_membresias_membresia_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_membresia_id_fkey FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);


--
-- Name: socio_membresias socio_membresias_sede_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id);


--
-- Name: socio_membresias socio_membresias_socio_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_socio_id_fkey FOREIGN KEY (socio_id) REFERENCES socios.socios(id) ON DELETE CASCADE;


--
-- Name: socios socios_estado_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_estado_id_fkey FOREIGN KEY (estado_id) REFERENCES core.estados(id);


--
-- Name: socios socios_persona_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: socios socios_sede_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: -
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id);


--
-- Name: auth_menu_items auth_menu_items_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT auth_menu_items_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_menu_items auth_menu_items_parent_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT auth_menu_items_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES train_gimnasio.auth_menu_items(id) ON DELETE CASCADE;


--
-- Name: auth_menu_items auth_menu_items_permiso_requerido_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT auth_menu_items_permiso_requerido_id_fkey FOREIGN KEY (permiso_requerido_id) REFERENCES train_gimnasio.auth_permisos(id);


--
-- Name: auth_permisos auth_permisos_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_permisos
    ADD CONSTRAINT auth_permisos_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_rol_permisos auth_rol_permisos_permiso_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos
    ADD CONSTRAINT auth_rol_permisos_permiso_id_fkey FOREIGN KEY (permiso_id) REFERENCES train_gimnasio.auth_permisos(id) ON DELETE CASCADE;


--
-- Name: auth_rol_permisos auth_rol_permisos_rol_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos
    ADD CONSTRAINT auth_rol_permisos_rol_id_fkey FOREIGN KEY (rol_id) REFERENCES train_gimnasio.auth_roles(id) ON DELETE CASCADE;


--
-- Name: auth_roles auth_roles_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_roles
    ADD CONSTRAINT auth_roles_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_tokens_acceso auth_tokens_acceso_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso
    ADD CONSTRAINT auth_tokens_acceso_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_tokens_acceso auth_tokens_acceso_usuario_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso
    ADD CONSTRAINT auth_tokens_acceso_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES train_gimnasio.auth_usuarios(id) ON DELETE CASCADE;


--
-- Name: auth_usuario_roles auth_usuario_roles_rol_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles
    ADD CONSTRAINT auth_usuario_roles_rol_id_fkey FOREIGN KEY (rol_id) REFERENCES train_gimnasio.auth_roles(id) ON DELETE CASCADE;


--
-- Name: auth_usuario_roles auth_usuario_roles_usuario_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles
    ADD CONSTRAINT auth_usuario_roles_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES train_gimnasio.auth_usuarios(id) ON DELETE CASCADE;


--
-- Name: auth_usuarios auth_usuarios_actualizado_por_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_actualizado_por_id_fkey FOREIGN KEY (updated_id_user) REFERENCES train_gimnasio.auth_usuarios(id);


--
-- Name: auth_usuarios auth_usuarios_creado_por_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_creado_por_id_fkey FOREIGN KEY (created_id_user) REFERENCES train_gimnasio.auth_usuarios(id);


--
-- Name: auth_usuarios auth_usuarios_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_usuarios auth_usuarios_persona_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES train_gimnasio.personas(id) ON DELETE SET NULL;


--
-- Name: estados estados_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.estados
    ADD CONSTRAINT estados_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: horarios_gym fk_horarios_tipo_servicio; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.horarios_gym
    ADD CONSTRAINT fk_horarios_tipo_servicio FOREIGN KEY (tipo_servicio_id) REFERENCES train_gimnasio.tipos_servicios(id);


--
-- Name: reservas_gym fk_reservas_servicio; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT fk_reservas_servicio FOREIGN KEY (tipo_servicio_id) REFERENCES train_gimnasio.tipos_servicios(id);


--
-- Name: reservas_gym fk_reservas_tipo_servicio; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT fk_reservas_tipo_servicio FOREIGN KEY (tipo_servicio_id) REFERENCES train_gimnasio.tipos_servicios(id);


--
-- Name: tipos_servicios fk_tipos_servicios_categoria; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.tipos_servicios
    ADD CONSTRAINT fk_tipos_servicios_categoria FOREIGN KEY (categoria_id) REFERENCES train_gimnasio.categoria_servicios(id);


--
-- Name: horarios_gym_dias horarios_gym_dias_horario_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.horarios_gym_dias
    ADD CONSTRAINT horarios_gym_dias_horario_fkey FOREIGN KEY (horario_id) REFERENCES train_gimnasio.horarios_gym(id) ON DELETE CASCADE;


--
-- Name: horarios_gym horarios_gym_sede_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.horarios_gym
    ADD CONSTRAINT horarios_gym_sede_fkey FOREIGN KEY (sede_id) REFERENCES train_gimnasio.sedes(id);


--
-- Name: personas personas_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.personas
    ADD CONSTRAINT personas_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: reservas_gym reservas_horario_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT reservas_horario_fkey FOREIGN KEY (horario_id) REFERENCES train_gimnasio.horarios_gym(id);


--
-- Name: reservas_gym reservas_sede_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT reservas_sede_fkey FOREIGN KEY (sede_id) REFERENCES train_gimnasio.sedes(id);


--
-- Name: sedes sedes_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.sedes
    ADD CONSTRAINT sedes_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: usuario_sedes usuario_sedes_sede_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes
    ADD CONSTRAINT usuario_sedes_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES train_gimnasio.sedes(id) ON DELETE CASCADE;


--
-- Name: usuario_sedes usuario_sedes_usuario_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: -
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes
    ADD CONSTRAINT usuario_sedes_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES train_gimnasio.auth_usuarios(id) ON DELETE CASCADE;


--
-- Name: devolucion_detalles devolucion_detalles_devolucion_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_devolucion_id_fkey FOREIGN KEY (devolucion_id) REFERENCES ventas.devoluciones(id) ON DELETE CASCADE;


--
-- Name: devolucion_detalles devolucion_detalles_membresia_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_membresia_id_fkey FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);


--
-- Name: devolucion_detalles devolucion_detalles_movimiento_inventario_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_movimiento_inventario_id_fkey FOREIGN KEY (movimiento_inventario_id) REFERENCES inventario.movimientos_inventario(id);


--
-- Name: devolucion_detalles devolucion_detalles_producto_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: devolucion_detalles devolucion_detalles_venta_detalle_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_venta_detalle_id_fkey FOREIGN KEY (venta_detalle_id) REFERENCES ventas.venta_detalles(id);


--
-- Name: devoluciones devoluciones_created_by_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_created_by_fkey FOREIGN KEY (created_by) REFERENCES seguridad.usuarios(id);


--
-- Name: devoluciones devoluciones_updated_by_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES seguridad.usuarios(id);


--
-- Name: devoluciones devoluciones_venta_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_venta_id_fkey FOREIGN KEY (venta_id) REFERENCES ventas.ventas(id);


--
-- Name: ventas fk_ventas_anulada_by; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT fk_ventas_anulada_by FOREIGN KEY (anulada_by) REFERENCES seguridad.usuarios(id);


--
-- Name: ventas fk_ventas_membresia_id; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT fk_ventas_membresia_id FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);


--
-- Name: ventas fk_ventas_persona_id; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT fk_ventas_persona_id FOREIGN KEY (persona_id) REFERENCES core.personas(id);


--
-- Name: ventas fk_ventas_vendedor_usuario_id; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT fk_ventas_vendedor_usuario_id FOREIGN KEY (vendedor_usuario_id) REFERENCES seguridad.usuarios(id);


--
-- Name: venta_detalles fk_ventas_venta_detalles_membresia_id; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.venta_detalles
    ADD CONSTRAINT fk_ventas_venta_detalles_membresia_id FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);


--
-- Name: venta_detalles venta_detalles_venta_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.venta_detalles
    ADD CONSTRAINT venta_detalles_venta_id_fkey FOREIGN KEY (venta_id) REFERENCES ventas.ventas(id) ON DELETE CASCADE;


--
-- Name: venta_pagos venta_pagos_venta_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: -
--

ALTER TABLE ONLY ventas.venta_pagos
    ADD CONSTRAINT venta_pagos_venta_id_fkey FOREIGN KEY (venta_id) REFERENCES ventas.ventas(id) ON DELETE CASCADE;


--
-- Name: ev_auto_auditar_gym; Type: EVENT TRIGGER; Schema: -; Owner: -
--

CREATE EVENT TRIGGER ev_auto_auditar_gym ON ddl_command_end
         WHEN TAG IN ('CREATE TABLE')
   EXECUTE FUNCTION train_gimnasio.fn_evento_auto_auditar();


--
-- PostgreSQL database dump complete
--

\unrestrict H4WrxUOelGEInyZerDrefVeJQRmhGyN0BxctdJkUYqMUceurN5xgme9gYaVKc8y

