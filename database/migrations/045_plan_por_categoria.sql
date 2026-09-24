-- 045_plan_por_categoria.sql — Un plan responde «cada cuánto toca el servicio», y una unidad
-- solo puede tener una respuesta. Con eso, el modelo se dice en dos frases: cada categoría usa
-- un plan o no usa ninguno, y el diálogo de cada plan pregunta qué categorías lo usan.
--
-- Sobraban dos conceptos que solo servían para tapar el hueco de «ninguno»:
--   · `por_defecto` (el plan comodín) hacía falta porque no tener plan no era una respuesta.
--   · `plan_no_aplica` distinguía «no tiene» de «no lleva», dos formas de lo mismo.
-- Sin ellos, no marcar una categoría ya significa «sin plan», que es lo que el semáforo muestra.

-- Lo que estaba marcado como «no lleva plan» ya lo dice su falta de asignación.
UPDATE categorias_vehiculo SET plan_mantenimiento_id = NULL WHERE plan_no_aplica = 1;

ALTER TABLE categorias_vehiculo
    DROP COLUMN plan_no_aplica;

ALTER TABLE planes_mantenimiento
    DROP COLUMN por_defecto;
