<?php
/**
 * Mural en vivo (wallboard / kiosk) para pantalla de oficina. Página autónoma (sin el
 * chrome de la app): consume /api/disponibilidad y se auto-refresca. Ver mural.js / mural.css.
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
    <link rel="stylesheet" href="/assets/css/mural.css">
</head>
<body class="mural">
    <header class="mural__top">
        <div class="mural__brand">
            <img src="/assets/img/logo-small.png" alt="" class="mural__logo">
            <div class="mural__brand-copy">
                <strong>Disponibilidad de Flota</strong>
                <span class="mural__live"><span class="mural__dot" id="mural-dot"></span> En vivo</span>
            </div>
        </div>

        <div class="mural__tools">
            <label class="mural__field">
                <select id="mural-estacion" aria-label="Filtrar por estación">
                    <option value="">Todas las estaciones</option>
                    <?php foreach ($estaciones as $es): ?>
                        <option value="<?= e($es['codigo']) ?>"><?= e($es['codigo']) ?> · <?= e($es['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="button" class="mural__icon-btn" id="mural-theme" aria-label="Cambiar tema" title="Cambiar tema"></button>
        </div>

        <div class="mural__clock">
            <strong id="mural-time">--:--:--</strong>
            <span id="mural-date">—</span>
        </div>
        <a class="mural__exit" href="/" title="Volver al panel" aria-label="Salir del mural">✕</a>
    </header>

    <section class="mural__kpis" id="mural-kpis"><!-- KPIs por JS --></section>

    <section class="mural__body">
        <aside class="mural__panel mural__chart-panel">
            <h2>Distribución de la flota</h2>
            <div class="mural__donut-wrap">
                <svg viewBox="0 0 42 42" class="mural__donut" id="mural-donut" aria-hidden="true"></svg>
                <div class="mural__donut-center">
                    <strong id="mural-total">0</strong>
                    <span>unidades</span>
                </div>
            </div>
            <ul class="mural__legend" id="mural-legend"><!-- leyenda por JS --></ul>
        </aside>

        <div class="mural__grid-wrap">
            <div class="mural__grid" id="mural-grid"><!-- tiles por JS --></div>
        </div>
    </section>

    <footer class="mural__foot">
        <span id="mural-status" class="mural__status">Conectando…</span>
        <span id="mural-filterinfo" class="mural__filterinfo"></span>
        <span id="mural-updated" class="mural__updated"></span>
    </footer>

    <dialog class="mural-modal" id="mural-modal">
        <div class="mural-modal__inner">
            <button type="button" class="mural-modal__close" data-modal-close aria-label="Cerrar">✕</button>
            <div class="mural-modal__panel" id="mural-modal-body"><!-- detalle por JS --></div>
        </div>
    </dialog>

    <script src="/assets/js/mural.js" type="module"></script>
</body>
</html>
