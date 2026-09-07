-- 034_categorias_admite_arrastre.sql — El formulario de reserva no podía saber si una unidad
-- lleva equipo enganchado. es_motriz solo dice si se mueve sola, y eso no alcanza: cabezal y
-- camión son los dos motrices, pero solo el cabezal jala algo. Sin este dato la reserva pedía
-- cabezal y chasis para un camión, que no usa ninguno de los dos.
--
-- Va en el catálogo y no en el código para que dar de alta una categoría nueva sea un
-- registro y no un despliegue.
ALTER TABLE categorias_vehiculo
    ADD COLUMN admite_arrastre TINYINT(1) NOT NULL DEFAULT 0 AFTER es_motriz;

-- El cabezal jala furgón, contenedor o chasis. El contenedor también admite equipo: viaja
-- sobre un chasis. El resto (camión, pick-up, automóvil, motocicleta, furgón, chasis) no.
UPDATE categorias_vehiculo SET admite_arrastre = 1 WHERE nombre IN ('Cabezal', 'Contenedor');
