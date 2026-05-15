-- Extiende la tabla de auditoría para reportes por módulo, acción y persona.
-- No incorpora lógica de auditoría en la base de datos, solo estructura de almacenamiento.

ALTER TABLE train_gimnasio.aud_cambios
    ADD COLUMN IF NOT EXISTS actor_persona_id bigint,
    ADD COLUMN IF NOT EXISTS modulo varchar(80),
    ADD COLUMN IF NOT EXISTS accion varchar(120);

CREATE INDEX IF NOT EXISTS idx_aud_cambios_actor_persona_fecha
    ON train_gimnasio.aud_cambios (actor_persona_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_aud_cambios_modulo_fecha
    ON train_gimnasio.aud_cambios (modulo, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_aud_cambios_accion_fecha
    ON train_gimnasio.aud_cambios (accion, created_at DESC);
