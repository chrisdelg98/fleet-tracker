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

<!-- El módulo está en uso pero aún se le harán ajustes: conviene decirlo donde se ve siempre,
     no en una sola pantalla, para que nadie tome sus números como definitivos. -->
<p class="form__warn" style="margin-bottom: var(--sp-3)">
    <strong>En construcción.</strong> Este módulo todavía está en ajustes: puedes usarlo, pero
    algunas pantallas y datos van a cambiar.
</p>
