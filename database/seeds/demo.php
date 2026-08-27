<?php
/**
 * Datos de DEMO para pruebas (no es un seed de producción). Refleja el inventario real:
 * cabezales, camiones y equipo de arrastre (furgones, contenedores, chasis) como unidades
 * independientes, más pilotos y una semana de movimientos con todos los estados posibles.
 *
 * Las fechas son relativas a "ahora", así que la carga siempre cae en la semana en curso.
 * Lo consume database/seed-demo.php.
 */

declare(strict_types=1);

return [
    // [nombre, tipo_licencia_id, no_licencia, vence (días desde hoy), estacion_codigo]
    'pilotos' => [
        ['Carlos Méndez',   1, 'P-001', 420,  'TRK'],
        ['José Rivera',     1, 'P-002', -15,  'TRK'],
        ['Luis Hernández',  1, 'P-003', 25,   'TRK'],
        ['Óscar Portillo',  1, 'P-004', 380,  'TRK'],
        ['Mario Cruz',      1, 'P-005', 300,  'TRK'],
        ['Byron Alfaro',    1, 'P-006', 500,  'TRK'],
        ['Nelson Guzmán',   1, 'P-007', 640,  'TRK'],
        ['Walter Reyes',    1, 'P-008', 210,  'TRK'],
        ['Edwin Sánchez',   1, 'P-009', 470,  'TRK'],
        ['Rafael Molina',   1, 'P-010', 520,  'TRK'],
        ['Iván Portillo',   2, 'P-011', 260,  'SAL'],
        ['Diego Ramírez',   1, 'P-012', 190,  'GUA'],
    ],

    // [placa, categoría, marca, modelo, año, tipo_equipo, capacidad, estación, piloto, estado]
    'unidades' => [
        ['C99679',  'Cabezal', 'Mercedez',     '1117',     1988, 'N/A', '1T',   'TRK', 0,    'OPERATIVO'],
        ['C96670',  'Cabezal', 'Freightliner', 'FL',       1996, 'N/A', '2T',   'TRK', 1,    'OPERATIVO'],
        ['C93264',  'Cabezal', 'Freightliner', 'Century',  2000, 'N/A', '3.5T', 'TRK', 2,    'OPERATIVO'],
        ['C97609',  'Cabezal', 'Freightliner', 'FL',       2001, 'N/A', '5T',   'TRK', 3,    'OPERATIVO'],
        ['C88198',  'Cabezal', 'Freightliner', 'Columbia', 2002, 'N/A', '8T',   'TRK', 4,    'EN_MANTENIMIENTO'],
        ['C102766', 'Cabezal', 'Freightliner', 'Columbia', 2002, 'N/A', '10T',  'TRK', 5,    'OPERATIVO'],
        ['C65472',  'Camión',  'Freightliner', 'FL70',     2003, 'N/A', '12T',  'TRK', 6,    'OPERATIVO'],
        ['C107579', 'Cabezal', 'Freightliner', 'Century',  2003, 'N/A', '18T',  'TRK', 7,    'OPERATIVO'],
        ['C114757', 'Cabezal', 'Freightliner', 'Columbia', 2006, 'N/A', '24T',  'TRK', 8,    'OPERATIVO'],
        ['C96096',  'Cabezal', 'Freightliner', 'Columbia', 2006, 'N/A', '28T',  'TRK', 9,    'OPERATIVO'],
        ['C117304', 'Cabezal', 'Freightliner', 'Columbia', 2008, 'N/A', '24T',  'TRK', null, 'OPERATIVO'],
        ['C127133', 'Cabezal', 'International','Prostar',  2009, 'N/A', '28T',  'TRK', null, 'OPERATIVO'],
        ['C140310', 'Cabezal', 'Freightliner', 'Columbia', 2009, 'N/A', '28T',  'TRK', null, 'INOPERATIVO'],
        ['P1315F',  'Camión',  'Kia',          'K2700',    2011, 'N/A', '1T',   'TRK', null, 'OPERATIVO'],
        ['C127939', 'Cabezal', 'Freightliner', 'Century',  2012, 'N/A', '28T',  'SAL', 10,   'OPERATIVO'],
        ['C127994', 'Cabezal', 'Freightliner', 'Cascadia', 2015, 'N/A', '28T',  'GUA', 11,   'OPERATIVO'],

        ['RE15873', 'Furgón', 'Great Dane', '48', 1986, 'Standard', '48ft', 'TRK', null, 'OPERATIVO'],
        ['RE15879', 'Furgón', 'Wabash',     '53', 2001, 'Standard', '53ft', 'TRK', null, 'OPERATIVO'],
        ['RE5039',  'Furgón', 'Fruehauf',   '45', 1979, 'Standard', '45ft', 'TRK', null, 'EN_MANTENIMIENTO'],
        ['RE14326', 'Furgón', 'Pines',      '53', 1998, 'Standard', '53ft', 'TRK', null, 'OPERATIVO'],
        ['RE14330', 'Furgón', 'Wabash',     '53', 1996, 'Standard', '53ft', 'TRK', null, 'OPERATIVO'],
        ['RE14402', 'Furgón', 'Dorsey',     '53', 1995, 'Standard', '53ft', 'TRK', null, 'OPERATIVO'],
        ['RE14965', 'Furgón', 'Utility',    '53', 1998, 'Reefer',   '53ft', 'TRK', null, 'OPERATIVO'],
        ['RE15248', 'Furgón', 'Stoughton',  '53', 1993, 'Standard', '53ft', 'SAL', null, 'OPERATIVO'],

        ['CONT-4401', 'Contenedor', 'Maersk',    'Dry',    2016, 'Standard',  '40ft', 'TRK', null, 'OPERATIVO'],
        ['CONT-4402', 'Contenedor', 'Hapag',     'Dry',    2018, 'Standard',  '40ft', 'TRK', null, 'OPERATIVO'],
        ['CONT-2001', 'Contenedor', 'CMA CGM',   'Dry',    2019, 'Standard',  '20ft', 'TRK', null, 'OPERATIVO'],
        ['CONT-4403', 'Contenedor', 'Maersk',    'Reefer', 2020, 'Reefer',    '40ft', 'TRK', null, 'OPERATIVO'],
        ['CONT-4404', 'Contenedor', 'Evergreen', 'High',   2021, 'High Cube', '40ft', 'SAL', null, 'OPERATIVO'],

        ['CH-4410', 'Chasis', 'Dorsey',    'Esqueleto', 2014, 'Platform', '40ft', 'TRK', null, 'OPERATIVO'],
        ['CH-4411', 'Chasis', 'Wabash',    'Esqueleto', 2017, 'Platform', '40ft', 'TRK', null, 'OPERATIVO'],
        ['CH-2010', 'Chasis', 'Stoughton', 'Esqueleto', 2015, 'Platform', '20ft', 'TRK', null, 'OPERATIVO'],
    ],

    // [placa, estado, origen_iso, destino_iso, salida_h, fin_h, piloto, retorno, reservado_para, notas]
    'movimientos' => [
        ['C96670',   'EN_TRANSITO', 'SV', 'GT', -20,  6,   1,    true,  'EFL Global Guatemala',  'Carga consolidada'],
        ['C93264',   'EN_TRANSITO', 'SV', 'HN', -30, -4,   2,    true,  'Cliente Farmacéutico',  'Retenido en frontera'],
        ['C97609',   'EN_TRANSITO', 'SV', 'SV', -6,   4,   3,    false, 'Reparto local',         null],
        ['C102766',  'PROGRAMADO',  'SV', 'CR',  18,  56,  5,    true,  'EFL Global Costa Rica', null],
        ['C107579',  'PROGRAMADO',  'SV', 'GT',  30,  48,  7,    true,  'Cliente Textil',        null],
        ['C65472',   'PROGRAMADO',  'SV', 'SV',  8,   14,  6,    false, 'Traslado a bodega',     null],
        ['C114757',  'RESERVADO',   'SV', 'NI',  52,  92,  8,    true,  'EFL Global Nicaragua',  null],
        ['C96096',   'RESERVADO',   'SV', 'GT',  74,  96,  9,    false, 'Cliente Retail',        null],
        ['C117304',  'RESERVADO',   'SV', 'HN',  100, 140, null, true,  'Por confirmar',         'Falta asignar piloto'],
        ['CONT-4401','EN_TRANSITO', 'SV', 'GT', -12,  10,  null, false, 'Cliente Textil',        'Contenedor en viaje'],
        ['CONT-2001','RESERVADO',   'SV', 'SV',  24,  190, null, false, 'Bodega AIP',            'Queda con el cliente'],
        ['RE14326',  'PROGRAMADO',  'SV', 'SV',  12,  30,  null, false, 'Reparto local',         null],
        ['C127939',  'EN_TRANSITO', 'SV', 'GT', -40, -10,  10,   true,  'EFL Global Guatemala',  'Con demora'],
        ['C127994',  'PROGRAMADO',  'GT', 'SV',  20,  44,  11,   true,  'EFL Trucking',          null],
    ],

    // [placa, tipo, origen, motivo, desde_h, hasta_h]
    'overrides' => [
        ['C88198',  'EN_TALLER', 'AUTO_ESTADO', 'Cambio de turbo y frenos',        -72,  null],
        ['RE5039',  'EN_TALLER', 'AUTO_ESTADO', 'Reparación de suspensión',        -120, null],
        ['C140310', 'BLOQUEADA', 'MANUAL',      'Retenida en frontera (revisión)', -48,  72],
        ['RE14402', 'BLOQUEADA', 'MANUAL',      'Rentado a cliente por 7 días',    -24,  144],
    ],
];
