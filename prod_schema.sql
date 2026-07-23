--
-- PostgreSQL database dump
--

\restrict 91WEkSvJeggdigwHGMBr0dJQedJUDiIQtbooduNJH2ZCNmR1oxPY1SPBlmR89Y4

-- Dumped from database version 18.4 (Debian 18.4-1.pgdg13+1)
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
-- Name: auditoria; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA auditoria;


ALTER SCHEMA auditoria OWNER TO postgres;

--
-- Name: core; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA core;


ALTER SCHEMA core OWNER TO postgres;

--
-- Name: entrenamiento; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA entrenamiento;


ALTER SCHEMA entrenamiento OWNER TO postgres;

--
-- Name: inventario; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA inventario;


ALTER SCHEMA inventario OWNER TO postgres;

--
-- Name: salud; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA salud;


ALTER SCHEMA salud OWNER TO postgres;

--
-- Name: seguridad; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA seguridad;


ALTER SCHEMA seguridad OWNER TO postgres;

--
-- Name: socios; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA socios;


ALTER SCHEMA socios OWNER TO postgres;

--
-- Name: train_gimnasio; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA train_gimnasio;


ALTER SCHEMA train_gimnasio OWNER TO postgres;

--
-- Name: ventas; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA ventas;


ALTER SCHEMA ventas OWNER TO postgres;

--
-- Name: fn_evento_auto_auditar(); Type: FUNCTION; Schema: train_gimnasio; Owner: postgres
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


ALTER FUNCTION train_gimnasio.fn_evento_auto_auditar() OWNER TO postgres;

--
-- Name: fn_set_updated_at(); Type: FUNCTION; Schema: train_gimnasio; Owner: postgres
--

CREATE FUNCTION train_gimnasio.fn_set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;


ALTER FUNCTION train_gimnasio.fn_set_updated_at() OWNER TO postgres;

--
-- Name: generar_turnos_disponibles(date, bigint, bigint, bigint, bigint); Type: FUNCTION; Schema: train_gimnasio; Owner: postgres
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


ALTER FUNCTION train_gimnasio.generar_turnos_disponibles(p_fecha date, p_sede_id bigint, p_tipo_servicio_id bigint, p_tipo_usuario bigint, p_estado_cancelado bigint) OWNER TO postgres;

--
-- Name: obtener_turnos_futuros_hoy(bigint, bigint, bigint, integer); Type: FUNCTION; Schema: train_gimnasio; Owner: postgres
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


ALTER FUNCTION train_gimnasio.obtener_turnos_futuros_hoy(p_sede_id bigint, p_tipo_servicio_id bigint, p_tipo_usuario bigint, p_dias_adelante integer) OWNER TO postgres;

--
-- Name: reservar_turno(date, time without time zone, bigint, bigint, bigint, bigint, bigint, bigint); Type: FUNCTION; Schema: train_gimnasio; Owner: postgres
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


ALTER FUNCTION train_gimnasio.reservar_turno(p_fecha date, p_hora time without time zone, p_horario_id bigint, p_sede_id bigint, p_servicio_id bigint, p_user_id bigint, p_estado_reservado bigint, p_estado_cancelado bigint) OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: aud_cambios; Type: TABLE; Schema: auditoria; Owner: postgres
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


ALTER TABLE auditoria.aud_cambios OWNER TO postgres;

--
-- Name: aud_cambios_id_seq; Type: SEQUENCE; Schema: auditoria; Owner: postgres
--

CREATE SEQUENCE auditoria.aud_cambios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE auditoria.aud_cambios_id_seq OWNER TO postgres;

--
-- Name: aud_cambios_id_seq; Type: SEQUENCE OWNED BY; Schema: auditoria; Owner: postgres
--

ALTER SEQUENCE auditoria.aud_cambios_id_seq OWNED BY auditoria.aud_cambios.id;


--
-- Name: estados; Type: TABLE; Schema: core; Owner: postgres
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


ALTER TABLE core.estados OWNER TO postgres;

--
-- Name: estados_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.estados_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.estados_id_seq OWNER TO postgres;

--
-- Name: estados_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.estados_id_seq OWNED BY core.estados.id;


--
-- Name: persona_tipo_detalle; Type: TABLE; Schema: core; Owner: postgres
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


ALTER TABLE core.persona_tipo_detalle OWNER TO postgres;

--
-- Name: persona_tipo_detalle_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.persona_tipo_detalle_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.persona_tipo_detalle_id_seq OWNER TO postgres;

--
-- Name: persona_tipo_detalle_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.persona_tipo_detalle_id_seq OWNED BY core.persona_tipo_detalle.id;


--
-- Name: persona_tipos; Type: TABLE; Schema: core; Owner: postgres
--

CREATE TABLE core.persona_tipos (
    id bigint NOT NULL,
    codigo character varying(30) NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    activo boolean DEFAULT true NOT NULL
);


ALTER TABLE core.persona_tipos OWNER TO postgres;

--
-- Name: persona_tipos_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.persona_tipos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.persona_tipos_id_seq OWNER TO postgres;

--
-- Name: persona_tipos_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.persona_tipos_id_seq OWNED BY core.persona_tipos.id;


--
-- Name: personas; Type: TABLE; Schema: core; Owner: postgres
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


ALTER TABLE core.personas OWNER TO postgres;

--
-- Name: personas_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.personas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.personas_id_seq OWNER TO postgres;

--
-- Name: personas_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.personas_id_seq OWNED BY core.personas.id;


--
-- Name: sedes; Type: TABLE; Schema: core; Owner: postgres
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


ALTER TABLE core.sedes OWNER TO postgres;

--
-- Name: sedes_id_seq; Type: SEQUENCE; Schema: core; Owner: postgres
--

CREATE SEQUENCE core.sedes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE core.sedes_id_seq OWNER TO postgres;

--
-- Name: sedes_id_seq; Type: SEQUENCE OWNED BY; Schema: core; Owner: postgres
--

ALTER SEQUENCE core.sedes_id_seq OWNED BY core.sedes.id;


--
-- Name: ejecuciones; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.ejecuciones OWNER TO postgres;

--
-- Name: ejecuciones_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.ejecuciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.ejecuciones_id_seq OWNER TO postgres;

--
-- Name: ejecuciones_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.ejecuciones_id_seq OWNED BY entrenamiento.ejecuciones.id;


--
-- Name: ejercicios; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.ejercicios OWNER TO postgres;

--
-- Name: ejercicios_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.ejercicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.ejercicios_id_seq OWNER TO postgres;

--
-- Name: ejercicios_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.ejercicios_id_seq OWNED BY entrenamiento.ejercicios.id;


--
-- Name: evaluaciones; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.evaluaciones OWNER TO postgres;

--
-- Name: evaluaciones_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.evaluaciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.evaluaciones_id_seq OWNER TO postgres;

--
-- Name: evaluaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.evaluaciones_id_seq OWNED BY entrenamiento.evaluaciones.id;


--
-- Name: plan_asignaciones; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plan_asignaciones OWNER TO postgres;

--
-- Name: plan_asignaciones_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plan_asignaciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plan_asignaciones_id_seq OWNER TO postgres;

--
-- Name: plan_asignaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plan_asignaciones_id_seq OWNED BY entrenamiento.plan_asignaciones.id;


--
-- Name: plan_bloques; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plan_bloques OWNER TO postgres;

--
-- Name: plan_bloques_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plan_bloques_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plan_bloques_id_seq OWNER TO postgres;

--
-- Name: plan_bloques_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plan_bloques_id_seq OWNED BY entrenamiento.plan_bloques.id;


--
-- Name: plan_dias; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plan_dias OWNER TO postgres;

--
-- Name: plan_dias_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plan_dias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plan_dias_id_seq OWNER TO postgres;

--
-- Name: plan_dias_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plan_dias_id_seq OWNED BY entrenamiento.plan_dias.id;


--
-- Name: plan_ejecuciones; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plan_ejecuciones OWNER TO postgres;

--
-- Name: plan_ejecuciones_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plan_ejecuciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plan_ejecuciones_id_seq OWNER TO postgres;

--
-- Name: plan_ejecuciones_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plan_ejecuciones_id_seq OWNED BY entrenamiento.plan_ejecuciones.id;


--
-- Name: plan_ejercicio_series; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plan_ejercicio_series OWNER TO postgres;

--
-- Name: plan_ejercicio_series_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plan_ejercicio_series_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plan_ejercicio_series_id_seq OWNER TO postgres;

--
-- Name: plan_ejercicio_series_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plan_ejercicio_series_id_seq OWNED BY entrenamiento.plan_ejercicio_series.id;


--
-- Name: plan_ejercicio_transferencias; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plan_ejercicio_transferencias OWNER TO postgres;

--
-- Name: plan_ejercicio_transferencias_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plan_ejercicio_transferencias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plan_ejercicio_transferencias_id_seq OWNER TO postgres;

--
-- Name: plan_ejercicio_transferencias_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plan_ejercicio_transferencias_id_seq OWNED BY entrenamiento.plan_ejercicio_transferencias.id;


--
-- Name: plan_ejercicios; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plan_ejercicios OWNER TO postgres;

--
-- Name: plan_ejercicios_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plan_ejercicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plan_ejercicios_id_seq OWNER TO postgres;

--
-- Name: plan_ejercicios_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plan_ejercicios_id_seq OWNED BY entrenamiento.plan_ejercicios.id;


--
-- Name: plan_transferencia_series; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plan_transferencia_series OWNER TO postgres;

--
-- Name: plan_transferencia_series_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plan_transferencia_series_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plan_transferencia_series_id_seq OWNER TO postgres;

--
-- Name: plan_transferencia_series_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plan_transferencia_series_id_seq OWNED BY entrenamiento.plan_transferencia_series.id;


--
-- Name: planes; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.planes OWNER TO postgres;

--
-- Name: planes_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.planes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.planes_id_seq OWNER TO postgres;

--
-- Name: planes_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.planes_id_seq OWNED BY entrenamiento.planes.id;


--
-- Name: plantilla_semana_bloques; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plantilla_semana_bloques OWNER TO postgres;

--
-- Name: plantilla_semana_bloques_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plantilla_semana_bloques_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plantilla_semana_bloques_id_seq OWNER TO postgres;

--
-- Name: plantilla_semana_bloques_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plantilla_semana_bloques_id_seq OWNED BY entrenamiento.plantilla_semana_bloques.id;


--
-- Name: plantilla_semana_dias; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plantilla_semana_dias OWNER TO postgres;

--
-- Name: plantilla_semana_dias_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plantilla_semana_dias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plantilla_semana_dias_id_seq OWNER TO postgres;

--
-- Name: plantilla_semana_dias_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plantilla_semana_dias_id_seq OWNED BY entrenamiento.plantilla_semana_dias.id;


--
-- Name: plantilla_semana_ejercicio_series; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plantilla_semana_ejercicio_series OWNER TO postgres;

--
-- Name: plantilla_semana_ejercicio_series_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plantilla_semana_ejercicio_series_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plantilla_semana_ejercicio_series_id_seq OWNER TO postgres;

--
-- Name: plantilla_semana_ejercicio_series_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plantilla_semana_ejercicio_series_id_seq OWNED BY entrenamiento.plantilla_semana_ejercicio_series.id;


--
-- Name: plantilla_semana_ejercicio_transferencias; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plantilla_semana_ejercicio_transferencias OWNER TO postgres;

--
-- Name: plantilla_semana_ejercicio_transferencias_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plantilla_semana_ejercicio_transferencias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plantilla_semana_ejercicio_transferencias_id_seq OWNER TO postgres;

--
-- Name: plantilla_semana_ejercicio_transferencias_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plantilla_semana_ejercicio_transferencias_id_seq OWNED BY entrenamiento.plantilla_semana_ejercicio_transferencias.id;


--
-- Name: plantilla_semana_ejercicios; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plantilla_semana_ejercicios OWNER TO postgres;

--
-- Name: plantilla_semana_ejercicios_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plantilla_semana_ejercicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plantilla_semana_ejercicios_id_seq OWNER TO postgres;

--
-- Name: plantilla_semana_ejercicios_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plantilla_semana_ejercicios_id_seq OWNED BY entrenamiento.plantilla_semana_ejercicios.id;


--
-- Name: plantilla_semana_transferencia_series; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plantilla_semana_transferencia_series OWNER TO postgres;

--
-- Name: plantilla_semana_transferencia_series_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plantilla_semana_transferencia_series_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plantilla_semana_transferencia_series_id_seq OWNER TO postgres;

--
-- Name: plantilla_semana_transferencia_series_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plantilla_semana_transferencia_series_id_seq OWNED BY entrenamiento.plantilla_semana_transferencia_series.id;


--
-- Name: plantillas_semanales; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.plantillas_semanales OWNER TO postgres;

--
-- Name: plantillas_semanales_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.plantillas_semanales_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.plantillas_semanales_id_seq OWNER TO postgres;

--
-- Name: plantillas_semanales_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.plantillas_semanales_id_seq OWNED BY entrenamiento.plantillas_semanales.id;


--
-- Name: rm_registros; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.rm_registros OWNER TO postgres;

--
-- Name: rm_registros_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.rm_registros_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.rm_registros_id_seq OWNER TO postgres;

--
-- Name: rm_registros_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.rm_registros_id_seq OWNED BY entrenamiento.rm_registros.id;


--
-- Name: rutina_plantilla_detalles; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.rutina_plantilla_detalles OWNER TO postgres;

--
-- Name: rutina_plantilla_detalles_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.rutina_plantilla_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.rutina_plantilla_detalles_id_seq OWNER TO postgres;

--
-- Name: rutina_plantilla_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.rutina_plantilla_detalles_id_seq OWNED BY entrenamiento.rutina_plantilla_detalles.id;


--
-- Name: rutina_plantillas; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.rutina_plantillas OWNER TO postgres;

--
-- Name: rutina_plantillas_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.rutina_plantillas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.rutina_plantillas_id_seq OWNER TO postgres;

--
-- Name: rutina_plantillas_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.rutina_plantillas_id_seq OWNED BY entrenamiento.rutina_plantillas.id;


--
-- Name: rutinas; Type: TABLE; Schema: entrenamiento; Owner: postgres
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


ALTER TABLE entrenamiento.rutinas OWNER TO postgres;

--
-- Name: rutinas_id_seq; Type: SEQUENCE; Schema: entrenamiento; Owner: postgres
--

CREATE SEQUENCE entrenamiento.rutinas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE entrenamiento.rutinas_id_seq OWNER TO postgres;

--
-- Name: rutinas_id_seq; Type: SEQUENCE OWNED BY; Schema: entrenamiento; Owner: postgres
--

ALTER SEQUENCE entrenamiento.rutinas_id_seq OWNED BY entrenamiento.rutinas.id;


--
-- Name: categorias_producto; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.categorias_producto (
    id bigint NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    estado smallint DEFAULT 1 NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE inventario.categorias_producto OWNER TO postgres;

--
-- Name: categorias_producto_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
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
-- Name: movimientos_inventario; Type: TABLE; Schema: inventario; Owner: postgres
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
    CONSTRAINT ck_movimientos_inventario_tipo CHECK (((tipo_movimiento)::text = ANY (ARRAY[('ENTRADA'::character varying)::text, ('SALIDA'::character varying)::text, ('AJUSTE'::character varying)::text, ('TRANSFERENCIA_SALIDA'::character varying)::text, ('TRANSFERENCIA_ENTRADA'::character varying)::text])))
);


ALTER TABLE inventario.movimientos_inventario OWNER TO postgres;

--
-- Name: movimientos_inventario_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
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
-- Name: producto_lotes; Type: TABLE; Schema: inventario; Owner: postgres
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


ALTER TABLE inventario.producto_lotes OWNER TO postgres;

--
-- Name: producto_lotes_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
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
-- Name: producto_precios; Type: TABLE; Schema: inventario; Owner: postgres
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


ALTER TABLE inventario.producto_precios OWNER TO postgres;

--
-- Name: producto_precios_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
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
-- Name: producto_stock_sede; Type: TABLE; Schema: inventario; Owner: postgres
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


ALTER TABLE inventario.producto_stock_sede OWNER TO postgres;

--
-- Name: producto_stock_sede_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
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
-- Name: productos; Type: TABLE; Schema: inventario; Owner: postgres
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


ALTER TABLE inventario.productos OWNER TO postgres;

--
-- Name: productos_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
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
-- Name: proveedores; Type: TABLE; Schema: inventario; Owner: postgres
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


ALTER TABLE inventario.proveedores OWNER TO postgres;

--
-- Name: proveedores_prov_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.proveedores_prov_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.proveedores_prov_id_seq OWNER TO postgres;

--
-- Name: proveedores_prov_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.proveedores_prov_id_seq OWNED BY inventario.proveedores.prov_id;


--
-- Name: transferencia_detalle; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.transferencia_detalle (
    id bigint NOT NULL,
    transferencia_id bigint NOT NULL,
    producto_id bigint NOT NULL,
    cantidad numeric(12,2) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_transferencia_detalle_cantidad CHECK ((cantidad > (0)::numeric))
);


ALTER TABLE inventario.transferencia_detalle OWNER TO postgres;

--
-- Name: transferencia_detalle_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
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
-- Name: transferencias_inventario; Type: TABLE; Schema: inventario; Owner: postgres
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
    CONSTRAINT ck_transferencias_inventario_estado CHECK (((estado)::text = ANY (ARRAY[('PENDIENTE'::character varying)::text, ('EN_TRANSITO'::character varying)::text, ('RECIBIDA'::character varying)::text, ('CANCELADA'::character varying)::text]))),
    CONSTRAINT ck_transferencias_inventario_sedes_diferentes CHECK ((sede_origen_id <> sede_destino_id))
);


ALTER TABLE inventario.transferencias_inventario OWNER TO postgres;

--
-- Name: transferencias_inventario_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
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
-- Name: cache; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache OWNER TO postgres;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO postgres;

--
-- Name: catalogos; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.catalogos OWNER TO postgres;

--
-- Name: catalogos_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.catalogos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.catalogos_id_seq OWNER TO postgres;

--
-- Name: catalogos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.catalogos_id_seq OWNED BY public.catalogos.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.failed_jobs OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.job_batches OWNER TO postgres;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.jobs OWNER TO postgres;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO postgres;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO postgres;

--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.personal_access_tokens OWNER TO postgres;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.personal_access_tokens_id_seq OWNER TO postgres;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO postgres;

--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: catalogo_patologias; Type: TABLE; Schema: salud; Owner: postgres
--

CREATE TABLE salud.catalogo_patologias (
    id bigint NOT NULL,
    nombre character varying(150) NOT NULL,
    descripcion text,
    activa boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE salud.catalogo_patologias OWNER TO postgres;

--
-- Name: catalogo_patologias_id_seq; Type: SEQUENCE; Schema: salud; Owner: postgres
--

CREATE SEQUENCE salud.catalogo_patologias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE salud.catalogo_patologias_id_seq OWNER TO postgres;

--
-- Name: catalogo_patologias_id_seq; Type: SEQUENCE OWNED BY; Schema: salud; Owner: postgres
--

ALTER SEQUENCE salud.catalogo_patologias_id_seq OWNED BY salud.catalogo_patologias.id;


--
-- Name: ficha_mediciones; Type: TABLE; Schema: salud; Owner: postgres
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


ALTER TABLE salud.ficha_mediciones OWNER TO postgres;

--
-- Name: ficha_mediciones_id_seq; Type: SEQUENCE; Schema: salud; Owner: postgres
--

CREATE SEQUENCE salud.ficha_mediciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE salud.ficha_mediciones_id_seq OWNER TO postgres;

--
-- Name: ficha_mediciones_id_seq; Type: SEQUENCE OWNED BY; Schema: salud; Owner: postgres
--

ALTER SEQUENCE salud.ficha_mediciones_id_seq OWNED BY salud.ficha_mediciones.id;


--
-- Name: ficha_patologias; Type: TABLE; Schema: salud; Owner: postgres
--

CREATE TABLE salud.ficha_patologias (
    id bigint NOT NULL,
    ficha_tecnica_id bigint NOT NULL,
    patologia_id bigint NOT NULL,
    detalle text,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE salud.ficha_patologias OWNER TO postgres;

--
-- Name: ficha_patologias_id_seq; Type: SEQUENCE; Schema: salud; Owner: postgres
--

CREATE SEQUENCE salud.ficha_patologias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE salud.ficha_patologias_id_seq OWNER TO postgres;

--
-- Name: ficha_patologias_id_seq; Type: SEQUENCE OWNED BY; Schema: salud; Owner: postgres
--

ALTER SEQUENCE salud.ficha_patologias_id_seq OWNED BY salud.ficha_patologias.id;


--
-- Name: fichas_tecnicas; Type: TABLE; Schema: salud; Owner: postgres
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


ALTER TABLE salud.fichas_tecnicas OWNER TO postgres;

--
-- Name: fichas_tecnicas_id_seq; Type: SEQUENCE; Schema: salud; Owner: postgres
--

CREATE SEQUENCE salud.fichas_tecnicas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE salud.fichas_tecnicas_id_seq OWNER TO postgres;

--
-- Name: fichas_tecnicas_id_seq; Type: SEQUENCE OWNED BY; Schema: salud; Owner: postgres
--

ALTER SEQUENCE salud.fichas_tecnicas_id_seq OWNED BY salud.fichas_tecnicas.id;


--
-- Name: roles; Type: TABLE; Schema: seguridad; Owner: postgres
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


ALTER TABLE seguridad.roles OWNER TO postgres;

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: seguridad; Owner: postgres
--

CREATE SEQUENCE seguridad.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE seguridad.roles_id_seq OWNER TO postgres;

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: seguridad; Owner: postgres
--

ALTER SEQUENCE seguridad.roles_id_seq OWNED BY seguridad.roles.id;


--
-- Name: usuario_roles; Type: TABLE; Schema: seguridad; Owner: postgres
--

CREATE TABLE seguridad.usuario_roles (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    rol_id bigint NOT NULL,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE seguridad.usuario_roles OWNER TO postgres;

--
-- Name: usuario_roles_id_seq; Type: SEQUENCE; Schema: seguridad; Owner: postgres
--

CREATE SEQUENCE seguridad.usuario_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE seguridad.usuario_roles_id_seq OWNER TO postgres;

--
-- Name: usuario_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: seguridad; Owner: postgres
--

ALTER SEQUENCE seguridad.usuario_roles_id_seq OWNED BY seguridad.usuario_roles.id;


--
-- Name: usuario_sedes; Type: TABLE; Schema: seguridad; Owner: postgres
--

CREATE TABLE seguridad.usuario_sedes (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    sede_id bigint NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


ALTER TABLE seguridad.usuario_sedes OWNER TO postgres;

--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE; Schema: seguridad; Owner: postgres
--

CREATE SEQUENCE seguridad.usuario_sedes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE seguridad.usuario_sedes_id_seq OWNER TO postgres;

--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE OWNED BY; Schema: seguridad; Owner: postgres
--

ALTER SEQUENCE seguridad.usuario_sedes_id_seq OWNED BY seguridad.usuario_sedes.id;


--
-- Name: usuarios; Type: TABLE; Schema: seguridad; Owner: postgres
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


ALTER TABLE seguridad.usuarios OWNER TO postgres;

--
-- Name: usuarios_id_seq; Type: SEQUENCE; Schema: seguridad; Owner: postgres
--

CREATE SEQUENCE seguridad.usuarios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE seguridad.usuarios_id_seq OWNER TO postgres;

--
-- Name: usuarios_id_seq; Type: SEQUENCE OWNED BY; Schema: seguridad; Owner: postgres
--

ALTER SEQUENCE seguridad.usuarios_id_seq OWNED BY seguridad.usuarios.id;


--
-- Name: membresia_precios_sede; Type: TABLE; Schema: socios; Owner: postgres
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


ALTER TABLE socios.membresia_precios_sede OWNER TO postgres;

--
-- Name: membresia_precios_sede_id_seq; Type: SEQUENCE; Schema: socios; Owner: postgres
--

CREATE SEQUENCE socios.membresia_precios_sede_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE socios.membresia_precios_sede_id_seq OWNER TO postgres;

--
-- Name: membresia_precios_sede_id_seq; Type: SEQUENCE OWNED BY; Schema: socios; Owner: postgres
--

ALTER SEQUENCE socios.membresia_precios_sede_id_seq OWNED BY socios.membresia_precios_sede.id;


--
-- Name: membresias; Type: TABLE; Schema: socios; Owner: postgres
--

CREATE TABLE socios.membresias (
    id bigint NOT NULL,
    nombre character varying(120) NOT NULL,
    descripcion text,
    duracion_dias integer NOT NULL,
    precio numeric(12,2) NOT NULL,
    activa boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    facturacion_automatica boolean DEFAULT true NOT NULL
);


ALTER TABLE socios.membresias OWNER TO postgres;

--
-- Name: membresias_id_seq; Type: SEQUENCE; Schema: socios; Owner: postgres
--

CREATE SEQUENCE socios.membresias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE socios.membresias_id_seq OWNER TO postgres;

--
-- Name: membresias_id_seq; Type: SEQUENCE OWNED BY; Schema: socios; Owner: postgres
--

ALTER SEQUENCE socios.membresias_id_seq OWNED BY socios.membresias.id;


--
-- Name: socio_membresias; Type: TABLE; Schema: socios; Owner: postgres
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


ALTER TABLE socios.socio_membresias OWNER TO postgres;

--
-- Name: socio_membresias_id_seq; Type: SEQUENCE; Schema: socios; Owner: postgres
--

CREATE SEQUENCE socios.socio_membresias_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE socios.socio_membresias_id_seq OWNER TO postgres;

--
-- Name: socio_membresias_id_seq; Type: SEQUENCE OWNED BY; Schema: socios; Owner: postgres
--

ALTER SEQUENCE socios.socio_membresias_id_seq OWNED BY socios.socio_membresias.id;


--
-- Name: socios; Type: TABLE; Schema: socios; Owner: postgres
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


ALTER TABLE socios.socios OWNER TO postgres;

--
-- Name: socios_id_seq; Type: SEQUENCE; Schema: socios; Owner: postgres
--

CREATE SEQUENCE socios.socios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE socios.socios_id_seq OWNER TO postgres;

--
-- Name: socios_id_seq; Type: SEQUENCE OWNED BY; Schema: socios; Owner: postgres
--

ALTER SEQUENCE socios.socios_id_seq OWNED BY socios.socios.id;


--
-- Name: auth_menu_items; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.auth_menu_items OWNER TO postgres;

--
-- Name: auth_menu_items_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.auth_menu_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.auth_menu_items_id_seq OWNER TO postgres;

--
-- Name: auth_menu_items_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.auth_menu_items_id_seq OWNED BY train_gimnasio.auth_menu_items.id;


--
-- Name: auth_permisos; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.auth_permisos OWNER TO postgres;

--
-- Name: auth_permisos_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.auth_permisos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.auth_permisos_id_seq OWNER TO postgres;

--
-- Name: auth_permisos_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.auth_permisos_id_seq OWNED BY train_gimnasio.auth_permisos.id;


--
-- Name: auth_rol_permisos; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.auth_rol_permisos (
    id bigint NOT NULL,
    rol_id bigint NOT NULL,
    permiso_id bigint NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT auth_rol_permisos_creado_en_not_null NOT NULL
);


ALTER TABLE train_gimnasio.auth_rol_permisos OWNER TO postgres;

--
-- Name: auth_rol_permisos_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.auth_rol_permisos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.auth_rol_permisos_id_seq OWNER TO postgres;

--
-- Name: auth_rol_permisos_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.auth_rol_permisos_id_seq OWNED BY train_gimnasio.auth_rol_permisos.id;


--
-- Name: auth_roles; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.auth_roles OWNER TO postgres;

--
-- Name: auth_roles_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.auth_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.auth_roles_id_seq OWNER TO postgres;

--
-- Name: auth_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.auth_roles_id_seq OWNED BY train_gimnasio.auth_roles.id;


--
-- Name: auth_tokens_acceso; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.auth_tokens_acceso OWNER TO postgres;

--
-- Name: auth_tokens_acceso_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.auth_tokens_acceso_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.auth_tokens_acceso_id_seq OWNER TO postgres;

--
-- Name: auth_tokens_acceso_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.auth_tokens_acceso_id_seq OWNED BY train_gimnasio.auth_tokens_acceso.id;


--
-- Name: auth_usuario_roles; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.auth_usuario_roles (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    rol_id bigint NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT auth_usuario_roles_creado_en_not_null NOT NULL
);


ALTER TABLE train_gimnasio.auth_usuario_roles OWNER TO postgres;

--
-- Name: auth_usuario_roles_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.auth_usuario_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.auth_usuario_roles_id_seq OWNER TO postgres;

--
-- Name: auth_usuario_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.auth_usuario_roles_id_seq OWNED BY train_gimnasio.auth_usuario_roles.id;


--
-- Name: auth_usuarios; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.auth_usuarios OWNER TO postgres;

--
-- Name: auth_usuarios_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.auth_usuarios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.auth_usuarios_id_seq OWNER TO postgres;

--
-- Name: auth_usuarios_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.auth_usuarios_id_seq OWNED BY train_gimnasio.auth_usuarios.id;


--
-- Name: cache; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE train_gimnasio.cache OWNER TO postgres;

--
-- Name: cache_locks; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE train_gimnasio.cache_locks OWNER TO postgres;

--
-- Name: categoria_servicios; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.categoria_servicios OWNER TO postgres;

--
-- Name: categoria_servicios_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.categoria_servicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.categoria_servicios_id_seq OWNER TO postgres;

--
-- Name: categoria_servicios_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.categoria_servicios_id_seq OWNED BY train_gimnasio.categoria_servicios.id;


--
-- Name: estados; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.estados OWNER TO postgres;

--
-- Name: estados_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.estados_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.estados_id_seq OWNER TO postgres;

--
-- Name: estados_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.estados_id_seq OWNED BY train_gimnasio.estados.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.failed_jobs OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.failed_jobs_id_seq OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.failed_jobs_id_seq OWNED BY train_gimnasio.failed_jobs.id;


--
-- Name: gimnasios; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.gimnasios (
    id bigint NOT NULL,
    nombre character varying(120) NOT NULL,
    ruc character varying(30),
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT gimnasios_creado_en_not_null NOT NULL,
    updated_at timestamp without time zone DEFAULT now() CONSTRAINT gimnasios_actualizado_en_not_null NOT NULL
);


ALTER TABLE train_gimnasio.gimnasios OWNER TO postgres;

--
-- Name: gimnasios_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.gimnasios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.gimnasios_id_seq OWNER TO postgres;

--
-- Name: gimnasios_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.gimnasios_id_seq OWNED BY train_gimnasio.gimnasios.id;


--
-- Name: horarios_gym; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.horarios_gym OWNER TO postgres;

--
-- Name: horarios_gym_dias; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.horarios_gym_dias (
    horario_id bigint NOT NULL,
    dia_semana smallint NOT NULL,
    CONSTRAINT horarios_gym_dias_dia_semana_check CHECK (((dia_semana >= 1) AND (dia_semana <= 7)))
);


ALTER TABLE train_gimnasio.horarios_gym_dias OWNER TO postgres;

--
-- Name: horarios_gym_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.horarios_gym_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.horarios_gym_id_seq OWNER TO postgres;

--
-- Name: horarios_gym_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.horarios_gym_id_seq OWNED BY train_gimnasio.horarios_gym.id;


--
-- Name: job_batches; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.job_batches OWNER TO postgres;

--
-- Name: jobs; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.jobs OWNER TO postgres;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.jobs_id_seq OWNER TO postgres;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.jobs_id_seq OWNED BY train_gimnasio.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE train_gimnasio.migrations OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.migrations_id_seq OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.migrations_id_seq OWNED BY train_gimnasio.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE train_gimnasio.password_reset_tokens OWNER TO postgres;

--
-- Name: personal_access_tokens; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.personal_access_tokens OWNER TO postgres;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.personal_access_tokens_id_seq OWNER TO postgres;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.personal_access_tokens_id_seq OWNED BY train_gimnasio.personal_access_tokens.id;


--
-- Name: personas; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.personas OWNER TO postgres;

--
-- Name: personas_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.personas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.personas_id_seq OWNER TO postgres;

--
-- Name: personas_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.personas_id_seq OWNED BY train_gimnasio.personas.id;


--
-- Name: reservas_gym; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.reservas_gym OWNER TO postgres;

--
-- Name: reservas_gym_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.reservas_gym_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.reservas_gym_id_seq OWNER TO postgres;

--
-- Name: reservas_gym_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.reservas_gym_id_seq OWNED BY train_gimnasio.reservas_gym.id;


--
-- Name: sedes; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.sedes OWNER TO postgres;

--
-- Name: sedes_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.sedes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.sedes_id_seq OWNER TO postgres;

--
-- Name: sedes_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.sedes_id_seq OWNED BY train_gimnasio.sedes.id;


--
-- Name: sessions; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE train_gimnasio.sessions OWNER TO postgres;

--
-- Name: tipos_servicios; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.tipos_servicios OWNER TO postgres;

--
-- Name: tipos_servicios_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.tipos_servicios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.tipos_servicios_id_seq OWNER TO postgres;

--
-- Name: tipos_servicios_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.tipos_servicios_id_seq OWNED BY train_gimnasio.tipos_servicios.id;


--
-- Name: users; Type: TABLE; Schema: train_gimnasio; Owner: postgres
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


ALTER TABLE train_gimnasio.users OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.users_id_seq OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.users_id_seq OWNED BY train_gimnasio.users.id;


--
-- Name: usuario_sedes; Type: TABLE; Schema: train_gimnasio; Owner: postgres
--

CREATE TABLE train_gimnasio.usuario_sedes (
    id bigint NOT NULL,
    usuario_id bigint NOT NULL,
    sede_id bigint NOT NULL,
    es_principal boolean DEFAULT false NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() CONSTRAINT usuario_sedes_creado_en_not_null NOT NULL
);


ALTER TABLE train_gimnasio.usuario_sedes OWNER TO postgres;

--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE; Schema: train_gimnasio; Owner: postgres
--

CREATE SEQUENCE train_gimnasio.usuario_sedes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE train_gimnasio.usuario_sedes_id_seq OWNER TO postgres;

--
-- Name: usuario_sedes_id_seq; Type: SEQUENCE OWNED BY; Schema: train_gimnasio; Owner: postgres
--

ALTER SEQUENCE train_gimnasio.usuario_sedes_id_seq OWNED BY train_gimnasio.usuario_sedes.id;


--
-- Name: devolucion_detalles; Type: TABLE; Schema: ventas; Owner: postgres
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


ALTER TABLE ventas.devolucion_detalles OWNER TO postgres;

--
-- Name: devolucion_detalles_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.devolucion_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.devolucion_detalles_id_seq OWNER TO postgres;

--
-- Name: devolucion_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.devolucion_detalles_id_seq OWNED BY ventas.devolucion_detalles.id;


--
-- Name: devoluciones; Type: TABLE; Schema: ventas; Owner: postgres
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


ALTER TABLE ventas.devoluciones OWNER TO postgres;

--
-- Name: devoluciones_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.devoluciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.devoluciones_id_seq OWNER TO postgres;

--
-- Name: devoluciones_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.devoluciones_id_seq OWNED BY ventas.devoluciones.id;


--
-- Name: venta_detalles; Type: TABLE; Schema: ventas; Owner: postgres
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


ALTER TABLE ventas.venta_detalles OWNER TO postgres;

--
-- Name: venta_detalles_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.venta_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.venta_detalles_id_seq OWNER TO postgres;

--
-- Name: venta_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.venta_detalles_id_seq OWNED BY ventas.venta_detalles.id;


--
-- Name: venta_pagos; Type: TABLE; Schema: ventas; Owner: postgres
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


ALTER TABLE ventas.venta_pagos OWNER TO postgres;

--
-- Name: venta_pagos_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.venta_pagos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.venta_pagos_id_seq OWNER TO postgres;

--
-- Name: venta_pagos_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.venta_pagos_id_seq OWNED BY ventas.venta_pagos.id;


--
-- Name: ventas; Type: TABLE; Schema: ventas; Owner: postgres
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


ALTER TABLE ventas.ventas OWNER TO postgres;

--
-- Name: ventas_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.ventas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.ventas_id_seq OWNER TO postgres;

--
-- Name: ventas_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.ventas_id_seq OWNED BY ventas.ventas.id;


--
-- Name: aud_cambios id; Type: DEFAULT; Schema: auditoria; Owner: postgres
--

ALTER TABLE ONLY auditoria.aud_cambios ALTER COLUMN id SET DEFAULT nextval('auditoria.aud_cambios_id_seq'::regclass);


--
-- Name: estados id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.estados ALTER COLUMN id SET DEFAULT nextval('core.estados_id_seq'::regclass);


--
-- Name: persona_tipo_detalle id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.persona_tipo_detalle ALTER COLUMN id SET DEFAULT nextval('core.persona_tipo_detalle_id_seq'::regclass);


--
-- Name: persona_tipos id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.persona_tipos ALTER COLUMN id SET DEFAULT nextval('core.persona_tipos_id_seq'::regclass);


--
-- Name: personas id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.personas ALTER COLUMN id SET DEFAULT nextval('core.personas_id_seq'::regclass);


--
-- Name: sedes id; Type: DEFAULT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.sedes ALTER COLUMN id SET DEFAULT nextval('core.sedes_id_seq'::regclass);


--
-- Name: ejecuciones id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.ejecuciones ALTER COLUMN id SET DEFAULT nextval('entrenamiento.ejecuciones_id_seq'::regclass);


--
-- Name: ejercicios id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.ejercicios ALTER COLUMN id SET DEFAULT nextval('entrenamiento.ejercicios_id_seq'::regclass);


--
-- Name: evaluaciones id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.evaluaciones ALTER COLUMN id SET DEFAULT nextval('entrenamiento.evaluaciones_id_seq'::regclass);


--
-- Name: plan_asignaciones id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_asignaciones ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_asignaciones_id_seq'::regclass);


--
-- Name: plan_bloques id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_bloques ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_bloques_id_seq'::regclass);


--
-- Name: plan_dias id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_dias ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_dias_id_seq'::regclass);


--
-- Name: plan_ejecuciones id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_ejecuciones_id_seq'::regclass);


--
-- Name: plan_ejercicio_series id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_series ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_ejercicio_series_id_seq'::regclass);


--
-- Name: plan_ejercicio_transferencias id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_transferencias ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_ejercicio_transferencias_id_seq'::regclass);


--
-- Name: plan_ejercicios id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_ejercicios_id_seq'::regclass);


--
-- Name: plan_transferencia_series id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_transferencia_series ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plan_transferencia_series_id_seq'::regclass);


--
-- Name: planes id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.planes ALTER COLUMN id SET DEFAULT nextval('entrenamiento.planes_id_seq'::regclass);


--
-- Name: plantilla_semana_bloques id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_bloques ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_bloques_id_seq'::regclass);


--
-- Name: plantilla_semana_dias id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_dias ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_dias_id_seq'::regclass);


--
-- Name: plantilla_semana_ejercicio_series id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_series ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_ejercicio_series_id_seq'::regclass);


--
-- Name: plantilla_semana_ejercicio_transferencias id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_transferencias ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_ejercicio_transferencias_id_seq'::regclass);


--
-- Name: plantilla_semana_ejercicios id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicios ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_ejercicios_id_seq'::regclass);


--
-- Name: plantilla_semana_transferencia_series id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_transferencia_series ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantilla_semana_transferencia_series_id_seq'::regclass);


--
-- Name: plantillas_semanales id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantillas_semanales ALTER COLUMN id SET DEFAULT nextval('entrenamiento.plantillas_semanales_id_seq'::regclass);


--
-- Name: rm_registros id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rm_registros ALTER COLUMN id SET DEFAULT nextval('entrenamiento.rm_registros_id_seq'::regclass);


--
-- Name: rutina_plantilla_detalles id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles ALTER COLUMN id SET DEFAULT nextval('entrenamiento.rutina_plantilla_detalles_id_seq'::regclass);


--
-- Name: rutina_plantillas id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutina_plantillas ALTER COLUMN id SET DEFAULT nextval('entrenamiento.rutina_plantillas_id_seq'::regclass);


--
-- Name: rutinas id; Type: DEFAULT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutinas ALTER COLUMN id SET DEFAULT nextval('entrenamiento.rutinas_id_seq'::regclass);


--
-- Name: proveedores prov_id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.proveedores ALTER COLUMN prov_id SET DEFAULT nextval('inventario.proveedores_prov_id_seq'::regclass);


--
-- Name: catalogos id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.catalogos ALTER COLUMN id SET DEFAULT nextval('public.catalogos_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: catalogo_patologias id; Type: DEFAULT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.catalogo_patologias ALTER COLUMN id SET DEFAULT nextval('salud.catalogo_patologias_id_seq'::regclass);


--
-- Name: ficha_mediciones id; Type: DEFAULT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.ficha_mediciones ALTER COLUMN id SET DEFAULT nextval('salud.ficha_mediciones_id_seq'::regclass);


--
-- Name: ficha_patologias id; Type: DEFAULT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.ficha_patologias ALTER COLUMN id SET DEFAULT nextval('salud.ficha_patologias_id_seq'::regclass);


--
-- Name: fichas_tecnicas id; Type: DEFAULT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.fichas_tecnicas ALTER COLUMN id SET DEFAULT nextval('salud.fichas_tecnicas_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles ALTER COLUMN id SET DEFAULT nextval('seguridad.roles_id_seq'::regclass);


--
-- Name: usuario_roles id; Type: DEFAULT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_roles ALTER COLUMN id SET DEFAULT nextval('seguridad.usuario_roles_id_seq'::regclass);


--
-- Name: usuario_sedes id; Type: DEFAULT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_sedes ALTER COLUMN id SET DEFAULT nextval('seguridad.usuario_sedes_id_seq'::regclass);


--
-- Name: usuarios id; Type: DEFAULT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios ALTER COLUMN id SET DEFAULT nextval('seguridad.usuarios_id_seq'::regclass);


--
-- Name: membresia_precios_sede id; Type: DEFAULT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.membresia_precios_sede ALTER COLUMN id SET DEFAULT nextval('socios.membresia_precios_sede_id_seq'::regclass);


--
-- Name: membresias id; Type: DEFAULT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.membresias ALTER COLUMN id SET DEFAULT nextval('socios.membresias_id_seq'::regclass);


--
-- Name: socio_membresias id; Type: DEFAULT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socio_membresias ALTER COLUMN id SET DEFAULT nextval('socios.socio_membresias_id_seq'::regclass);


--
-- Name: socios id; Type: DEFAULT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socios ALTER COLUMN id SET DEFAULT nextval('socios.socios_id_seq'::regclass);


--
-- Name: auth_menu_items id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_menu_items_id_seq'::regclass);


--
-- Name: auth_permisos id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_permisos ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_permisos_id_seq'::regclass);


--
-- Name: auth_rol_permisos id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_rol_permisos_id_seq'::regclass);


--
-- Name: auth_roles id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_roles ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_roles_id_seq'::regclass);


--
-- Name: auth_tokens_acceso id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_tokens_acceso_id_seq'::regclass);


--
-- Name: auth_usuario_roles id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_usuario_roles_id_seq'::regclass);


--
-- Name: auth_usuarios id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.auth_usuarios_id_seq'::regclass);


--
-- Name: categoria_servicios id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.categoria_servicios ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.categoria_servicios_id_seq'::regclass);


--
-- Name: estados id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.estados ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.estados_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.failed_jobs ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.failed_jobs_id_seq'::regclass);


--
-- Name: gimnasios id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.gimnasios ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.gimnasios_id_seq'::regclass);


--
-- Name: horarios_gym id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.horarios_gym ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.horarios_gym_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.jobs ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.migrations ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.personal_access_tokens_id_seq'::regclass);


--
-- Name: personas id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.personas ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.personas_id_seq'::regclass);


--
-- Name: reservas_gym id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.reservas_gym ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.reservas_gym_id_seq'::regclass);


--
-- Name: sedes id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.sedes ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.sedes_id_seq'::regclass);


--
-- Name: tipos_servicios id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.tipos_servicios ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.tipos_servicios_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.users ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.users_id_seq'::regclass);


--
-- Name: usuario_sedes id; Type: DEFAULT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes ALTER COLUMN id SET DEFAULT nextval('train_gimnasio.usuario_sedes_id_seq'::regclass);


--
-- Name: devolucion_detalles id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devolucion_detalles ALTER COLUMN id SET DEFAULT nextval('ventas.devolucion_detalles_id_seq'::regclass);


--
-- Name: devoluciones id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones ALTER COLUMN id SET DEFAULT nextval('ventas.devoluciones_id_seq'::regclass);


--
-- Name: venta_detalles id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.venta_detalles ALTER COLUMN id SET DEFAULT nextval('ventas.venta_detalles_id_seq'::regclass);


--
-- Name: venta_pagos id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.venta_pagos ALTER COLUMN id SET DEFAULT nextval('ventas.venta_pagos_id_seq'::regclass);


--
-- Name: ventas id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ventas ALTER COLUMN id SET DEFAULT nextval('ventas.ventas_id_seq'::regclass);


--
-- Name: aud_cambios aud_cambios_pkey; Type: CONSTRAINT; Schema: auditoria; Owner: postgres
--

ALTER TABLE ONLY auditoria.aud_cambios
    ADD CONSTRAINT aud_cambios_pkey PRIMARY KEY (id);


--
-- Name: estados estados_codigo_key; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.estados
    ADD CONSTRAINT estados_codigo_key UNIQUE (codigo);


--
-- Name: estados estados_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.estados
    ADD CONSTRAINT estados_pkey PRIMARY KEY (id);


--
-- Name: persona_tipo_detalle persona_tipo_detalle_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.persona_tipo_detalle
    ADD CONSTRAINT persona_tipo_detalle_pkey PRIMARY KEY (id);


--
-- Name: persona_tipos persona_tipos_codigo_key; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.persona_tipos
    ADD CONSTRAINT persona_tipos_codigo_key UNIQUE (codigo);


--
-- Name: persona_tipos persona_tipos_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.persona_tipos
    ADD CONSTRAINT persona_tipos_pkey PRIMARY KEY (id);


--
-- Name: personas personas_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.personas
    ADD CONSTRAINT personas_pkey PRIMARY KEY (id);


--
-- Name: sedes sedes_pkey; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.sedes
    ADD CONSTRAINT sedes_pkey PRIMARY KEY (id);


--
-- Name: persona_tipo_detalle uq_core_persona_tipo; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.persona_tipo_detalle
    ADD CONSTRAINT uq_core_persona_tipo UNIQUE (persona_id, tipo_id);


--
-- Name: personas uq_core_personas_identificacion; Type: CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.personas
    ADD CONSTRAINT uq_core_personas_identificacion UNIQUE (tipo_identificacion, numero_identificacion);


--
-- Name: ejecuciones ejecuciones_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.ejecuciones
    ADD CONSTRAINT ejecuciones_pkey PRIMARY KEY (id);


--
-- Name: ejecuciones ejecuciones_unique_rutina_fecha; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.ejecuciones
    ADD CONSTRAINT ejecuciones_unique_rutina_fecha UNIQUE (rutina_id, fecha_ejecucion);


--
-- Name: ejercicios ejercicios_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.ejercicios
    ADD CONSTRAINT ejercicios_pkey PRIMARY KEY (id);


--
-- Name: evaluaciones evaluaciones_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.evaluaciones
    ADD CONSTRAINT evaluaciones_pkey PRIMARY KEY (id);


--
-- Name: plan_asignaciones plan_asignaciones_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_asignaciones
    ADD CONSTRAINT plan_asignaciones_pkey PRIMARY KEY (id);


--
-- Name: plan_bloques plan_bloques_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_bloques
    ADD CONSTRAINT plan_bloques_pkey PRIMARY KEY (id);


--
-- Name: plan_dias plan_dias_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_dias
    ADD CONSTRAINT plan_dias_pkey PRIMARY KEY (id);


--
-- Name: plan_dias plan_dias_plan_semana_dia_unique; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_dias
    ADD CONSTRAINT plan_dias_plan_semana_dia_unique UNIQUE (plan_id, semana, dia);


--
-- Name: plan_ejecuciones plan_ejecuciones_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones
    ADD CONSTRAINT plan_ejecuciones_pkey PRIMARY KEY (id);


--
-- Name: plan_ejecuciones plan_ejecuciones_unique_ejercicio_fecha; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones
    ADD CONSTRAINT plan_ejecuciones_unique_ejercicio_fecha UNIQUE (plan_ejercicio_id, fecha_ejecucion);


--
-- Name: plan_ejercicio_series plan_ejercicio_series_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_series
    ADD CONSTRAINT plan_ejercicio_series_pkey PRIMARY KEY (id);


--
-- Name: plan_ejercicio_transferencias plan_ejercicio_transferencias_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_transferencias
    ADD CONSTRAINT plan_ejercicio_transferencias_pkey PRIMARY KEY (id);


--
-- Name: plan_ejercicios plan_ejercicios_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios
    ADD CONSTRAINT plan_ejercicios_pkey PRIMARY KEY (id);


--
-- Name: plan_transferencia_series plan_transferencia_series_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_transferencia_series
    ADD CONSTRAINT plan_transferencia_series_pkey PRIMARY KEY (id);


--
-- Name: planes planes_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.planes
    ADD CONSTRAINT planes_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_bloques plantilla_semana_bloques_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_bloques
    ADD CONSTRAINT plantilla_semana_bloques_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_dias plantilla_semana_dias_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_dias
    ADD CONSTRAINT plantilla_semana_dias_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_dias plantilla_semana_dias_unique; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_dias
    ADD CONSTRAINT plantilla_semana_dias_unique UNIQUE (plantilla_id, orden_dia, dia);


--
-- Name: plantilla_semana_ejercicio_series plantilla_semana_ejercicio_series_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_series
    ADD CONSTRAINT plantilla_semana_ejercicio_series_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_ejercicio_transferencias plantilla_semana_ejercicio_transferencias_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_transferencias
    ADD CONSTRAINT plantilla_semana_ejercicio_transferencias_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_ejercicios plantilla_semana_ejercicios_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicios
    ADD CONSTRAINT plantilla_semana_ejercicios_pkey PRIMARY KEY (id);


--
-- Name: plantilla_semana_transferencia_series plantilla_semana_transferencia_series_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_transferencia_series
    ADD CONSTRAINT plantilla_semana_transferencia_series_pkey PRIMARY KEY (id);


--
-- Name: plantillas_semanales plantillas_semanales_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantillas_semanales
    ADD CONSTRAINT plantillas_semanales_pkey PRIMARY KEY (id);


--
-- Name: rm_registros rm_registros_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rm_registros
    ADD CONSTRAINT rm_registros_pkey PRIMARY KEY (id);


--
-- Name: rutina_plantilla_detalles rutina_plantilla_detalles_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles
    ADD CONSTRAINT rutina_plantilla_detalles_pkey PRIMARY KEY (id);


--
-- Name: rutina_plantillas rutina_plantillas_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutina_plantillas
    ADD CONSTRAINT rutina_plantillas_pkey PRIMARY KEY (id);


--
-- Name: rutinas rutinas_pkey; Type: CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutinas
    ADD CONSTRAINT rutinas_pkey PRIMARY KEY (id);


--
-- Name: categorias_producto categorias_producto_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.categorias_producto
    ADD CONSTRAINT categorias_producto_pkey PRIMARY KEY (id);


--
-- Name: movimientos_inventario movimientos_inventario_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_pkey PRIMARY KEY (id);


--
-- Name: producto_lotes producto_lotes_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_lotes
    ADD CONSTRAINT producto_lotes_pkey PRIMARY KEY (id);


--
-- Name: producto_precios producto_precios_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_precios
    ADD CONSTRAINT producto_precios_pkey PRIMARY KEY (id);


--
-- Name: producto_stock_sede producto_stock_sede_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_stock_sede
    ADD CONSTRAINT producto_stock_sede_pkey PRIMARY KEY (id);


--
-- Name: productos productos_codigo_key; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos
    ADD CONSTRAINT productos_codigo_key UNIQUE (codigo);


--
-- Name: productos productos_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos
    ADD CONSTRAINT productos_pkey PRIMARY KEY (id);


--
-- Name: proveedores proveedores_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.proveedores
    ADD CONSTRAINT proveedores_pkey PRIMARY KEY (prov_id);


--
-- Name: transferencia_detalle transferencia_detalle_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.transferencia_detalle
    ADD CONSTRAINT transferencia_detalle_pkey PRIMARY KEY (id);


--
-- Name: transferencias_inventario transferencias_inventario_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.transferencias_inventario
    ADD CONSTRAINT transferencias_inventario_pkey PRIMARY KEY (id);


--
-- Name: producto_lotes uq_producto_lotes; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_lotes
    ADD CONSTRAINT uq_producto_lotes UNIQUE (producto_id, sede_id, codigo_lote);


--
-- Name: producto_stock_sede uq_producto_stock_sede; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_stock_sede
    ADD CONSTRAINT uq_producto_stock_sede UNIQUE (producto_id, sede_id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: catalogos catalogos_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.catalogos
    ADD CONSTRAINT catalogos_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: catalogos public_catalogos_grupo_codigo_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.catalogos
    ADD CONSTRAINT public_catalogos_grupo_codigo_unique UNIQUE (grupo, codigo);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: catalogo_patologias catalogo_patologias_nombre_key; Type: CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.catalogo_patologias
    ADD CONSTRAINT catalogo_patologias_nombre_key UNIQUE (nombre);


--
-- Name: catalogo_patologias catalogo_patologias_pkey; Type: CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.catalogo_patologias
    ADD CONSTRAINT catalogo_patologias_pkey PRIMARY KEY (id);


--
-- Name: ficha_mediciones ficha_mediciones_pkey; Type: CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.ficha_mediciones
    ADD CONSTRAINT ficha_mediciones_pkey PRIMARY KEY (id);


--
-- Name: ficha_patologias ficha_patologias_pkey; Type: CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.ficha_patologias
    ADD CONSTRAINT ficha_patologias_pkey PRIMARY KEY (id);


--
-- Name: fichas_tecnicas fichas_tecnicas_pkey; Type: CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.fichas_tecnicas
    ADD CONSTRAINT fichas_tecnicas_pkey PRIMARY KEY (id);


--
-- Name: roles roles_codigo_key; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles
    ADD CONSTRAINT roles_codigo_key UNIQUE (codigo);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: usuario_roles uq_seguridad_usuario_rol; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_roles
    ADD CONSTRAINT uq_seguridad_usuario_rol UNIQUE (usuario_id, rol_id);


--
-- Name: usuario_sedes uq_seguridad_usuario_sede; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_sedes
    ADD CONSTRAINT uq_seguridad_usuario_sede UNIQUE (usuario_id, sede_id);


--
-- Name: usuario_roles usuario_roles_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_roles
    ADD CONSTRAINT usuario_roles_pkey PRIMARY KEY (id);


--
-- Name: usuario_sedes usuario_sedes_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_sedes
    ADD CONSTRAINT usuario_sedes_pkey PRIMARY KEY (id);


--
-- Name: usuarios usuarios_email_key; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT usuarios_email_key UNIQUE (email);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id);


--
-- Name: membresia_precios_sede membresia_precios_sede_pkey; Type: CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.membresia_precios_sede
    ADD CONSTRAINT membresia_precios_sede_pkey PRIMARY KEY (id);


--
-- Name: membresias membresias_pkey; Type: CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.membresias
    ADD CONSTRAINT membresias_pkey PRIMARY KEY (id);


--
-- Name: socio_membresias socio_membresias_pkey; Type: CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_pkey PRIMARY KEY (id);


--
-- Name: socios socios_codigo_socio_key; Type: CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_codigo_socio_key UNIQUE (codigo_socio);


--
-- Name: socios socios_persona_id_key; Type: CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_persona_id_key UNIQUE (persona_id);


--
-- Name: socios socios_pkey; Type: CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_pkey PRIMARY KEY (id);


--
-- Name: auth_menu_items auth_menu_items_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT auth_menu_items_pkey PRIMARY KEY (id);


--
-- Name: auth_permisos auth_permisos_gimnasio_id_codigo_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_permisos
    ADD CONSTRAINT auth_permisos_gimnasio_id_codigo_key UNIQUE (gimnasio_id, codigo);


--
-- Name: auth_permisos auth_permisos_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_permisos
    ADD CONSTRAINT auth_permisos_pkey PRIMARY KEY (id);


--
-- Name: auth_rol_permisos auth_rol_permisos_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos
    ADD CONSTRAINT auth_rol_permisos_pkey PRIMARY KEY (id);


--
-- Name: auth_rol_permisos auth_rol_permisos_rol_id_permiso_id_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos
    ADD CONSTRAINT auth_rol_permisos_rol_id_permiso_id_key UNIQUE (rol_id, permiso_id);


--
-- Name: auth_roles auth_roles_gimnasio_id_codigo_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_roles
    ADD CONSTRAINT auth_roles_gimnasio_id_codigo_key UNIQUE (gimnasio_id, codigo);


--
-- Name: auth_roles auth_roles_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_roles
    ADD CONSTRAINT auth_roles_pkey PRIMARY KEY (id);


--
-- Name: auth_tokens_acceso auth_tokens_acceso_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso
    ADD CONSTRAINT auth_tokens_acceso_pkey PRIMARY KEY (id);


--
-- Name: auth_tokens_acceso auth_tokens_acceso_token_hash_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso
    ADD CONSTRAINT auth_tokens_acceso_token_hash_key UNIQUE (token_hash);


--
-- Name: auth_usuario_roles auth_usuario_roles_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles
    ADD CONSTRAINT auth_usuario_roles_pkey PRIMARY KEY (id);


--
-- Name: auth_usuario_roles auth_usuario_roles_usuario_id_rol_id_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles
    ADD CONSTRAINT auth_usuario_roles_usuario_id_rol_id_key UNIQUE (usuario_id, rol_id);


--
-- Name: auth_usuarios auth_usuarios_gimnasio_id_email_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_gimnasio_id_email_key UNIQUE (gimnasio_id, email);


--
-- Name: auth_usuarios auth_usuarios_gimnasio_id_persona_id_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_gimnasio_id_persona_id_key UNIQUE (gimnasio_id, persona_id);


--
-- Name: auth_usuarios auth_usuarios_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: categoria_servicios categoria_servicios_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.categoria_servicios
    ADD CONSTRAINT categoria_servicios_pkey PRIMARY KEY (id);


--
-- Name: estados estados_gimnasio_tipo_codigo_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.estados
    ADD CONSTRAINT estados_gimnasio_tipo_codigo_key UNIQUE (gimnasio_id, tipo, codigo);


--
-- Name: estados estados_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.estados
    ADD CONSTRAINT estados_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: gimnasios gimnasios_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.gimnasios
    ADD CONSTRAINT gimnasios_pkey PRIMARY KEY (id);


--
-- Name: horarios_gym_dias horarios_gym_dias_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.horarios_gym_dias
    ADD CONSTRAINT horarios_gym_dias_pkey PRIMARY KEY (horario_id, dia_semana);


--
-- Name: horarios_gym horarios_gym_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.horarios_gym
    ADD CONSTRAINT horarios_gym_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: personas personas_gimnasio_id_cedula_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.personas
    ADD CONSTRAINT personas_gimnasio_id_cedula_key UNIQUE (gimnasio_id, cedula);


--
-- Name: personas personas_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.personas
    ADD CONSTRAINT personas_pkey PRIMARY KEY (id);


--
-- Name: reservas_gym reservas_gym_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT reservas_gym_pkey PRIMARY KEY (id);


--
-- Name: sedes sedes_gimnasio_id_nombre_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.sedes
    ADD CONSTRAINT sedes_gimnasio_id_nombre_key UNIQUE (gimnasio_id, nombre);


--
-- Name: sedes sedes_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.sedes
    ADD CONSTRAINT sedes_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: tipos_servicios tipos_servicios_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.tipos_servicios
    ADD CONSTRAINT tipos_servicios_pkey PRIMARY KEY (id);


--
-- Name: categoria_servicios uq_categoria_servicios_nombre; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.categoria_servicios
    ADD CONSTRAINT uq_categoria_servicios_nombre UNIQUE (nombre);


--
-- Name: auth_menu_items uq_menu_gym_ruta; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT uq_menu_gym_ruta UNIQUE (gimnasio_id, ruta);


--
-- Name: auth_menu_items uq_menu_item; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT uq_menu_item UNIQUE (gimnasio_id, parent_id, ruta);


--
-- Name: auth_menu_items uq_menu_ruta; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT uq_menu_ruta UNIQUE (gimnasio_id, ruta);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: usuario_sedes usuario_sedes_pkey; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes
    ADD CONSTRAINT usuario_sedes_pkey PRIMARY KEY (id);


--
-- Name: usuario_sedes usuario_sedes_usuario_id_sede_id_key; Type: CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes
    ADD CONSTRAINT usuario_sedes_usuario_id_sede_id_key UNIQUE (usuario_id, sede_id);


--
-- Name: devolucion_detalles devolucion_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_pkey PRIMARY KEY (id);


--
-- Name: devoluciones devoluciones_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_pkey PRIMARY KEY (id);


--
-- Name: venta_detalles venta_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.venta_detalles
    ADD CONSTRAINT venta_detalles_pkey PRIMARY KEY (id);


--
-- Name: venta_pagos venta_pagos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.venta_pagos
    ADD CONSTRAINT venta_pagos_pkey PRIMARY KEY (id);


--
-- Name: ventas ventas_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT ventas_pkey PRIMARY KEY (id);


--
-- Name: idx_aud_cambios_accion_fecha; Type: INDEX; Schema: auditoria; Owner: postgres
--

CREATE INDEX idx_aud_cambios_accion_fecha ON auditoria.aud_cambios USING btree (accion, created_at DESC);


--
-- Name: idx_aud_cambios_actor_fecha; Type: INDEX; Schema: auditoria; Owner: postgres
--

CREATE INDEX idx_aud_cambios_actor_fecha ON auditoria.aud_cambios USING btree (actor_usuario_id, created_at DESC);


--
-- Name: idx_aud_cambios_actor_persona_fecha; Type: INDEX; Schema: auditoria; Owner: postgres
--

CREATE INDEX idx_aud_cambios_actor_persona_fecha ON auditoria.aud_cambios USING btree (actor_persona_id, created_at DESC);


--
-- Name: idx_aud_cambios_created_at; Type: INDEX; Schema: auditoria; Owner: postgres
--

CREATE INDEX idx_aud_cambios_created_at ON auditoria.aud_cambios USING btree (created_at DESC);


--
-- Name: idx_aud_cambios_modulo_fecha; Type: INDEX; Schema: auditoria; Owner: postgres
--

CREATE INDEX idx_aud_cambios_modulo_fecha ON auditoria.aud_cambios USING btree (modulo, created_at DESC);


--
-- Name: idx_aud_cambios_operacion_fecha; Type: INDEX; Schema: auditoria; Owner: postgres
--

CREATE INDEX idx_aud_cambios_operacion_fecha ON auditoria.aud_cambios USING btree (operacion, created_at DESC);


--
-- Name: idx_aud_cambios_request_id; Type: INDEX; Schema: auditoria; Owner: postgres
--

CREATE INDEX idx_aud_cambios_request_id ON auditoria.aud_cambios USING btree (request_id);


--
-- Name: idx_aud_cambios_tabla_registro_fecha; Type: INDEX; Schema: auditoria; Owner: postgres
--

CREATE INDEX idx_aud_cambios_tabla_registro_fecha ON auditoria.aud_cambios USING btree (tabla, registro_id, created_at DESC);


--
-- Name: entrenamiento_ejercicios_nombre_unique_idx; Type: INDEX; Schema: entrenamiento; Owner: postgres
--

CREATE UNIQUE INDEX entrenamiento_ejercicios_nombre_unique_idx ON entrenamiento.ejercicios USING btree (lower(btrim((nombre)::text)));


--
-- Name: plan_bloques_plan_dia_idx; Type: INDEX; Schema: entrenamiento; Owner: postgres
--

CREATE INDEX plan_bloques_plan_dia_idx ON entrenamiento.plan_bloques USING btree (plan_dia_id, orden);


--
-- Name: plan_dias_plan_idx; Type: INDEX; Schema: entrenamiento; Owner: postgres
--

CREATE INDEX plan_dias_plan_idx ON entrenamiento.plan_dias USING btree (plan_id, semana, dia);


--
-- Name: plan_ejercicio_series_ejercicio_idx; Type: INDEX; Schema: entrenamiento; Owner: postgres
--

CREATE INDEX plan_ejercicio_series_ejercicio_idx ON entrenamiento.plan_ejercicio_series USING btree (plan_ejercicio_id, numero_serie);


--
-- Name: plan_ejercicio_transferencias_ejercicio_idx; Type: INDEX; Schema: entrenamiento; Owner: postgres
--

CREATE INDEX plan_ejercicio_transferencias_ejercicio_idx ON entrenamiento.plan_ejercicio_transferencias USING btree (plan_ejercicio_id, orden);


--
-- Name: plan_ejercicios_bloque_idx; Type: INDEX; Schema: entrenamiento; Owner: postgres
--

CREATE INDEX plan_ejercicios_bloque_idx ON entrenamiento.plan_ejercicios USING btree (plan_bloque_id, orden);


--
-- Name: plan_ejercicios_rm_idx; Type: INDEX; Schema: entrenamiento; Owner: postgres
--

CREATE INDEX plan_ejercicios_rm_idx ON entrenamiento.plan_ejercicios USING btree (rm_registro_id);


--
-- Name: plan_transferencia_series_transferencia_idx; Type: INDEX; Schema: entrenamiento; Owner: postgres
--

CREATE INDEX plan_transferencia_series_transferencia_idx ON entrenamiento.plan_transferencia_series USING btree (transferencia_id, numero_serie);


--
-- Name: plantilla_semana_dias_idx; Type: INDEX; Schema: entrenamiento; Owner: postgres
--

CREATE INDEX plantilla_semana_dias_idx ON entrenamiento.plantilla_semana_dias USING btree (plantilla_id, orden_dia);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: idx_seguridad_usuario_sedes_usuario; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_seguridad_usuario_sedes_usuario ON seguridad.usuario_sedes USING btree (usuario_id, activo);


--
-- Name: uq_seguridad_usuarios_cedula; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE UNIQUE INDEX uq_seguridad_usuarios_cedula ON seguridad.usuarios USING btree (cedula);


--
-- Name: idx_membresia_precios_sede_lookup; Type: INDEX; Schema: socios; Owner: postgres
--

CREATE INDEX idx_membresia_precios_sede_lookup ON socios.membresia_precios_sede USING btree (membresia_id, sede_id, activa);


--
-- Name: idx_socio_membresias_cedula; Type: INDEX; Schema: socios; Owner: postgres
--

CREATE INDEX idx_socio_membresias_cedula ON socios.socio_membresias USING btree (cedula);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX cache_expiration_index ON train_gimnasio.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX cache_locks_expiration_index ON train_gimnasio.cache_locks USING btree (expiration);


--
-- Name: idx_auth_usuarios_gym_email; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_auth_usuarios_gym_email ON train_gimnasio.auth_usuarios USING btree (gimnasio_id, email);


--
-- Name: idx_estados_activo; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_estados_activo ON train_gimnasio.estados USING btree (activo);


--
-- Name: idx_estados_gym_tipo; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_estados_gym_tipo ON train_gimnasio.estados USING btree (gimnasio_id, tipo);


--
-- Name: idx_horarios_gym_activo; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_horarios_gym_activo ON train_gimnasio.horarios_gym USING btree (activo);


--
-- Name: idx_horarios_gym_dias_dia; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_horarios_gym_dias_dia ON train_gimnasio.horarios_gym_dias USING btree (dia_semana);


--
-- Name: idx_horarios_gym_sede_servicio; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_horarios_gym_sede_servicio ON train_gimnasio.horarios_gym USING btree (sede_id, tipo_servicio_id);


--
-- Name: idx_horarios_gym_tipo_usuario; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_horarios_gym_tipo_usuario ON train_gimnasio.horarios_gym USING btree (tipo_usuario);


--
-- Name: idx_menu_gym_parent_orden; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_menu_gym_parent_orden ON train_gimnasio.auth_menu_items USING btree (gimnasio_id, parent_id, orden);


--
-- Name: idx_personas_gym_cedula; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_personas_gym_cedula ON train_gimnasio.personas USING btree (gimnasio_id, cedula);


--
-- Name: idx_reservas_cedula; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_reservas_cedula ON train_gimnasio.reservas_gym USING btree (cedula);


--
-- Name: idx_reservas_por_slot; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_reservas_por_slot ON train_gimnasio.reservas_gym USING btree (tipo_servicio_id, horario_id, fecha, hora, estado_id);


--
-- Name: idx_tipos_servicios_categoria; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_tipos_servicios_categoria ON train_gimnasio.tipos_servicios USING btree (categoria_id);


--
-- Name: idx_tipos_servicios_estado; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_tipos_servicios_estado ON train_gimnasio.tipos_servicios USING btree (estado_id);


--
-- Name: idx_tokens_usuario; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX idx_tokens_usuario ON train_gimnasio.auth_tokens_acceso USING btree (usuario_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX jobs_queue_index ON train_gimnasio.jobs USING btree (queue);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX personal_access_tokens_expires_at_index ON train_gimnasio.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON train_gimnasio.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX sessions_last_activity_index ON train_gimnasio.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE INDEX sessions_user_id_index ON train_gimnasio.sessions USING btree (user_id);


--
-- Name: uq_reserva_cedula_slot; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE UNIQUE INDEX uq_reserva_cedula_slot ON train_gimnasio.reservas_gym USING btree (cedula, tipo_servicio_id, horario_id, fecha, hora) WHERE (cedula IS NOT NULL);


--
-- Name: uq_reserva_usuario_slot; Type: INDEX; Schema: train_gimnasio; Owner: postgres
--

CREATE UNIQUE INDEX uq_reserva_usuario_slot ON train_gimnasio.reservas_gym USING btree (user_id, tipo_servicio_id, horario_id, fecha, hora) WHERE (user_id IS NOT NULL);


--
-- Name: idx_ventas_devolucion_detalles_devolucion; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_ventas_devolucion_detalles_devolucion ON ventas.devolucion_detalles USING btree (devolucion_id);


--
-- Name: idx_ventas_devolucion_detalles_venta_detalle; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_ventas_devolucion_detalles_venta_detalle ON ventas.devolucion_detalles USING btree (venta_detalle_id);


--
-- Name: idx_ventas_devoluciones_tipo; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_ventas_devoluciones_tipo ON ventas.devoluciones USING btree (tipo, created_at DESC);


--
-- Name: idx_ventas_devoluciones_venta; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_ventas_devoluciones_venta ON ventas.devoluciones USING btree (venta_id, created_at DESC);


--
-- Name: idx_ventas_persona_estado_pago; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_ventas_persona_estado_pago ON ventas.ventas USING btree (persona_id, estado_pago, fecha_consumo DESC);


--
-- Name: idx_ventas_tipo_venta; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_ventas_tipo_venta ON ventas.ventas USING btree (tipo_venta, fecha_consumo DESC);


--
-- Name: idx_ventas_venta_detalles_tipo_detalle; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_ventas_venta_detalles_tipo_detalle ON ventas.venta_detalles USING btree (tipo_detalle);


--
-- Name: categoria_servicios trg_set_updated_at_categoria_servicios; Type: TRIGGER; Schema: train_gimnasio; Owner: postgres
--

CREATE TRIGGER trg_set_updated_at_categoria_servicios BEFORE UPDATE ON train_gimnasio.categoria_servicios FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: estados trg_set_updated_at_estados; Type: TRIGGER; Schema: train_gimnasio; Owner: postgres
--

CREATE TRIGGER trg_set_updated_at_estados BEFORE UPDATE ON train_gimnasio.estados FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: horarios_gym trg_set_updated_at_horarios_gym; Type: TRIGGER; Schema: train_gimnasio; Owner: postgres
--

CREATE TRIGGER trg_set_updated_at_horarios_gym BEFORE UPDATE ON train_gimnasio.horarios_gym FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: reservas_gym trg_set_updated_at_reservas_gym; Type: TRIGGER; Schema: train_gimnasio; Owner: postgres
--

CREATE TRIGGER trg_set_updated_at_reservas_gym BEFORE UPDATE ON train_gimnasio.reservas_gym FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: tipos_servicios trg_set_updated_at_tipos_servicios; Type: TRIGGER; Schema: train_gimnasio; Owner: postgres
--

CREATE TRIGGER trg_set_updated_at_tipos_servicios BEFORE UPDATE ON train_gimnasio.tipos_servicios FOR EACH ROW EXECUTE FUNCTION train_gimnasio.fn_set_updated_at();


--
-- Name: persona_tipo_detalle persona_tipo_detalle_persona_id_fkey; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.persona_tipo_detalle
    ADD CONSTRAINT persona_tipo_detalle_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: persona_tipo_detalle persona_tipo_detalle_tipo_id_fkey; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.persona_tipo_detalle
    ADD CONSTRAINT persona_tipo_detalle_tipo_id_fkey FOREIGN KEY (tipo_id) REFERENCES core.persona_tipos(id) ON DELETE CASCADE;


--
-- Name: personas personas_estado_id_fkey; Type: FK CONSTRAINT; Schema: core; Owner: postgres
--

ALTER TABLE ONLY core.personas
    ADD CONSTRAINT personas_estado_id_fkey FOREIGN KEY (estado_id) REFERENCES core.estados(id);


--
-- Name: ejecuciones ejecuciones_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.ejecuciones
    ADD CONSTRAINT ejecuciones_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: ejecuciones ejecuciones_rutina_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.ejecuciones
    ADD CONSTRAINT ejecuciones_rutina_id_fkey FOREIGN KEY (rutina_id) REFERENCES entrenamiento.rutinas(id) ON DELETE CASCADE;


--
-- Name: evaluaciones evaluaciones_persona_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.evaluaciones
    ADD CONSTRAINT evaluaciones_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: plan_asignaciones plan_asignaciones_persona_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_asignaciones
    ADD CONSTRAINT plan_asignaciones_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE SET NULL;


--
-- Name: plan_asignaciones plan_asignaciones_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_asignaciones
    ADD CONSTRAINT plan_asignaciones_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: plan_bloques plan_bloques_plan_dia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_bloques
    ADD CONSTRAINT plan_bloques_plan_dia_id_fkey FOREIGN KEY (plan_dia_id) REFERENCES entrenamiento.plan_dias(id) ON DELETE CASCADE;


--
-- Name: plan_dias plan_dias_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_dias
    ADD CONSTRAINT plan_dias_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: plan_ejecuciones plan_ejecuciones_plan_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones
    ADD CONSTRAINT plan_ejecuciones_plan_ejercicio_id_fkey FOREIGN KEY (plan_ejercicio_id) REFERENCES entrenamiento.plan_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plan_ejecuciones plan_ejecuciones_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejecuciones
    ADD CONSTRAINT plan_ejecuciones_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: plan_ejercicio_series plan_ejercicio_series_plan_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_series
    ADD CONSTRAINT plan_ejercicio_series_plan_ejercicio_id_fkey FOREIGN KEY (plan_ejercicio_id) REFERENCES entrenamiento.plan_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plan_ejercicio_transferencias plan_ejercicio_transferencias_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_transferencias
    ADD CONSTRAINT plan_ejercicio_transferencias_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: plan_ejercicio_transferencias plan_ejercicio_transferencias_plan_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicio_transferencias
    ADD CONSTRAINT plan_ejercicio_transferencias_plan_ejercicio_id_fkey FOREIGN KEY (plan_ejercicio_id) REFERENCES entrenamiento.plan_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plan_ejercicios plan_ejercicios_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios
    ADD CONSTRAINT plan_ejercicios_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: plan_ejercicios plan_ejercicios_plan_bloque_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios
    ADD CONSTRAINT plan_ejercicios_plan_bloque_id_fkey FOREIGN KEY (plan_bloque_id) REFERENCES entrenamiento.plan_bloques(id) ON DELETE CASCADE;


--
-- Name: plan_ejercicios plan_ejercicios_rm_registro_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_ejercicios
    ADD CONSTRAINT plan_ejercicios_rm_registro_id_fkey FOREIGN KEY (rm_registro_id) REFERENCES entrenamiento.rm_registros(id) ON DELETE SET NULL;


--
-- Name: plan_transferencia_series plan_transferencia_series_transferencia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plan_transferencia_series
    ADD CONSTRAINT plan_transferencia_series_transferencia_id_fkey FOREIGN KEY (transferencia_id) REFERENCES entrenamiento.plan_ejercicio_transferencias(id) ON DELETE CASCADE;


--
-- Name: planes planes_persona_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.planes
    ADD CONSTRAINT planes_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_bloques plantilla_semana_bloques_plantilla_dia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_bloques
    ADD CONSTRAINT plantilla_semana_bloques_plantilla_dia_id_fkey FOREIGN KEY (plantilla_dia_id) REFERENCES entrenamiento.plantilla_semana_dias(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_dias plantilla_semana_dias_plantilla_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_dias
    ADD CONSTRAINT plantilla_semana_dias_plantilla_id_fkey FOREIGN KEY (plantilla_id) REFERENCES entrenamiento.plantillas_semanales(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_ejercicio_series plantilla_semana_ejercicio_series_plantilla_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_series
    ADD CONSTRAINT plantilla_semana_ejercicio_series_plantilla_ejercicio_id_fkey FOREIGN KEY (plantilla_ejercicio_id) REFERENCES entrenamiento.plantilla_semana_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_ejercicio_transferencias plantilla_semana_ejercicio_transfer_plantilla_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_transferencias
    ADD CONSTRAINT plantilla_semana_ejercicio_transfer_plantilla_ejercicio_id_fkey FOREIGN KEY (plantilla_ejercicio_id) REFERENCES entrenamiento.plantilla_semana_ejercicios(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_ejercicio_transferencias plantilla_semana_ejercicio_transferencias_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicio_transferencias
    ADD CONSTRAINT plantilla_semana_ejercicio_transferencias_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL;


--
-- Name: plantilla_semana_ejercicios plantilla_semana_ejercicios_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicios
    ADD CONSTRAINT plantilla_semana_ejercicios_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL;


--
-- Name: plantilla_semana_ejercicios plantilla_semana_ejercicios_plantilla_bloque_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_ejercicios
    ADD CONSTRAINT plantilla_semana_ejercicios_plantilla_bloque_id_fkey FOREIGN KEY (plantilla_bloque_id) REFERENCES entrenamiento.plantilla_semana_bloques(id) ON DELETE CASCADE;


--
-- Name: plantilla_semana_transferencia_series plantilla_semana_transferencia_series_transferencia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.plantilla_semana_transferencia_series
    ADD CONSTRAINT plantilla_semana_transferencia_series_transferencia_id_fkey FOREIGN KEY (transferencia_id) REFERENCES entrenamiento.plantilla_semana_ejercicio_transferencias(id) ON DELETE CASCADE;


--
-- Name: rm_registros rm_registros_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rm_registros
    ADD CONSTRAINT rm_registros_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: rm_registros rm_registros_persona_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rm_registros
    ADD CONSTRAINT rm_registros_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: rutina_plantilla_detalles rutina_plantilla_detalles_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles
    ADD CONSTRAINT rutina_plantilla_detalles_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: rutina_plantilla_detalles rutina_plantilla_detalles_ejercicio_transferencia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles
    ADD CONSTRAINT rutina_plantilla_detalles_ejercicio_transferencia_id_fkey FOREIGN KEY (ejercicio_transferencia_id) REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL;


--
-- Name: rutina_plantilla_detalles rutina_plantilla_detalles_plantilla_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutina_plantilla_detalles
    ADD CONSTRAINT rutina_plantilla_detalles_plantilla_id_fkey FOREIGN KEY (plantilla_id) REFERENCES entrenamiento.rutina_plantillas(id) ON DELETE CASCADE;


--
-- Name: rutinas rutinas_ejercicio_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutinas
    ADD CONSTRAINT rutinas_ejercicio_id_fkey FOREIGN KEY (ejercicio_id) REFERENCES entrenamiento.ejercicios(id);


--
-- Name: rutinas rutinas_ejercicio_transferencia_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutinas
    ADD CONSTRAINT rutinas_ejercicio_transferencia_id_fkey FOREIGN KEY (ejercicio_transferencia_id) REFERENCES entrenamiento.ejercicios(id) ON DELETE SET NULL;


--
-- Name: rutinas rutinas_plan_id_fkey; Type: FK CONSTRAINT; Schema: entrenamiento; Owner: postgres
--

ALTER TABLE ONLY entrenamiento.rutinas
    ADD CONSTRAINT rutinas_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES entrenamiento.planes(id) ON DELETE CASCADE;


--
-- Name: movimientos_inventario fk_movimientos_inventario_lote; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimientos_inventario
    ADD CONSTRAINT fk_movimientos_inventario_lote FOREIGN KEY (lote_id) REFERENCES inventario.producto_lotes(id);


--
-- Name: movimientos_inventario fk_movimientos_inventario_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimientos_inventario
    ADD CONSTRAINT fk_movimientos_inventario_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: producto_lotes fk_producto_lotes_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_lotes
    ADD CONSTRAINT fk_producto_lotes_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: producto_precios fk_producto_precios_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_precios
    ADD CONSTRAINT fk_producto_precios_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: producto_stock_sede fk_producto_stock_sede_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_stock_sede
    ADD CONSTRAINT fk_producto_stock_sede_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: productos fk_productos_categoria; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos
    ADD CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id) REFERENCES inventario.categorias_producto(id);


--
-- Name: transferencia_detalle fk_transferencia_detalle_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.transferencia_detalle
    ADD CONSTRAINT fk_transferencia_detalle_producto FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: transferencia_detalle fk_transferencia_detalle_transferencia; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.transferencia_detalle
    ADD CONSTRAINT fk_transferencia_detalle_transferencia FOREIGN KEY (transferencia_id) REFERENCES inventario.transferencias_inventario(id) ON DELETE CASCADE;


--
-- Name: ficha_mediciones ficha_mediciones_ficha_tecnica_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.ficha_mediciones
    ADD CONSTRAINT ficha_mediciones_ficha_tecnica_id_fkey FOREIGN KEY (ficha_tecnica_id) REFERENCES salud.fichas_tecnicas(id) ON DELETE CASCADE;


--
-- Name: ficha_patologias ficha_patologias_ficha_tecnica_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.ficha_patologias
    ADD CONSTRAINT ficha_patologias_ficha_tecnica_id_fkey FOREIGN KEY (ficha_tecnica_id) REFERENCES salud.fichas_tecnicas(id) ON DELETE CASCADE;


--
-- Name: ficha_patologias ficha_patologias_patologia_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.ficha_patologias
    ADD CONSTRAINT ficha_patologias_patologia_id_fkey FOREIGN KEY (patologia_id) REFERENCES salud.catalogo_patologias(id);


--
-- Name: fichas_tecnicas fichas_tecnicas_persona_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.fichas_tecnicas
    ADD CONSTRAINT fichas_tecnicas_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: fichas_tecnicas fichas_tecnicas_registrado_por_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.fichas_tecnicas
    ADD CONSTRAINT fichas_tecnicas_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES seguridad.usuarios(id);


--
-- Name: fichas_tecnicas fichas_tecnicas_sede_id_fkey; Type: FK CONSTRAINT; Schema: salud; Owner: postgres
--

ALTER TABLE ONLY salud.fichas_tecnicas
    ADD CONSTRAINT fichas_tecnicas_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id);


--
-- Name: usuario_roles usuario_roles_rol_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_roles
    ADD CONSTRAINT usuario_roles_rol_id_fkey FOREIGN KEY (rol_id) REFERENCES seguridad.roles(id) ON DELETE CASCADE;


--
-- Name: usuario_roles usuario_roles_usuario_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_roles
    ADD CONSTRAINT usuario_roles_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(id) ON DELETE CASCADE;


--
-- Name: usuario_sedes usuario_sedes_sede_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_sedes
    ADD CONSTRAINT usuario_sedes_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id) ON DELETE CASCADE;


--
-- Name: usuario_sedes usuario_sedes_usuario_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuario_sedes
    ADD CONSTRAINT usuario_sedes_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(id) ON DELETE CASCADE;


--
-- Name: usuarios usuarios_persona_id_fkey; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT usuarios_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id);


--
-- Name: membresia_precios_sede membresia_precios_sede_membresia_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.membresia_precios_sede
    ADD CONSTRAINT membresia_precios_sede_membresia_id_fkey FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id) ON DELETE CASCADE;


--
-- Name: membresia_precios_sede membresia_precios_sede_sede_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.membresia_precios_sede
    ADD CONSTRAINT membresia_precios_sede_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id) ON DELETE CASCADE;


--
-- Name: socio_membresias socio_membresias_estado_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_estado_id_fkey FOREIGN KEY (estado_id) REFERENCES core.estados(id);


--
-- Name: socio_membresias socio_membresias_membresia_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_membresia_id_fkey FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);


--
-- Name: socio_membresias socio_membresias_sede_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id);


--
-- Name: socio_membresias socio_membresias_socio_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socio_membresias
    ADD CONSTRAINT socio_membresias_socio_id_fkey FOREIGN KEY (socio_id) REFERENCES socios.socios(id) ON DELETE CASCADE;


--
-- Name: socios socios_estado_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_estado_id_fkey FOREIGN KEY (estado_id) REFERENCES core.estados(id);


--
-- Name: socios socios_persona_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES core.personas(id) ON DELETE CASCADE;


--
-- Name: socios socios_sede_id_fkey; Type: FK CONSTRAINT; Schema: socios; Owner: postgres
--

ALTER TABLE ONLY socios.socios
    ADD CONSTRAINT socios_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES core.sedes(id);


--
-- Name: auth_menu_items auth_menu_items_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT auth_menu_items_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_menu_items auth_menu_items_parent_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT auth_menu_items_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES train_gimnasio.auth_menu_items(id) ON DELETE CASCADE;


--
-- Name: auth_menu_items auth_menu_items_permiso_requerido_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_menu_items
    ADD CONSTRAINT auth_menu_items_permiso_requerido_id_fkey FOREIGN KEY (permiso_requerido_id) REFERENCES train_gimnasio.auth_permisos(id);


--
-- Name: auth_permisos auth_permisos_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_permisos
    ADD CONSTRAINT auth_permisos_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_rol_permisos auth_rol_permisos_permiso_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos
    ADD CONSTRAINT auth_rol_permisos_permiso_id_fkey FOREIGN KEY (permiso_id) REFERENCES train_gimnasio.auth_permisos(id) ON DELETE CASCADE;


--
-- Name: auth_rol_permisos auth_rol_permisos_rol_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_rol_permisos
    ADD CONSTRAINT auth_rol_permisos_rol_id_fkey FOREIGN KEY (rol_id) REFERENCES train_gimnasio.auth_roles(id) ON DELETE CASCADE;


--
-- Name: auth_roles auth_roles_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_roles
    ADD CONSTRAINT auth_roles_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_tokens_acceso auth_tokens_acceso_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso
    ADD CONSTRAINT auth_tokens_acceso_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_tokens_acceso auth_tokens_acceso_usuario_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_tokens_acceso
    ADD CONSTRAINT auth_tokens_acceso_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES train_gimnasio.auth_usuarios(id) ON DELETE CASCADE;


--
-- Name: auth_usuario_roles auth_usuario_roles_rol_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles
    ADD CONSTRAINT auth_usuario_roles_rol_id_fkey FOREIGN KEY (rol_id) REFERENCES train_gimnasio.auth_roles(id) ON DELETE CASCADE;


--
-- Name: auth_usuario_roles auth_usuario_roles_usuario_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuario_roles
    ADD CONSTRAINT auth_usuario_roles_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES train_gimnasio.auth_usuarios(id) ON DELETE CASCADE;


--
-- Name: auth_usuarios auth_usuarios_actualizado_por_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_actualizado_por_id_fkey FOREIGN KEY (updated_id_user) REFERENCES train_gimnasio.auth_usuarios(id);


--
-- Name: auth_usuarios auth_usuarios_creado_por_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_creado_por_id_fkey FOREIGN KEY (created_id_user) REFERENCES train_gimnasio.auth_usuarios(id);


--
-- Name: auth_usuarios auth_usuarios_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: auth_usuarios auth_usuarios_persona_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.auth_usuarios
    ADD CONSTRAINT auth_usuarios_persona_id_fkey FOREIGN KEY (persona_id) REFERENCES train_gimnasio.personas(id) ON DELETE SET NULL;


--
-- Name: estados estados_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.estados
    ADD CONSTRAINT estados_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: horarios_gym fk_horarios_tipo_servicio; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.horarios_gym
    ADD CONSTRAINT fk_horarios_tipo_servicio FOREIGN KEY (tipo_servicio_id) REFERENCES train_gimnasio.tipos_servicios(id);


--
-- Name: reservas_gym fk_reservas_servicio; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT fk_reservas_servicio FOREIGN KEY (tipo_servicio_id) REFERENCES train_gimnasio.tipos_servicios(id);


--
-- Name: reservas_gym fk_reservas_tipo_servicio; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT fk_reservas_tipo_servicio FOREIGN KEY (tipo_servicio_id) REFERENCES train_gimnasio.tipos_servicios(id);


--
-- Name: tipos_servicios fk_tipos_servicios_categoria; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.tipos_servicios
    ADD CONSTRAINT fk_tipos_servicios_categoria FOREIGN KEY (categoria_id) REFERENCES train_gimnasio.categoria_servicios(id);


--
-- Name: horarios_gym_dias horarios_gym_dias_horario_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.horarios_gym_dias
    ADD CONSTRAINT horarios_gym_dias_horario_fkey FOREIGN KEY (horario_id) REFERENCES train_gimnasio.horarios_gym(id) ON DELETE CASCADE;


--
-- Name: horarios_gym horarios_gym_sede_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.horarios_gym
    ADD CONSTRAINT horarios_gym_sede_fkey FOREIGN KEY (sede_id) REFERENCES train_gimnasio.sedes(id);


--
-- Name: personas personas_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.personas
    ADD CONSTRAINT personas_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: reservas_gym reservas_horario_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT reservas_horario_fkey FOREIGN KEY (horario_id) REFERENCES train_gimnasio.horarios_gym(id);


--
-- Name: reservas_gym reservas_sede_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.reservas_gym
    ADD CONSTRAINT reservas_sede_fkey FOREIGN KEY (sede_id) REFERENCES train_gimnasio.sedes(id);


--
-- Name: sedes sedes_gimnasio_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.sedes
    ADD CONSTRAINT sedes_gimnasio_id_fkey FOREIGN KEY (gimnasio_id) REFERENCES train_gimnasio.gimnasios(id);


--
-- Name: usuario_sedes usuario_sedes_sede_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes
    ADD CONSTRAINT usuario_sedes_sede_id_fkey FOREIGN KEY (sede_id) REFERENCES train_gimnasio.sedes(id) ON DELETE CASCADE;


--
-- Name: usuario_sedes usuario_sedes_usuario_id_fkey; Type: FK CONSTRAINT; Schema: train_gimnasio; Owner: postgres
--

ALTER TABLE ONLY train_gimnasio.usuario_sedes
    ADD CONSTRAINT usuario_sedes_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES train_gimnasio.auth_usuarios(id) ON DELETE CASCADE;


--
-- Name: devolucion_detalles devolucion_detalles_devolucion_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_devolucion_id_fkey FOREIGN KEY (devolucion_id) REFERENCES ventas.devoluciones(id) ON DELETE CASCADE;


--
-- Name: devolucion_detalles devolucion_detalles_membresia_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_membresia_id_fkey FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);


--
-- Name: devolucion_detalles devolucion_detalles_movimiento_inventario_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_movimiento_inventario_id_fkey FOREIGN KEY (movimiento_inventario_id) REFERENCES inventario.movimientos_inventario(id);


--
-- Name: devolucion_detalles devolucion_detalles_producto_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_producto_id_fkey FOREIGN KEY (producto_id) REFERENCES inventario.productos(id);


--
-- Name: devolucion_detalles devolucion_detalles_venta_detalle_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devolucion_detalles
    ADD CONSTRAINT devolucion_detalles_venta_detalle_id_fkey FOREIGN KEY (venta_detalle_id) REFERENCES ventas.venta_detalles(id);


--
-- Name: devoluciones devoluciones_created_by_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_created_by_fkey FOREIGN KEY (created_by) REFERENCES seguridad.usuarios(id);


--
-- Name: devoluciones devoluciones_updated_by_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES seguridad.usuarios(id);


--
-- Name: devoluciones devoluciones_venta_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_venta_id_fkey FOREIGN KEY (venta_id) REFERENCES ventas.ventas(id);


--
-- Name: ventas fk_ventas_anulada_by; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT fk_ventas_anulada_by FOREIGN KEY (anulada_by) REFERENCES seguridad.usuarios(id);


--
-- Name: ventas fk_ventas_membresia_id; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT fk_ventas_membresia_id FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);


--
-- Name: ventas fk_ventas_persona_id; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT fk_ventas_persona_id FOREIGN KEY (persona_id) REFERENCES core.personas(id);


--
-- Name: ventas fk_ventas_vendedor_usuario_id; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ventas
    ADD CONSTRAINT fk_ventas_vendedor_usuario_id FOREIGN KEY (vendedor_usuario_id) REFERENCES seguridad.usuarios(id);


--
-- Name: venta_detalles fk_ventas_venta_detalles_membresia_id; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.venta_detalles
    ADD CONSTRAINT fk_ventas_venta_detalles_membresia_id FOREIGN KEY (membresia_id) REFERENCES socios.membresias(id);


--
-- Name: venta_detalles venta_detalles_venta_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.venta_detalles
    ADD CONSTRAINT venta_detalles_venta_id_fkey FOREIGN KEY (venta_id) REFERENCES ventas.ventas(id) ON DELETE CASCADE;


--
-- Name: venta_pagos venta_pagos_venta_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.venta_pagos
    ADD CONSTRAINT venta_pagos_venta_id_fkey FOREIGN KEY (venta_id) REFERENCES ventas.ventas(id) ON DELETE CASCADE;


--
-- Name: ev_auto_auditar_gym; Type: EVENT TRIGGER; Schema: -; Owner: postgres
--

CREATE EVENT TRIGGER ev_auto_auditar_gym ON ddl_command_end
         WHEN TAG IN ('CREATE TABLE')
   EXECUTE FUNCTION train_gimnasio.fn_evento_auto_auditar();


ALTER EVENT TRIGGER ev_auto_auditar_gym OWNER TO postgres;

--
-- PostgreSQL database dump complete
--

\unrestrict 91WEkSvJeggdigwHGMBr0dJQedJUDiIQtbooduNJH2ZCNmR1oxPY1SPBlmR89Y4

