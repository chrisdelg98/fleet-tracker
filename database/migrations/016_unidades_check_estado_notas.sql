-- 016_unidades_check_estado_notas.sql — Última línea de defensa (AGENTS.md §Integridad #3).
-- Invariante de datos: si el vehículo no está OPERATIVO, estado_notas es obligatorio.
-- El backend da el 422 amigable; este CHECK garantiza que la BD nunca guarde el estado
-- sin motivo (plan §5.5, regla 17). La herencia de en_disponibilidad NO va aquí: es
-- comportamiento (default editable), vive 100% en el backend.
ALTER TABLE unidades
    ADD CONSTRAINT chk_unidades_estado_notas
    CHECK (estado_vehiculo = 'OPERATIVO' OR (estado_notas IS NOT NULL AND estado_notas <> ''));
