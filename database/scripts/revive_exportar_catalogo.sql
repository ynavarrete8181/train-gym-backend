-- REVIVE SPORTS | Exportacion de catalogo comercial desde PostgreSQL de desarrollo
-- Uso: psql -h 127.0.0.1 -p 5433 -U postgres -d db_gimnasio -X -A -t -v ON_ERROR_STOP=1 -f database/scripts/revive_exportar_catalogo.sql > revive_catalogo.json
-- SOLO LECTURA. No genera IDs, no inserta/actualiza registros y no mueve stock, lotes ni Kardex.
-- El archivo JSON es una fuente para validacion y conciliacion, NO un script de importacion automatica.
-- Los identificadores locales son referencias de ORIGEN y nunca deben copiarse como claves a produccion.
BEGIN TRANSACTION READ ONLY;
SELECT jsonb_build_object(
  'formato', 'revive_catalogo_comercial_v1',
  'base_origen', current_database(),
  'fecha_exportacion', CURRENT_TIMESTAMP,
  'categorias', COALESCE((
    SELECT jsonb_agg(to_jsonb(c) ORDER BY c.id)
    FROM inventario.categorias_producto AS c
  ), '[]'::jsonb),
  'proveedores_referencia', COALESCE((
    SELECT jsonb_agg(jsonb_build_object('id_origen', v.id, 'nombre', v.nombre, 'ruc', v.ruc) ORDER BY v.id)
    FROM inventario.proveedores AS v
  ), '[]'::jsonb),
  'sedes_referencia', COALESCE((
    SELECT jsonb_agg(jsonb_build_object('id_origen', s.id_sede, 'nombre', s.nombre, 'activo', s.activo, 'maneja_inventario', s.maneja_inventario) ORDER BY s.id_sede)
    FROM institucional.sedes AS s
  ), '[]'::jsonb),
  'productos', COALESCE((
    SELECT jsonb_agg(
      to_jsonb(p) - 'stock_actual' - 'stock_minimo' - 'stock_inicial' - 'cantidad_actual'
      ORDER BY p.codigo, p.id
    )
    FROM inventario.productos AS p
  ), '[]'::jsonb),
  'precios_por_sede', COALESCE((
    SELECT jsonb_agg(
      jsonb_build_object(
        'producto_id_origen', ps.producto_id,
        'producto_codigo', p.codigo,
        'sede_id_origen', ps.sede_id,
        'sede_nombre', s.nombre,
        'precio', ps.precio,
        'activo', ps.activo
      ) ORDER BY p.codigo, s.nombre, ps.sede_id
    )
    FROM inventario.producto_precios_sede AS ps
    JOIN inventario.productos AS p ON p.id = ps.producto_id
    JOIN institucional.sedes AS s ON s.id_sede = ps.sede_id
  ), '[]'::jsonb)
)::text;
COMMIT;
