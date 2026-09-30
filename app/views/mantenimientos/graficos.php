<?php
/**
 * Mantenimientos › Gráficos. La misma hoja de BI que vivía en Consulta, ahora donde se
 * consulta el resto del mantenimiento.
 *
 * @var array    $usuario
 * @var array    $filas
 * @var array    $rango
 * @var int|null $estacionSel
 * @var bool     $verTodas
 * @var array    $estaciones
 */
set_page_meta(
    'Costos de mantenimiento',
    'Pulsa cualquier barra para filtrar el resto de la hoja. Se pueden combinar.',
);
$seccion = 'graficos';
$hoja = 'costos';
$accion = '/mantenimientos/graficos';
?>
<section class="module">
    <?php require __DIR__ . '/_nav.php'; ?>
    <?php require __DIR__ . '/../graficos/_hoja.php'; ?>
</section>
