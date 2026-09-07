<?php
/**
 * Administración › Correos enviados. Bitácora de los avisos que salieron del sistema.
 *
 * @var array $registro   {filas, total, paginas, pagina}
 * @var string $q
 * @var bool $soloFallidos
 * @var int $fallos
 */
set_page_meta(
    'Correos enviados',
    'Qué avisos salió a enviar el sistema, cuándo y si el servidor de correo pudo entregarlos.',
    ['padre' => ['label' => 'Administración', 'href' => '/admin']]
);

$EVENTOS = [
    'reserva.creada'     => 'Reserva creada',
    'reserva.reenviada'  => 'Reserva reenviada',
    'unidad.liberada'    => 'Unidad liberada',
    'retorno.disponible' => 'Retorno disponible',
];
$enlace = static function (array $cambios) use ($q, $soloFallidos): string {
    $base = ['q' => $q, 'fallidos' => $soloFallidos ? '1' : '', 'pagina' => ''];
    return '/admin/correos?' . http_build_query(array_filter(array_merge($base, $cambios), static fn($v): bool => $v !== ''));
};
?>
<section class="module">
    <form class="filters-panel" method="get" action="/admin/correos" data-filters-panel data-initial-open="<?= $q !== '' ? 'true' : 'false' ?>">
        <div class="filters-panel__bar">
            <div class="filters-panel__summary">
                <strong><?= (int) $registro['total'] ?> <?= $registro['total'] === 1 ? 'envío' : 'envíos' ?></strong>
                <span>
                    <?php if ($fallos > 0): ?>
                        <?= (int) $fallos ?> con problemas entre los últimos 100
                    <?php else: ?>
                        Sin fallos entre los últimos 100
                    <?php endif; ?>
                </span>
            </div>
            <button type="button" class="filters-panel__toggle" data-filters-toggle aria-expanded="false" aria-controls="correos-filters-more">
                <span data-filters-toggle-label data-open-label="Mostrar filtros" data-close-label="Ocultar filtros">Mostrar filtros</span>
                <span class="filters-panel__toggle-icon" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="filters-panel__more" id="correos-filters-more" data-filters-more hidden>
            <div class="filters-grid">
                <label class="field"><span class="field__label">Buscar</span>
                    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Placa, movimiento, motivo del error…" class="search"></label>
                <label class="check"><input type="checkbox" name="fallidos" value="1" <?= $soloFallidos ? 'checked' : '' ?>><span>Solo los que fallaron</span></label>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn btn--ghost-dark">Filtrar</button>
                <a href="/admin/correos" class="link">Limpiar</a>
            </div>
        </div>
    </form>

    <?php if ($registro['filas'] === []): ?>
        <div class="card empty"><div class="card__empty">
            <p><?= $q !== '' || $soloFallidos ? 'Ningún envío coincide con el filtro.' : 'Todavía no ha salido ningún correo del sistema.' ?></p>
        </div></div>
    <?php else: ?>
        <div class="card card--table">
            <table class="table">
                <thead><tr>
                    <th>Fecha y hora (UTC)</th>
                    <th>Aviso</th>
                    <th>Referencia</th>
                    <th>Destinatarios</th>
                    <th>Resultado</th>
                    <th>Lo disparó</th>
                </tr></thead>
                <tbody>
                <?php foreach ($registro['filas'] as $f): $ok = !empty($f['ok']); ?>
                    <tr>
                        <td><?= e((string) $f['fecha']) ?></td>
                        <td><?= e($EVENTOS[$f['evento']] ?? (string) $f['evento']) ?></td>
                        <td><?= e((string) ($f['referencia'] ?? '')) ?: '—' ?></td>
                        <td><?= (int) ($f['destinatarios'] ?? 0) ?></td>
                        <td>
                            <?php if ($ok): ?>
                                <span class="badge badge--ok">Aceptado</span>
                            <?php else: ?>
                                <span class="badge badge--alert">Falló</span>
                                <div class="muted"><?= e((string) ($f['error'] ?? '')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($f['usuario'] ?? '')) ?: 'Automático' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($registro['paginas'] > 1): ?>
            <nav class="pager">
                <?php for ($pag = 1; $pag <= $registro['paginas']; $pag++): ?>
                    <a href="<?= e($enlace(['pagina' => (string) $pag])) ?>"
                       class="pager__link<?= $pag === $registro['pagina'] ? ' is-active' : '' ?>"><?= $pag ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card" style="margin-top: var(--sp-4)">
        <p style="margin-top:0"><strong>«Aceptado» significa que el servidor de correo recibió el mensaje</strong>, no que
           haya llegado al buzón. La entrega ocurre después y fuera de este sistema: un rebote
           por una dirección inexistente o un rechazo del destinatario no se ve desde aquí.
           Para eso está el registro de entregas del hosting (en cPanel, <em>Track Delivery</em>)
           y el buzón del remitente, donde caen los rebotes.</p>
        <p class="muted">No se guardan las direcciones, solo cuántas eran: basta para saber si el
           servidor pudo recibir el mensaje y evita dejar contactos de clientes en un archivo de texto.</p>
    </div>
</section>
