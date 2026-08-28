-- 029_permisos_internacional.sql — Antes de pedir una unidad a otra estación hay que saber si
-- puede cruzar la frontera: pedir un cabezal de SV para una ruta a GT no sirve de nada si su
-- registro es solo nacional. La capacidad no se guarda en la unidad (se desincronizaría con sus
-- papeles): se marca el permiso que la habilita y la unidad la hereda de los permisos que tiene.
ALTER TABLE permisos_especiales
    ADD COLUMN habilita_internacional TINYINT(1) NOT NULL DEFAULT 0 AFTER descripcion;

UPDATE permisos_especiales
   SET habilita_internacional = 1
 WHERE nombre LIKE '%Internacional%';
