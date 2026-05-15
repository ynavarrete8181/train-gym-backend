-- Elimina únicamente la auditoría basada en triggers.
-- Mantiene triggers técnicos como fn_set_updated_at().
-- A partir de este punto, la fuente de auditoría debe ser el backend.

DO $$
DECLARE
    trigger_row record;
BEGIN
    FOR trigger_row IN
        SELECT
            event_object_schema,
            event_object_table,
            trigger_name
        FROM information_schema.triggers
        WHERE event_object_schema = 'train_gimnasio'
          AND action_statement = 'EXECUTE FUNCTION train_gimnasio.fn_auditar_cambios()'
    LOOP
        EXECUTE format(
            'DROP TRIGGER IF EXISTS %I ON %I.%I',
            trigger_row.trigger_name,
            trigger_row.event_object_schema,
            trigger_row.event_object_table
        );
    END LOOP;
END $$;

DROP FUNCTION IF EXISTS train_gimnasio.fn_auditar_cambios();
DROP FUNCTION IF EXISTS train_gimnasio.fn_diff_jsonb(jsonb, jsonb);
