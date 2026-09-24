<?php
/**
 * Barra de filtros única del módulo.
 *
 * Las cuatro pantallas tenían tres patrones distintos —una abierta y ordenada, otra abierta y
 * desordenada, dos escondidas tras «Mostrar filtros»—, así que en cada una había que aprender
 * de nuevo dónde buscar. Aquí viven todas: siempre visibles, en el mismo orden (búsqueda,
 * filtros, acciones) y con los mismos estilos.
 *
 * @var string $accion    URL del formulario
 * @var array  $campos    [['tipo' => 'buscar|select|fecha', 'name', 'label', 'valor', 'opciones'?, 'ancho'?]]
 * @var array  $ocultos   name => valor que hay que conservar al filtrar (paginación, tarjetas)
 * @var array  $filtros   los valores actuales, para saber si hay algo que limpiar
 * @var bool   $conRangos atajos de fecha; solo donde hay «desde» y «hasta»
 */
$hayAlgo = implode('', array_map(static fn($v): string => (string) $v, $filtros)) !== '';

// Atajos de fecha: el 90 % de las consultas son «este mes», «lo que va del año» o «todo», y
// tecleadas a mano son cuatro clics en dos calendarios.
$hoy = new DateTimeImmutable('today');
$rangos = [
    'Este mes'      => [$hoy->modify('first day of this month')->format('Y-m-d'), $hoy->format('Y-m-d')],
    'Últimos 3 meses' => [$hoy->modify('-3 months')->format('Y-m-d'), $hoy->format('Y-m-d')],
    'Este año'      => [$hoy->format('Y') . '-01-01', $hoy->format('Y-m-d')],
    'Todo'          => ['', ''],
];
?>
<form class="modfiltros" method="get" action="<?= e($accion) ?>">
    <?php foreach ($ocultos as $name => $valor): ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $valor) ?>">
    <?php endforeach; ?>

    <div class="modfiltros__campos">
        <?php foreach ($campos as $c):
            $ancho = $c['ancho'] ?? ($c['tipo'] === 'buscar' ? 'modfiltros__campo--ancho' : '');
        ?>
            <label class="field modfiltros__campo <?= e($ancho) ?>">
                <span class="field__label"><?= e($c['label']) ?></span>
                <?php if ($c['tipo'] === 'select'): ?>
                    <select name="<?= e($c['name']) ?>"<?= !empty($c['sinBuscador']) ? ' data-no-search' : '' ?>>
                        <?php foreach ($c['opciones'] as $valor => $texto): ?>
                            <option value="<?= e((string) $valor) ?>" <?= (string) $c['valor'] === (string) $valor ? 'selected' : '' ?>><?= e($texto) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($c['tipo'] === 'fecha'): ?>
                    <input type="date" name="<?= e($c['name']) ?>" value="<?= e((string) $c['valor']) ?>">
                <?php else: ?>
                    <input type="search" name="<?= e($c['name']) ?>" value="<?= e((string) $c['valor']) ?>"
                           placeholder="<?= e($c['placeholder'] ?? 'Buscar…') ?>" data-no-search>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>

        <div class="modfiltros__acciones">
            <button type="submit" class="btn btn--primary">Filtrar</button>
            <?php if ($hayAlgo): ?><a href="<?= e($accion) ?>" class="link">Limpiar</a><?php endif; ?>
        </div>
    </div>

    <?php if (!empty($conRangos)): ?>
        <div class="modfiltros__rangos">
            <span class="muted">Periodo:</span>
            <?php foreach ($rangos as $texto => [$d, $h]):
                $activo = (string) ($filtros['desde'] ?? '') === $d && (string) ($filtros['hasta'] ?? '') === $h;
                $qs = http_build_query(array_filter(
                    array_merge($filtros, $ocultos, ['desde' => $d, 'hasta' => $h]),
                    static fn($v): bool => $v !== '' && $v !== null
                ));
            ?>
                <a class="chipbtn<?= $activo ? ' is-active' : '' ?>" href="<?= e($accion . ($qs !== '' ? '?' . $qs : '')) ?>"><?= e($texto) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</form>
