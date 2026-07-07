<?php
/**
 * Live en vivo (wallboard / kiosk) para pantalla de oficina. Página autónoma (sin el
 * chrome de la app): consume /api/disponibilidad y se auto-refresca. Ver live.js / live.css.
 *
 * @var array  $user
 * @var array  $estaciones
 * @var string $title
 */
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <title><?= e($title) ?></title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/assets/img/logo-small.png">
    <link rel="stylesheet" href="/assets/css/live.css">
</head>
<body class="live">
    <header class="live__top">
        <div class="live__brand">
            <img src="/assets/img/logo-small.png" alt="" class="live__logo">
            <div class="live__brand-copy">
                <strong>Disponibilidad de Flota</strong>
                <span class="live__pulse"><span class="live__dot" id="live-dot"></span> En vivo</span>
            </div>
        </div>

        <div class="live__clock">
            <strong id="live-time">--:--:--</strong>
            <span id="live-date">—</span>
        </div>

        <div class="live__right">
            <button type="button" class="live__icon-btn" id="live-panel-toggle" aria-label="Mostrar u ocultar la distribución" title="Distribución de la flota" aria-pressed="false"></button>
            <button type="button" class="live__icon-btn" id="live-theme" aria-label="Cambiar tema" title="Cambiar tema"></button>
            <a class="live__exit" href="/" title="Volver al panel" aria-label="Salir del live">✕</a>
        </div>
    </header>

    <div class="live__filters">
        <span class="live__filters-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18l-7 8v6l-4-2v-4z"/></svg></span>
        <label class="live__field">
            <select id="live-estacion" aria-label="Filtrar por estación">
                <option value="">Todas las estaciones</option>
                <?php foreach ($estaciones as $es): ?>
                    <option value="<?= e($es['codigo']) ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="live__field">
            <select id="live-size" aria-label="Filtrar por tamaño"><option value="">Todos los tamaños</option></select>
        </label>
        <label class="live__field">
            <select id="live-tipo" aria-label="Filtrar por tipo"><option value="">Todos los tipos</option></select>
        </label>
    </div>

    <section class="live__kpis" id="live-kpis"><!-- KPIs por JS --></section>

    <section class="live__body">
        <aside class="live__panel live__chart-panel">
            <h2>Distribución de la flota</h2>
            <div class="live__donut-wrap">
                <svg viewBox="0 0 42 42" class="live__donut" id="live-donut" aria-hidden="true"></svg>
                <div class="live__donut-center">
                    <strong id="live-total">0</strong>
                    <span>unidades</span>
                </div>
            </div>
            <div class="live__summary" id="live-summary"><!-- resumen por JS --></div>
            <ul class="live__legend" id="live-legend"><!-- leyenda por JS --></ul>
        </aside>

        <div class="live__grid-wrap">
            <div class="live__grid" id="live-grid"><!-- tiles por JS --></div>
        </div>
    </section>

    <footer class="live__foot">
        <span id="live-status" class="live__status">Conectando…</span>
        <span id="live-filterinfo" class="live__filterinfo"></span>
        <span id="live-updated" class="live__updated"></span>
    </footer>

    <dialog class="live-modal" id="live-modal">
        <div class="live-modal__inner">
            <button type="button" class="live-modal__close" data-modal-close aria-label="Cerrar">✕</button>
            <div class="live-modal__panel" id="live-modal-body"><!-- detalle por JS --></div>
        </div>
    </dialog>

    <script src="/assets/js/live.js" type="module"></script>
</body>
</html>
