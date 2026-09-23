<?php
/**
 * Barra de secciones del módulo. El menú lateral tiene un solo acceso —Mantenimientos— y aquí
 * dentro están todas sus pantallas, para no llenar el menú con cinco entradas de lo mismo.
 *
 * @var string $seccion  clave de la sección activa
 */
$secciones = [
    'control'       => ['/mantenimientos', 'Control'],
    'historial'     => ['/mantenimientos/historial', 'Historial'],
    'costos'        => ['/mantenimientos/costos', 'Costos'],
    'talleres'      => ['/mantenimientos/talleres', 'Talleres'],
    'configuracion' => ['/mantenimientos/configuracion', 'Configuración'],
];
?>
<nav class="subnav" aria-label="Secciones de mantenimientos">
    <?php foreach ($secciones as $clave => [$href, $texto]): ?>
        <a href="<?= e($href) ?>" class="subnav__link<?= $clave === $seccion ? ' is-active' : '' ?>"
           <?= $clave === $seccion ? 'aria-current="page"' : '' ?>><?= e($texto) ?></a>
    <?php endforeach; ?>
</nav>
