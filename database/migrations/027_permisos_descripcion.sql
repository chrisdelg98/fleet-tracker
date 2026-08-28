-- 027_permisos_descripcion.sql — Los permisos especiales se listan por nombre en el formulario
-- de unidades, así que el nombre debe ser corto ("Carga peligrosa"). La descripción guarda el
-- detalle de qué habilita, igual que ya lo hacen tipos de equipo y capacidades.
ALTER TABLE permisos_especiales
    ADD COLUMN descripcion VARCHAR(255) NULL AFTER nombre;
