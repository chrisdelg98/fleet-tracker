-- 008_fk_created_by_bootstrap.sql — Cierra el ciclo estaciones<->usuarios.
-- Agrega el FK created_by -> usuarios a todas las tablas creadas antes de usuarios.
-- created_by permanece NULL para filas de bootstrap (seeds y primer admin). ON DELETE
-- RESTRICT: nunca se borra físicamente un usuario que dejó rastro de autoría.
ALTER TABLE paises
    ADD CONSTRAINT fk_paises_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT;

ALTER TABLE tipos_equipo
    ADD CONSTRAINT fk_tipos_equipo_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT;

ALTER TABLE permisos_especiales
    ADD CONSTRAINT fk_permisos_especiales_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT;

ALTER TABLE tipos_licencia
    ADD CONSTRAINT fk_tipos_licencia_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT;

ALTER TABLE categorias_vehiculo
    ADD CONSTRAINT fk_categorias_vehiculo_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT;

ALTER TABLE estaciones
    ADD CONSTRAINT fk_estaciones_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT;

ALTER TABLE usuarios
    ADD CONSTRAINT fk_usuarios_created_by FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE RESTRICT;
