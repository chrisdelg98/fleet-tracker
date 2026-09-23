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
    'configuracion' => ['/mantenimientos/configuracion/planes', 'Configuración'],
];

// Configuración son tres catálogos distintos. Juntos en una pantalla había que leer tres tablas
// para encontrar un dato; separados, cada uno se explica solo.
$deConfiguracion = [
    'planes'  => ['/mantenimientos/configuracion/planes',  'Planes de servicio'],
    'tipos'   => ['/mantenimientos/configuracion/tipos',   'Tipos de mantenimiento'],
    'monedas' => ['/mantenimientos/configuracion/monedas', 'Monedas'],
];
?>
<nav class="subnav" aria-label="Secciones de mantenimientos">
    <?php foreach ($secciones as $sec => [$href, $texto]): ?>
        <a href="<?= e($href) ?>" class="subnav__link<?= $sec === $seccion ? ' is-active' : '' ?>"
           <?= $sec === $seccion ? 'aria-current="page"' : '' ?>><?= e($texto) ?></a>
    <?php endforeach; ?>
</nav>

<!-- El módulo está en uso pero aún se le harán ajustes: conviene decirlo donde se ve siempre,
     no en una sola pantalla, para que nadie tome sus números como definitivos. -->
<p class="form__warn" style="margin-bottom: var(--sp-3)">
    <strong>En construcción.</strong> Este módulo todavía está en ajustes: puedes usarlo, pero
    algunas pantallas y datos van a cambiar.
</p>

<?php if ($seccion === 'configuracion'): ?>
<nav class="subnav subnav--hija" aria-label="Catálogos de configuración">
    <?php foreach ($deConfiguracion as $c => [$href, $texto]): ?>
        <a href="<?= e($href) ?>" class="subnav__link<?= ($clave ?? 'planes') === $c ? ' is-active' : '' ?>"
           <?= ($clave ?? 'planes') === $c ? 'aria-current="page"' : '' ?>><?= e($texto) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
