<?php /** Layout base. Recibe $title y $content ya renderizado. */ ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="<?= is_logged_in() ? 'app-shell' : 'auth-page' ?>">
<?php if (is_logged_in()): $u = current_user(); $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); ?>
    <?php
    $puedeGestionar = in_array($u['rol'], [Rol::ADMIN_GLOBAL, Rol::ENCARGADO], true);
    $enlacePrincipal = ['/' => 'Dashboard'];
    $grupos = [
        'Operación' => [],
        'Consulta' => [],
        'Administración' => [],
    ];

    if ($puedeGestionar) {
        $grupos['Operación']['/flota'] = 'Flota';
        $grupos['Operación']['/pilotos'] = 'Pilotos';
        $grupos['Operación']['/rutas'] = 'Rutas';
    }
    if ($u['rol'] !== Rol::CONSULTA_BASICO) {
        $grupos['Consulta']['/inventario'] = 'Inventario';
        $grupos['Consulta']['/inteligencia'] = 'Inteligencia';
    }
    if (in_array($u['rol'], [Rol::ADMIN_GLOBAL, Rol::ENCARGADO], true)) {
        $grupos['Consulta']['/historico'] = 'Histórico';
    }
    if ($u['rol'] === Rol::ADMIN_GLOBAL) {
        $grupos['Administración']['/admin'] = 'Administración';
    }
    ?>
    <header class="topbar">
        <div class="topbar__brand">
            <img src="/assets/img/logo-small.png" alt="Disponibilidad de Flota" class="topbar__logo">
            <div class="topbar__brand-copy">
                <strong>Disponibilidad de Flota</strong>
                <small>Operación regional</small>
            </div>
        </div>

        <div class="topbar__nav">
            <div class="topbar__user-block">
                <strong class="topbar__user-name"><?= e($u['nombre']) ?></strong>
                <span class="topbar__user-role"><?= e($u['rol']) ?></span>
            </div>
            <form method="post" action="/logout" class="topbar__logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--topbar">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <div class="app-shell__body">
        <aside class="sidebar">
            <div class="sidebar__panel">
                <nav class="sidebar__nav" aria-label="Navegación principal">
                    <?php foreach ($enlacePrincipal as $href => $label):
                        $activo = $href === '/' ? $ruta === '/' : str_starts_with((string) $ruta, $href);
                    ?>
                        <a href="<?= e($href) ?>" class="sidebar__link sidebar__link--primary<?= $activo ? ' is-active' : '' ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>

                    <?php foreach ($grupos as $titulo => $items): ?>
                        <?php if ($items === []): continue; endif; ?>
                        <div class="sidebar__group">
                            <p class="sidebar__group-title"><?= e($titulo) ?></p>
                            <?php foreach ($items as $href => $label):
                                $activo = $href === '/' ? $ruta === '/' : str_starts_with((string) $ruta, $href);
                            ?>
                                <a href="<?= e($href) ?>" class="sidebar__link<?= $activo ? ' is-active' : '' ?>"><?= e($label) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </nav>
            </div>
        </aside>

        <main class="page-shell">
            <div class="page-shell__inner">
                <?= $content ?>
            </div>
        </main>
    </div>
<?php else: ?>
    <?= $content ?>
<?php endif; ?>
    <script src="/assets/js/filter-panel.js" type="module"></script>
    <script src="/assets/js/searchable-select.js" type="module"></script>
</body>
</html>
