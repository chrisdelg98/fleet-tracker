-- 031_pilotos_codigos_contacto.sql — Al piloto le faltaban datos para operar:
--
-- 1. Los dos códigos de transporte. En El Salvador se llaman "SV" (transporte nacional) y
--    "SVC" (fletes internacionales), pero el CONCEPTO es el mismo en toda la región: un
--    código habilita mover carga dentro del país y otro habilita cruzar la frontera. Por eso
--    las columnas se llaman por lo que significan, no por su nombre salvadoreño, y cada país
--    guarda cómo los llama en su jurisdicción. Un país sin esa figura deja las etiquetas
--    vacías y la interfaz cae en el nombre genérico.
--
-- 2. Documento de identificación y teléfonos. Los teléfonos van en un solo campo de texto
--    libre porque en la práctica un piloto tiene varios (personal, empresa, familiar) y
--    partirlos en columnas fijas obliga a decidir de antemano cuántos caben.
ALTER TABLE pilotos
    ADD COLUMN documento_identidad  VARCHAR(40)  NULL AFTER nombre,
    ADD COLUMN telefonos            VARCHAR(255) NULL AFTER documento_identidad,
    ADD COLUMN codigo_nacional      VARCHAR(40)  NULL AFTER licencia_vence,
    ADD COLUMN codigo_internacional VARCHAR(40)  NULL AFTER codigo_nacional;

-- Cómo llama cada país a esos dos códigos. NULL = usar el nombre genérico.
ALTER TABLE paises
    ADD COLUMN etiqueta_codigo_nacional      VARCHAR(60) NULL AFTER nombre,
    ADD COLUMN etiqueta_codigo_internacional VARCHAR(60) NULL AFTER etiqueta_codigo_nacional;

UPDATE paises
   SET etiqueta_codigo_nacional      = 'Código SV',
       etiqueta_codigo_internacional = 'Código SVC'
 WHERE codigo_iso = 'SV';
