/**
 * Qué mide y qué muestra cada hoja de gráficos.
 *
 * El motor (graficos.js) no sabe nada del negocio: recibe de aquí la medida, los paneles y las
 * columnas de la tabla. Añadir una hoja nueva es añadir una entrada, no tocar el dibujo.
 */

// 'en-US' y no 'es': el resto del sistema usa number_format() de PHP, que separa los miles con
// coma. Mezclar 1.234 y 1,234 en la misma pantalla se lee como dos cifras distintas.
/** Iconos de las cifras de cabecera (paths de 20×20), para que cada KPI se reconozca de un vistazo. */
const ICO = {
    camion: 'M2 6a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v2h2.4a1 1 0 0 1 .8.4l1.6 2.1a1 1 0 0 1 .2.6V14a.75.75 0 0 1-.75.75h-.9a2.35 2.35 0 0 1-4.6 0H9.1a2.35 2.35 0 0 1-4.6 0h-1A1 1 0 0 1 2 13.5V6Zm10 3.5v2.5h.36a2.35 2.35 0 0 1 3.28 0H16v-1l-1.3-1.5H12Z',
    reloj: 'M10 2.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Zm.75 4a.75.75 0 0 0-1.5 0v3.9c0 .27.14.52.37.65l2.77 1.6a.75.75 0 1 0 .76-1.3l-2.4-1.38V6.5Z',
    alerta: 'M10 2.2a1.3 1.3 0 0 1 1.13.66l6.3 11a1.3 1.3 0 0 1-1.13 1.95H3.7a1.3 1.3 0 0 1-1.13-1.95l6.3-11A1.3 1.3 0 0 1 10 2.2Zm0 4a.9.9 0 0 0-.9.9v3a.9.9 0 0 0 1.8 0v-3a.9.9 0 0 0-.9-.9Zm0 6.4a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z',
    retorno: 'M4 6.5A3.5 3.5 0 0 1 7.5 3H13a1 1 0 0 1 0 2H7.5A1.5 1.5 0 0 0 6 6.5V8l-2.5-2L6 4v1.5ZM16 13.5A3.5 3.5 0 0 1 12.5 17H7a1 1 0 0 1 0-2h5.5a1.5 1.5 0 0 0 1.5-1.5V12l2.5 2L14 16v-2.5Z',
    dinero: 'M10 2.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Zm.9 11.4v.6a.9.9 0 1 1-1.8 0v-.55a3 3 0 0 1-1.7-.9.85.85 0 0 1 1.2-1.2c.3.3.7.45 1.15.45.6 0 1-.28 1-.72 0-.4-.25-.6-1.2-.86-1.3-.35-2.3-.85-2.3-2.15 0-.95.6-1.7 1.55-1.98V6a.9.9 0 1 1 1.8 0v.5c.5.14.94.42 1.28.8a.85.85 0 1 1-1.26 1.14c-.24-.26-.55-.4-.92-.4-.55 0-.9.26-.9.64 0 .38.28.55 1.2.8 1.35.37 2.3.9 2.3 2.2 0 1-.62 1.77-1.6 2.02Z',
    llave: 'M12.5 2.5a5 5 0 0 0-4.8 6.4l-4.9 4.9a1 1 0 0 0-.3.7v2a1 1 0 0 0 1 1h2a1 1 0 0 0 .7-.3l.6-.6v-1.4h1.4l1-1 4.9-4.9a5 5 0 1 0-1.6-9.7 5 5 0 0 0 0 .1Zm2 1.5a1.4 1.4 0 1 1 0 2.8 1.4 1.4 0 0 1 0-2.8Z',
};

const num = (n) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 });
const dinero = (n) => '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pct = (parte, total) => (total === 0 ? '0%' : Math.round((parte / total) * 100) + '%');
const suma = (filas, campo) => filas.reduce((a, f) => a + Number(f[campo] || 0), 0);

export const ESPECIFICACIONES = {
    movimientos: {
        // Cada movimiento cuenta uno: la medida es el número de viajes.
        medida: () => 1,
        formato: num,
        etiquetas: {
            estacion: 'Estación', categoria: 'Categoría', ruta: 'Ruta', tipo: 'Tipo',
            flota: 'Flota', estado: 'Estado', fecha: 'Mes', unidad: 'Unidad', piloto: 'Piloto',
        },
        kpis: [
            { titulo: 'Movimientos', icono: ICO.camion, valor: (f) => num(f.length) },
            { titulo: 'Horas en viaje', icono: ICO.reloj, valor: (f) => num(suma(f, 'horas')) },
            // El porcentaje se calcula solo sobre los terminados: un viaje en curso todavía no
            // llegó tarde, y meterlo en el denominador hunde el indicador sin motivo.
            {
                titulo: 'Con demora', icono: ICO.alerta,
                valor: (f) => {
                    const cerrados = f.filter((x) => x.estado === 'COMPLETADO');
                    return pct(cerrados.filter((x) => Number(x.demora) === 1).length, cerrados.length);
                },
            },
            { titulo: 'Con retorno aprovechado', icono: ICO.retorno, valor: (f) => num(f.filter((x) => Number(x.retorno) === 1).length) },
        ],
        // span = columnas (de 4) que ocupa cada panel; cada fila suma 4 para no dejar huecos.
        // El orden empareja paneles de altura parecida: las dos listas largas juntas y las
        // cortas entre sí. Al revés, una lista de 10 filas al lado de una de 2 deja la mitad
        // de la fila vacía.
        paneles: [
            { titulo: 'Movimientos por mes', dim: 'fecha', tiempo: true, span: 2 },
            { titulo: 'Nacional o internacional', dim: 'tipo', dona: true, span: 1 },
            { titulo: 'Flota propia o contratada', dim: 'flota', dona: true, span: 1 },
            { titulo: 'Rutas más usadas', dim: 'ruta', tope: 10, span: 2 },
            { titulo: 'Unidades más movidas', dim: 'unidad', tope: 10, span: 2 },
            { titulo: 'Por categoría', dim: 'categoria', span: 1 },
            { titulo: 'Estado', dim: 'estado', span: 1 },
            { titulo: 'Por estación', dim: 'estacion', span: 2 },
        ],
        tabla: [
            { titulo: 'Fecha', valor: (f) => f.fecha },
            { titulo: 'Unidad', valor: (f) => f.unidad },
            { titulo: 'Estación', valor: (f) => f.estacion },
            { titulo: 'Ruta', valor: (f) => f.ruta },
            { titulo: 'Tipo', valor: (f) => f.tipo },
            { titulo: 'Flota', valor: (f) => f.flota },
            { titulo: 'Horas', valor: (f) => f.horas },
            // Las notas del movimiento: es lo único que cuenta qué pasó en ese viaje.
            { titulo: 'Descripción', valor: (f) => f.descripcion, clase: 'col--desc' },
            { titulo: 'Estado', valor: (f) => f.estado },
        ],
    },

    costos: {
        dinero: true,
        // Aquí la medida es dinero: cada fila aporta su costo en dólares, no una unidad.
        medida: (f) => Number(f.costo || 0),
        formato: dinero,
        etiquetas: {
            estacion: 'Estación', categoria: 'Categoría', unidad: 'Unidad', marca: 'Marca',
            taller: 'Taller', tipo: 'Tipo', taller_tipo: 'Taller', fecha: 'Mes',
        },
        kpis: [
            { titulo: 'Gasto total', icono: ICO.dinero, valor: (f) => dinero(suma(f, 'costo')) },
            { titulo: 'Intervenciones', icono: ICO.llave, valor: (f) => num(f.length) },
            { titulo: 'Costo promedio', icono: ICO.reloj, valor: (f) => (f.length ? dinero(suma(f, 'costo') / f.length) : '$0.00') },
            { titulo: 'Unidades atendidas', icono: ICO.camion, valor: (f) => num(new Set(f.map((x) => x.unidad)).size) },
        ],
        paneles: [
            { titulo: 'Gasto por mes', dim: 'fecha', tiempo: true, span: 2 },
            { titulo: 'Preventivo o correctivo', dim: 'tipo', dona: true, span: 1 },
            { titulo: 'Taller propio o externo', dim: 'taller_tipo', dona: true, span: 1 },
            { titulo: 'Unidades con más gasto', dim: 'unidad', tope: 10, span: 2 },
            { titulo: 'Por taller', dim: 'taller', tope: 10, span: 2 },
            { titulo: 'Por marca', dim: 'marca', tope: 6, span: 2 },
            { titulo: 'Por estación', dim: 'estacion', span: 1 },
            { titulo: 'Por categoría', dim: 'categoria', span: 1 },
        ],
        tabla: [
            { titulo: 'Fecha', valor: (f) => f.fecha },
            { titulo: 'Unidad', valor: (f) => f.unidad },
            { titulo: 'Estación', valor: (f) => f.estacion },
            { titulo: 'Tipo', valor: (f) => f.tipo },
            // Texto libre y largo: se recorta en la celda y el completo va en el title.
            { titulo: 'Descripción', valor: (f) => f.descripcion, clase: 'col--desc' },
            { titulo: 'Taller', valor: (f) => f.taller },
            { titulo: 'Costo', valor: (f) => dinero(f.costo) },
        ],
    },
};
