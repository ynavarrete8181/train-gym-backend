-- Mejora de rendimiento para auditoría gestionada desde backend.
-- Se enfoca en búsquedas típicas por tabla, registro, actor, request y fecha.

CREATE INDEX IF NOT EXISTS idx_aud_cambios_created_at
    ON train_gimnasio.aud_cambios (created_at DESC);

CREATE INDEX IF NOT EXISTS idx_aud_cambios_tabla_registro_fecha
    ON train_gimnasio.aud_cambios (tabla, registro_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_aud_cambios_actor_fecha
    ON train_gimnasio.aud_cambios (actor_usuario_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_aud_cambios_request_id
    ON train_gimnasio.aud_cambios (request_id);

CREATE INDEX IF NOT EXISTS idx_aud_cambios_operacion_fecha
    ON train_gimnasio.aud_cambios (operacion, created_at DESC);

