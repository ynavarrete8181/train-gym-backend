-- REVIVE SPORTS | Migracion controlada del catalogo de DESARROLLO a PRODUCCION
-- Requiere revive_catalogo.json exportado con revive_exportar_catalogo.sql
-- Ejecutar desde la raiz del backend, con PostgreSQL de destino seleccionado en psql.
-- Simulacion: psql ... -X -v ON_ERROR_STOP=1 -f database/scripts/revive_importar_catalogo_produccion.sql
-- Aplicar:    psql ... -X -v ON_ERROR_STOP=1 -v apply=1 -f database/scripts/revive_importar_catalogo_produccion.sql
-- NO usar 'php artisan migrate' para cargar el catalogo. NO aplica stock, lotes ni movimientos.
-- No incluye productos de ejemplo. Los IDs los genera la secuencia de produccion.
\if :{?apply}
\else
  \set apply 0
\endif
\set ON_ERROR_STOP on
BEGIN;
CREATE TEMP TABLE revive_import_raw (linea text NOT NULL) ON COMMIT DROP;
-- CSV con separadores de control: conserva las barras invertidas y comillas JSON.
-- El exportador genera UNA sola linea JSON.
\copy revive_import_raw(linea) FROM 'revive_catalogo.json' WITH (FORMAT csv, DELIMITER E'\x1f', QUOTE E'\x1e', ESCAPE E'\x1d')
DO $$
BEGIN
  IF (SELECT count(*) FROM revive_import_raw) <> 1 THEN
    RAISE EXCEPTION 'El archivo revive_catalogo.json debe contener exactamente un objeto JSON en una sola linea.';
  END IF;
END $$;
CREATE TEMP TABLE revive_payload ON COMMIT DROP AS
SELECT linea::jsonb AS doc FROM revive_import_raw;
DO $$
BEGIN
 IF (SELECT doc->>'formato' FROM revive_payload) <> 'revive_catalogo_comercial_v1'
 OR jsonb_typeof((SELECT doc->'productos' FROM revive_payload)) <> 'array'
 OR jsonb_typeof((SELECT doc->'categorias' FROM revive_payload)) <> 'array'
 OR jsonb_typeof((SELECT doc->'precios_por_sede' FROM revive_payload)) <> 'array' THEN
    RAISE EXCEPTION 'Formato inesperado del catalogo. No se modifico produccion.';
 END IF;
END $$;
CREATE TEMP TABLE revive_categorias ON COMMIT DROP AS
SELECT (v->>'id')::bigint AS id_origen, nullif(btrim(v->>'nombre'),'') AS nombre,
       v->>'descripcion' AS descripcion
FROM revive_payload, jsonb_array_elements(doc->'categorias') v;
CREATE TEMP TABLE revive_productos ON COMMIT DROP AS
SELECT (v->>'id')::bigint AS id_origen,
       nullif(btrim(v->>'codigo'),'') AS codigo,
       nullif(btrim(v->>'nombre'),'') AS nombre,
       nullif(v->>'descripcion','') AS descripcion,
       nullif(v->>'marca','') AS marca,
       nullif(v->>'unidad_medida','') AS unidad_medida,
       nullif(v->>'imagen_url','') AS imagen_url,
       (nullif(v->>'categoria_id',''))::bigint AS categoria_id_origen,
       (nullif(v->>'precio_venta',''))::numeric AS precio_venta,
       coalesce((nullif(v->>'activo',''))::boolean, true) AS activo
FROM revive_payload, jsonb_array_elements(doc->'productos') v;
CREATE TEMP TABLE revive_precios ON COMMIT DROP AS
SELECT (v->>'producto_id_origen')::bigint AS producto_id_origen,
       (v->>'sede_id_origen')::bigint AS sede_id_origen,
       nullif(btrim(v->>'sede_nombre'),'') AS sede_nombre,
       (nullif(v->>'precio',''))::numeric AS precio,
       coalesce((nullif(v->>'activo',''))::boolean, true) AS activo
FROM revive_payload, jsonb_array_elements(doc->'precios_por_sede') v;
-- Validacion PREVIA: aborta por completo ante referencias inexistentes o ambiguas.
DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM revive_productos WHERE codigo IS NULL OR nombre IS NULL
              OR length(codigo)>60 OR length(nombre)>160 OR precio_venta IS NULL OR precio_venta<0 OR precio_venta>9999999999.99
              OR length(coalesce(marca,''))>100 OR length(coalesce(unidad_medida,''))>30) THEN
   RAISE EXCEPTION 'Productos con codigo, nombre, precio o longitudes invalidas.';
 END IF;
 IF EXISTS (SELECT 1 FROM revive_productos GROUP BY lower(codigo) HAVING count(*)>1)
 OR EXISTS (SELECT 1 FROM revive_productos GROUP BY id_origen HAVING count(*)>1) THEN
   RAISE EXCEPTION 'Existen productos repetidos en el origen.';
 END IF;
 IF EXISTS (SELECT 1 FROM revive_categorias WHERE nombre IS NULL OR length(nombre)>120)
 OR EXISTS (SELECT 1 FROM revive_categorias GROUP BY lower(nombre) HAVING count(*)>1) THEN
   RAISE EXCEPTION 'Categorias invalidas o duplicadas en desarrollo.';
 END IF;
 IF EXISTS (SELECT 1 FROM inventario.categorias_producto GROUP BY lower(btrim(nombre)) HAVING count(*)>1)
 OR EXISTS (SELECT 1 FROM institucional.sedes GROUP BY lower(btrim(nombre)) HAVING count(*)>1)
 OR EXISTS (SELECT 1 FROM inventario.productos GROUP BY lower(btrim(codigo)) HAVING count(*)>1) THEN
   RAISE EXCEPTION 'Categorias, sedes o codigos ambiguos en PRODUCCION.';
 END IF;
 IF EXISTS (SELECT 1 FROM revive_productos p
  LEFT JOIN revive_categorias c ON c.id_origen=p.categoria_id_origen
  WHERE p.categoria_id_origen IS NOT NULL AND c.id_origen IS NULL) THEN
   RAISE EXCEPTION 'Hay productos con categoria local inexistente.';
 END IF;
 IF EXISTS (SELECT 1 FROM revive_productos o
 JOIN inventario.productos d ON lower(btrim(d.codigo))=lower(o.codigo)
 WHERE lower(btrim(d.nombre))<>lower(o.nombre)) THEN
   RAISE EXCEPTION 'Conflicto: un codigo ya existe con nombre diferente en PRODUCCION.';
 END IF;
 IF EXISTS (SELECT 1 FROM revive_precios pp
  LEFT JOIN revive_productos p ON p.id_origen=pp.producto_id_origen
  WHERE p.id_origen IS NULL OR pp.precio IS NULL OR pp.precio<0 OR pp.precio>9999999999.99) THEN
   RAISE EXCEPTION 'Precios invalidos o asociados a productos desconocidos.';
 END IF;
 IF EXISTS (SELECT 1 FROM revive_precios GROUP BY producto_id_origen,lower(sede_nombre) HAVING count(*)>1) THEN
   RAISE EXCEPTION 'Precios duplicados por producto y sede en desarrollo.';
 END IF;
 IF EXISTS (SELECT 1 FROM revive_precios ps
  LEFT JOIN institucional.sedes s ON lower(btrim(s.nombre))=lower(ps.sede_nombre)
  WHERE s.id_sede IS NULL OR NOT s.activo OR NOT s.maneja_inventario) THEN
   RAISE EXCEPTION 'Una sede de desarrollo no coincide con una sede de inventario habilitada en PRODUCCION.';
 END IF;
END $$;
SELECT 'PRODUCTOS_ORIGEN' AS indicador,count(*) AS cantidad FROM revive_productos
UNION ALL SELECT 'PRODUCTOS_NUEVOS',count(*) FROM revive_productos o
  WHERE NOT EXISTS (SELECT 1 FROM inventario.productos d WHERE lower(btrim(d.codigo))=lower(o.codigo))
UNION ALL SELECT 'CATEGORIAS_NUEVAS',count(*) FROM revive_categorias c
  WHERE NOT EXISTS (SELECT 1 FROM inventario.categorias_producto d WHERE lower(btrim(d.nombre))=lower(c.nombre))
UNION ALL SELECT 'PRECIOS_ORIGEN',count(*) FROM revive_precios
UNION ALL SELECT 'PRECIOS_YA_EXISTENTES',count(*) FROM revive_precios pp
  JOIN revive_productos o ON o.id_origen=pp.producto_id_origen
  JOIN inventario.productos p ON lower(btrim(p.codigo))=lower(o.codigo)
  JOIN institucional.sedes s ON lower(btrim(s.nombre))=lower(pp.sede_nombre)
  JOIN inventario.producto_precios_sede d ON d.producto_id=p.id AND d.sede_id=s.id_sede;
-- La simulacion no escribe. Unicamente -v apply=1 autoriza escrituras.
\if :apply
  LOCK TABLE inventario.categorias_producto, inventario.productos,
    inventario.producto_precios_sede IN SHARE ROW EXCLUSIVE MODE;
  INSERT INTO inventario.categorias_producto(nombre,descripcion,activo,created_at,updated_at)
  SELECT c.nombre,c.descripcion,true,now(),now() FROM revive_categorias c
  WHERE NOT EXISTS (SELECT 1 FROM inventario.categorias_producto x WHERE lower(btrim(x.nombre))=lower(c.nombre))
  ON CONFLICT(nombre) DO NOTHING;
  INSERT INTO inventario.productos(
    categoria_id,proveedor_id,codigo,nombre,descripcion,marca,unidad_medida,
    precio_costo,precio_venta,stock_actual,stock_minimo,controla_stock,maneja_lotes,
    imagen_url,activo,created_at,updated_at)
  SELECT d.id,NULL,p.codigo,p.nombre,p.descripcion,p.marca,
    coalesce(p.unidad_medida,'UNIDAD'),0,p.precio_venta,0,0,true,true,
    CASE WHEN p.imagen_url ~* '^https://' AND p.imagen_url !~* '(localhost|127\.0\.0\.1)' THEN p.imagen_url ELSE NULL END,
    p.activo,now(),now()
  FROM revive_productos p
  LEFT JOIN revive_categorias c ON c.id_origen=p.categoria_id_origen
  LEFT JOIN inventario.categorias_producto d ON lower(btrim(d.nombre))=lower(c.nombre)
  WHERE NOT EXISTS (SELECT 1 FROM inventario.productos x WHERE lower(btrim(x.codigo))=lower(p.codigo))
  ON CONFLICT(codigo) DO NOTHING;
  -- No sobrescribe tarifas que ya existan en produccion.
  INSERT INTO inventario.producto_precios_sede(producto_id,sede_id,precio,activo,created_at,updated_at)
  SELECT p.id,s.id_sede,ps.precio,ps.activo,now(),now()
  FROM revive_precios ps
  JOIN revive_productos o ON o.id_origen=ps.producto_id_origen
  JOIN inventario.productos p ON lower(btrim(p.codigo))=lower(o.codigo)
  JOIN institucional.sedes s ON lower(btrim(s.nombre))=lower(ps.sede_nombre)
  WHERE NOT EXISTS (SELECT 1 FROM inventario.producto_precios_sede d WHERE d.producto_id=p.id AND d.sede_id=s.id_sede);
  -- Consistencia: cada codigo del origen debe corresponder a un producto destino.
  DO $$
  BEGIN
    IF EXISTS (SELECT 1 FROM revive_productos o LEFT JOIN inventario.productos d
               ON lower(btrim(d.codigo))=lower(o.codigo) WHERE d.id IS NULL)
    OR EXISTS (SELECT 1 FROM revive_precios ps
               JOIN revive_productos o ON o.id_origen=ps.producto_id_origen
               JOIN inventario.productos p ON lower(btrim(p.codigo))=lower(o.codigo)
               JOIN institucional.sedes s ON lower(btrim(s.nombre))=lower(ps.sede_nombre)
               LEFT JOIN inventario.producto_precios_sede d ON d.producto_id=p.id AND d.sede_id=s.id_sede
               WHERE d.producto_id IS NULL) THEN
       RAISE EXCEPTION 'Verificacion final fallida. Se revierte toda la transaccion.';
    END IF;
  END $$;
  COMMIT;
  \echo 'IMPORTACION APLICADA. Verifica el listado de productos y precios por sede.'
\else
  ROLLBACK;
  \echo 'SIMULACION COMPLETADA. No se modifico ningun dato de produccion.'
\endif
