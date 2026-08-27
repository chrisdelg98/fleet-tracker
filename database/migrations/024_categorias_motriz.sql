-- 024_categorias_motriz.sql — La flota real se compone de activos independientes: un cabezal
-- mueve un furgón o un contenedor (a veces sobre un chasis) y cada pieza se ocupa por su
-- cuenta. Se distingue qué categorías se mueven solas (motrices) de las que son arrastradas,
-- y se registran las categorías de arrastre que faltaban.
ALTER TABLE categorias_vehiculo
    ADD COLUMN es_motriz TINYINT(1) NOT NULL DEFAULT 1 AFTER requiere_furgon;

-- Las existentes son todas motrices; el cabezal deja de exigir placa de furgón porque el
-- furgón pasa a registrarse como unidad propia.
UPDATE categorias_vehiculo SET requiere_furgon = 0 WHERE nombre = 'Cabezal';

INSERT INTO categorias_vehiculo (nombre, es_flota_operativa, requiere_furgon, es_motriz, orden, activo)
VALUES
    ('Furgón',     1, 0, 0, 6, 1),
    ('Contenedor', 1, 0, 0, 7, 1),
    ('Chasis',     1, 0, 0, 8, 1);
