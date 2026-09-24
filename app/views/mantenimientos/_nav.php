<?php
/**
 * Barra del módulo. El menú lateral tiene un solo acceso —Mantenimientos— y aquí dentro están
 * todas sus pantallas, para no llenar el menú con cinco entradas de lo mismo.
 *
 * Configuración es un desplegable y no tres pestañas más: son catálogos que se tocan de vez en
 * cuando, y ponerlos al mismo nivel que Control escondía lo que se usa a diario.
 *
 * @var string $seccion  clave de la sección activa
 */
$secciones = [
    'control'   => ['/mantenimientos', 'Control'],
    'historial' => ['/mantenimientos/historial', 'Historial'],
    'costos'    => ['/mantenimientos/costos', 'Costos'],
    'talleres'  => ['/mantenimientos/talleres', 'Talleres'],
];
$deConfiguracion = [
    'planes'  => ['/mantenimientos/configuracion/planes',  'Planes de servicio'],
    'tipos'   => ['/mantenimientos/configuracion/tipos',   'Tipos de mantenimiento'],
    'monedas' => ['/mantenimientos/configuracion/monedas', 'Monedas'],
];
$enConfig = $seccion === 'configuracion';
$claveConfig = $clave ?? 'planes';
?>
<nav class="modnav" aria-label="Secciones de mantenimientos">
    <?php foreach ($secciones as $sec => [$href, $texto]): ?>
        <a href="<?= e($href) ?>" class="modnav__link<?= $sec === $seccion ? ' is-active' : '' ?>"
           <?= $sec === $seccion ? 'aria-current="page"' : '' ?>><?= e($texto) ?></a>
    <?php endforeach; ?>

    <!-- Se apoya en el menú de acciones que ya existe: cierra al hacer clic fuera y con Escape. -->
    <div class="rowmenu modnav__config" data-rowmenu>
        <button type="button" class="modnav__link modnav__trigger<?= $enConfig ? ' is-active' : '' ?>"
                data-rowmenu-trigger aria-haspopup="true" aria-expanded="false">
            <?= $enConfig ? e($deConfiguracion[$claveConfig][1]) : 'Configuración' ?>
            <span class="modnav__caret" aria-hidden="true">▾</span>
        </button>
        <div class="rowmenu__menu" role="menu">
            <?php foreach ($deConfiguracion as $c => [$href, $texto]): ?>
                <a href="<?= e($href) ?>" role="menuitem"
                   class="rowmenu__item<?= $enConfig && $claveConfig === $c ? ' is-active' : '' ?>"><?= e($texto) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</nav>
