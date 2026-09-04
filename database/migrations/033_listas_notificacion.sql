-- 033_listas_notificacion.sql — Al reservar hay que avisar a alguien: el cliente, la estación
-- de destino, el ejecutivo de cuenta. Esos destinatarios se repiten viaje tras viaje, así que
-- se guardan como listas con nombre en vez de teclear los mismos correos cada vez.
--
-- Cada estación gestiona las suyas. Los contactos de El Salvador no le sirven a Guatemala, y
-- una lista global obligaría a que un administrador central mantuviera los contactos de todos.
-- estacion_id NULL = lista corporativa, visible desde cualquier estación.
--
-- El nombre es único DENTRO de la estación, no en toda la red: "Cliente Textil" puede existir
-- a la vez en SV y en GT y ser gente distinta. Un único global lo habría impedido.
--
-- Los correos van en un solo campo separados por coma, no en una tabla aparte: una lista es
-- una unidad de uso —se elige entera— y partirla añadiría una pantalla sin ganar nada.
CREATE TABLE listas_notificacion (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    estacion_id BIGINT UNSIGNED NULL,
    nombre      VARCHAR(100)    NOT NULL,
    correos     VARCHAR(500)    NOT NULL,
    activo      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by  BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_listas_estacion_nombre (estacion_id, nombre),
    KEY idx_listas_estacion (estacion_id),
    CONSTRAINT fk_listas_estacion FOREIGN KEY (estacion_id) REFERENCES estaciones (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A quién se avisó de ESTE movimiento. Se guardan los correos resueltos, no el id de la
-- lista: si mañana alguien edita la lista, el histórico debe seguir diciendo a quién se
-- escribió en su momento.
ALTER TABLE movimientos
    ADD COLUMN notificar_a VARCHAR(500) NULL AFTER reservado_para;
