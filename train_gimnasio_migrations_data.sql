--
-- PostgreSQL database dump
--

\restrict ahcABWfuMWhBqMtrHQ62dfZktdAhMCEB96ETGSI2BW2kyb7cjOdrbGb85p5EacB

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
-- Data for Name: migrations; Type: TABLE DATA; Schema: train_gimnasio; Owner: postgres
--

INSERT INTO train_gimnasio.migrations VALUES (1, '2026_05_20_200000_allow_socio_in_producto_precios_type_check', 1);
INSERT INTO train_gimnasio.migrations VALUES (2, '0001_01_01_000000_create_users_table', 2);
INSERT INTO train_gimnasio.migrations VALUES (3, '0001_01_01_000001_create_cache_table', 2);
INSERT INTO train_gimnasio.migrations VALUES (4, '0001_01_01_000002_create_jobs_table', 2);
INSERT INTO train_gimnasio.migrations VALUES (5, '2026_02_04_154345_create_personal_access_tokens_table', 2);
INSERT INTO train_gimnasio.migrations VALUES (6, '2026_05_31_054343_create_inventarios_proveedores_table', 2);
INSERT INTO train_gimnasio.migrations VALUES (7, '2026_05_31_220000_create_core_domain_architecture', 3);
INSERT INTO train_gimnasio.migrations VALUES (8, '2026_06_01_100000_create_entrenamiento_ejercicios_table', 4);
INSERT INTO train_gimnasio.migrations VALUES (9, '2026_06_03_180000_create_entrenamiento_evaluaciones_rm_tables', 5);
INSERT INTO train_gimnasio.migrations VALUES (10, '2026_06_03_200000_create_entrenamiento_planes_rutinas_tables', 5);
INSERT INTO train_gimnasio.migrations VALUES (11, '2026_06_03_210000_extend_rutinas_and_create_templates_tables', 6);
INSERT INTO train_gimnasio.migrations VALUES (12, '2026_06_03_220000_add_bloque_orden_to_rutinas', 7);
INSERT INTO train_gimnasio.migrations VALUES (13, '2026_06_03_230000_create_entrenamiento_ejecuciones_table', 8);
INSERT INTO train_gimnasio.migrations VALUES (14, '2026_06_12_162552_add_nivel_resultado_to_evaluaciones_table', 9);
INSERT INTO train_gimnasio.migrations VALUES (15, '2026_06_12_163733_create_catalogos_table', 10);
INSERT INTO train_gimnasio.migrations VALUES (16, '2026_06_12_165127_add_fecha_proxima_evaluacion_to_evaluaciones_table', 11);
INSERT INTO train_gimnasio.migrations VALUES (17, '2026_06_12_202654_add_tipo_entrenamiento_to_ejercicios_table', 12);
INSERT INTO train_gimnasio.migrations VALUES (18, '2026_06_15_133700_add_transferencia_and_series_detalles_to_rutinas_tables', 13);
INSERT INTO train_gimnasio.migrations VALUES (19, '2026_06_16_120000_create_entrenamiento_planificacion_detallada_tables', 14);
INSERT INTO train_gimnasio.migrations VALUES (20, '2026_06_16_150000_normalize_entrenamiento_ejercicios_catalog', 15);
INSERT INTO train_gimnasio.migrations VALUES (21, '2026_06_16_151000_finalize_entrenamiento_ejercicios_cleanup', 16);
INSERT INTO train_gimnasio.migrations VALUES (22, '2026_06_16_160000_make_plan_persona_optional', 17);
INSERT INTO train_gimnasio.migrations VALUES (23, '2026_06_16_170000_add_tipo_to_entrenamiento_planes', 18);
INSERT INTO train_gimnasio.migrations VALUES (24, '2026_06_16_180000_add_estructura_to_entrenamiento_planes', 19);
INSERT INTO train_gimnasio.migrations VALUES (25, '2026_06_16_140000_create_entrenamiento_plantillas_semanales_tables', 20);
INSERT INTO train_gimnasio.migrations VALUES (26, '2026_06_19_130000_add_alcance_to_entrenamiento_planes', 20);
INSERT INTO train_gimnasio.migrations VALUES (27, '2026_06_20_100000_create_plan_entrenamiento_asignaciones_table', 21);
INSERT INTO train_gimnasio.migrations VALUES (28, '2026_06_20_110000_create_plan_ejecuciones_table', 22);
INSERT INTO train_gimnasio.migrations VALUES (29, '2026_06_22_150000_add_cedula_to_seguridad_usuarios', 23);
INSERT INTO train_gimnasio.migrations VALUES (30, '2026_06_24_051817_alter_repeticiones_reales_in_plan_ejecuciones', 24);
INSERT INTO train_gimnasio.migrations VALUES (31, '2026_06_27_120000_add_pos_debt_and_membership_fields_to_ventas', 25);
INSERT INTO train_gimnasio.migrations VALUES (32, '2026_06_27_140000_create_ventas_punto_venta_borradores_table', 25);
INSERT INTO train_gimnasio.migrations VALUES (33, '2026_06_28_220000_add_tipo_detalle_to_ventas_venta_detalles', 26);
INSERT INTO train_gimnasio.migrations VALUES (34, '2026_06_30_120000_create_seguridad_usuario_sedes_table', 27);
INSERT INTO train_gimnasio.migrations VALUES (35, '2026_07_01_120000_create_ventas_devoluciones_tables', 28);
INSERT INTO train_gimnasio.migrations VALUES (36, '2026_07_03_120000_create_membresia_precios_sede_table', 28);
INSERT INTO train_gimnasio.migrations VALUES (37, '2026_07_03_121000_alter_membresia_precios_sede_for_history', 28);
INSERT INTO train_gimnasio.migrations VALUES (38, '2026_07_03_122000_add_sede_precio_to_socio_membresias', 29);
INSERT INTO train_gimnasio.migrations VALUES (39, '2026_07_03_123000_add_cedula_paciente_to_egresos', 30);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: train_gimnasio; Owner: postgres
--

SELECT pg_catalog.setval('train_gimnasio.migrations_id_seq', 39, true);


--
-- PostgreSQL database dump complete
--

\unrestrict ahcABWfuMWhBqMtrHQ62dfZktdAhMCEB96ETGSI2BW2kyb7cjOdrbGb85p5EacB

