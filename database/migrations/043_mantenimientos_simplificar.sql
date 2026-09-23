-- 043_mantenimientos_simplificar.sql — El ciclo se configuraba en dos sitios: «reinicia el ciclo»
-- en el catálogo de tipos y los intervalos en el plan. Nadie sabía cuál mandaba. Desde aquí la
-- regla es una sola frase: un mantenimiento PREVENTIVO reinicia el ciclo; un CORRECTIVO no.
--
-- Además, los tipos dejan de ser trabajos. «Neumáticos» es lo que se hizo, no una clasificación:
-- eso vive en la descripción, que ya existe y es texto libre.

-- ── Tipos: es_preventivo sustituye a reinicia_ciclo ──
ALTER TABLE tipos_mantenimiento
    ADD COLUMN es_preventivo TINYINT(1) NOT NULL DEFAULT 0 AFTER nombre;

-- Lo que hoy reinicia el ciclo es, por definición, el servicio programado.
UPDATE tipos_mantenimiento SET es_preventivo = reinicia_ciclo;

-- Los trabajos sueltos se absorben en Correctivo para no dejar huérfano su historial.
UPDATE mantenimientos m
   JOIN tipos_mantenimiento t ON t.id = m.tipo_mantenimiento_id
    SET m.tipo_mantenimiento_id = (SELECT id FROM (SELECT id FROM tipos_mantenimiento
                                                    WHERE es_preventivo = 0 ORDER BY orden LIMIT 1) x)
  WHERE t.nombre = 'Neumáticos';

DELETE FROM tipos_mantenimiento WHERE nombre = 'Neumáticos';

UPDATE tipos_mantenimiento SET nombre = 'Preventivo', orden = 1 WHERE es_preventivo = 1;
UPDATE tipos_mantenimiento SET nombre = 'Correctivo', orden = 2 WHERE es_preventivo = 0;

ALTER TABLE tipos_mantenimiento
    DROP COLUMN reinicia_ciclo;

-- ── El plan se aplica a una categoría entera ──
-- Asignar unidad por unidad era inviable: «todos los cabezales» son catorce. Y una plataforma no
-- necesita aviso de cambio de aceite, así que dejarla sin plan es una respuesta válida, no un
-- dato faltante.
-- `plan_no_aplica` no es lo mismo que dejar el plan vacío: vacío significa «usa el de por
-- defecto», y esto significa «esta categoría no lleva servicio programado». Sin distinguirlo,
-- las plataformas seguirían saliendo en el semáforo.
ALTER TABLE categorias_vehiculo
    ADD COLUMN plan_no_aplica TINYINT(1) NOT NULL DEFAULT 0 AFTER admite_arrastre,
    ADD COLUMN plan_mantenimiento_id BIGINT UNSIGNED NULL AFTER plan_no_aplica,
    ADD CONSTRAINT fk_categorias_plan FOREIGN KEY (plan_mantenimiento_id)
        REFERENCES planes_mantenimiento (id) ON DELETE SET NULL;
