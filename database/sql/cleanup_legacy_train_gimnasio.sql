BEGIN;

DO $$
DECLARE
    v_dependencias_restantes integer;
BEGIN
    SELECT COUNT(*)
    INTO v_dependencias_restantes
    FROM information_schema.views
    WHERE table_schema = 'train_gimnasio';

    IF v_dependencias_restantes > 0 THEN
        RAISE NOTICE 'Existen vistas en train_gimnasio. Revise antes de eliminar el esquema.';
    END IF;
END $$;

DROP SCHEMA IF EXISTS train_gimnasio CASCADE;

COMMIT;
