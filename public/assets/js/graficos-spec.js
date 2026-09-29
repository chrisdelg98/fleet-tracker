/**
 * Qué mide y qué muestra cada hoja de gráficos.
 *
 * El motor (graficos.js) no sabe nada del negocio: recibe de aquí la medida, los paneles y las
 * columnas de la tabla. Añadir una hoja nueva es añadir una entrada, no tocar el dibujo.
 */

// 'en-US' y no 'es': el resto del sistema usa number_format() de PHP, que separa los miles con
// coma. Mezclar 1.234 y 1,234 en la misma pantalla se lee como dos cifras distintas.
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
            { titulo: 'Movimientos', valor: (f) => num(f.length) },
            { titulo: 'Horas en viaje', valor: (f) => num(suma(f, 'horas')) },
            // El porcentaje se calcula solo sobre los terminados: un viaje en curso todavía no
            // llegó tarde, y meterlo en el denominador hunde el indicador sin motivo.
            {
                titulo: 'Con demora',
                valor: (f) => {
                    const cerrados = f.filter((x) => x.estado === 'COMPLETADO');
                    return pct(cerrados.filter((x) => Number(x.demora) === 1).length, cerrados.length);
                },
            },
            { titulo: 'Con retorno aprovechado', valor: (f) => num(f.filter((x) => Number(x.retorno) === 1).length) },
        ],
        paneles: [
            { titulo: 'Movimientos por mes', dim: 'fecha', tiempo: true, ancho: true },
            { titulo: 'Por estación', dim: 'estacion' },
            { titulo: 'Por categoría', dim: 'categoria' },
            { titulo: 'Rutas más usadas', dim: 'ruta', tope: 10, ancho: true },
            { titulo: 'Nacional o internacional', dim: 'tipo' },
            { titulo: 'Flota propia o contratada', dim: 'flota' },
            { titulo: 'Estado', dim: 'estado' },
            { titulo: 'Unidades más movidas', dim: 'unidad', tope: 10 },
        ],
        tabla: [
            { titulo: 'Fecha', valor: (f) => f.fecha },
            { titulo: 'Unidad', valor: (f) => f.unidad },
            { titulo: 'Estación', valor: (f) => f.estacion },
            { titulo: 'Ruta', valor: (f) => f.ruta },
            { titulo: 'Tipo', valor: (f) => f.tipo },
            { titulo: 'Flota', valor: (f) => f.flota },
            { titulo: 'Horas', valor: (f) => f.horas },
            { titulo: 'Estado', valor: (f) => f.estado },
        ],
    },

    costos: {
        // Aquí la medida es dinero: cada fila aporta su costo en dólares, no una unidad.
        medida: (f) => Number(f.costo || 0),
        formato: dinero,
        etiquetas: {
            estacion: 'Estación', categoria: 'Categoría', unidad: 'Unidad', marca: 'Marca',
            taller: 'Taller', tipo: 'Tipo', taller_tipo: 'Taller', fecha: 'Mes',
        },
        kpis: [
            { titulo: 'Gasto total', valor: (f) => dinero(suma(f, 'costo')) },
            { titulo: 'Intervenciones', valor: (f) => num(f.length) },
            { titulo: 'Costo promedio', valor: (f) => (f.length ? dinero(suma(f, 'costo') / f.length) : '$0.00') },
            { titulo: 'Unidades atendidas', valor: (f) => num(new Set(f.map((x) => x.unidad)).size) },
        ],
        paneles: [
            { titulo: 'Gasto por mes', dim: 'fecha', tiempo: true, ancho: true },
            { titulo: 'Por estación', dim: 'estacion' },
            { titulo: 'Preventivo o correctivo', dim: 'tipo' },
            { titulo: 'Unidades con más gasto', dim: 'unidad', tope: 10, ancho: true },
            { titulo: 'Por taller', dim: 'taller', tope: 10 },
            { titulo: 'Taller propio o externo', dim: 'taller_tipo' },
            { titulo: 'Por marca', dim: 'marca', tope: 10 },
            { titulo: 'Por categoría', dim: 'categoria' },
        ],
        tabla: [
            { titulo: 'Fecha', valor: (f) => f.fecha },
            { titulo: 'Unidad', valor: (f) => f.unidad },
            { titulo: 'Estación', valor: (f) => f.estacion },
            { titulo: 'Tipo', valor: (f) => f.tipo },
            { titulo: 'Taller', valor: (f) => f.taller },
            { titulo: 'Costo', valor: (f) => dinero(f.costo) },
        ],
    },
};
