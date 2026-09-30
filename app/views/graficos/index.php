<?php
/**
 * Consulta › Gráficos: la hoja de movimientos, que se explora pulsando las barras.
 *
 * Los costos de mantenimiento tenían aquí su propia hoja y se mudaron a una pestaña del módulo
 * de mantenimientos: quien va a mirar en qué se fue el dinero entra por ahí, no por Consulta.
 *
 * @var array    $usuario
 * @var array    $filas
 * @var array    $rango
 * @var int|null $estacionSel
 * @var bool     $verTodas
 * @var array    $estaciones
 */
set_page_meta(
    'Gráficos',
    'Pulsa cualquier barra para filtrar el resto de la hoja. Se pueden combinar.',
);
$hoja = 'movimientos';
$accion = '/graficos';
?>
<section class="module">
    <?php require __DIR__ . '/_hoja.php'; ?>
</section>
