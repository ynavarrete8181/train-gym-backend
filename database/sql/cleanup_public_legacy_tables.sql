BEGIN;

DO $$
DECLARE
    v_productos_exists boolean;
    v_categorias_exists boolean;
    v_productos_count bigint := 0;
    v_categorias_count bigint := 0;
BEGIN
    SELECT to_regclass('public.productos') IS NOT NULL INTO v_productos_exists;
    SELECT to_regclass('public.categorias_producto') IS NOT NULL INTO v_categorias_exists;

    IF v_productos_exists THEN
        EXECUTE 'SELECT count(*) FROM public.productos' INTO v_productos_count;
    END IF;

    IF v_categorias_exists THEN
        EXECUTE 'SELECT count(*) FROM public.categorias_producto' INTO v_categorias_count;
    END IF;

    IF v_productos_count <> 0 OR v_categorias_count <> 0 THEN
        RAISE EXCEPTION
            'Cancelado: public.productos (%) o public.categorias_producto (%) tienen datos.',
            v_productos_count,
            v_categorias_count;
    END IF;
END $$;

DROP TABLE IF EXISTS public.productos;
DROP TABLE IF EXISTS public.categorias_producto;

COMMIT;
