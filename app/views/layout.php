<?php
/**
 * Layout base. Recibe $title y $content (ya renderizado) desde render() en helpers/view.php.
 *
 * @var string $title
 * @var string $content
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <title><?= e($title) ?></title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/assets/img/logo-small.png">
    <link rel="apple-touch-icon" href="/assets/img/logo-small.png">
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
        <div class="topbar__left">
            <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Abrir menú" aria-controls="app-sidebar" aria-expanded="false">
                <span class="nav-toggle__bars" aria-hidden="true"></span>
            </button>
            <div class="topbar__brand">
                <img src="/assets/img/logo-small.png" alt="Disponibilidad de Flota" class="topbar__logo">
                <div class="topbar__brand-copy">
                    <strong>Disponibilidad de Flota</strong>
                </div>
            </div>
        </div>

        <div class="topbar__nav">
            <div class="topbar__user-block">
                <strong class="topbar__user-name"><?= e($u['nombre']) ?></strong>
                <span class="topbar__user-role">(<?= e($u['rol']) ?>)</span>
            </div>
            <form method="post" action="/logout" class="topbar__logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--topbar">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <div class="app-shell__body">
        <aside class="sidebar" id="app-sidebar">
            <div class="sidebar__panel">
                <button type="button" class="sidebar__collapse" id="nav-close" aria-label="Ocultar menú" title="Ocultar menú">
                    <svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true"><path d="M12 5 7 10l5 5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>Ocultar</span>
                </button>
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
    <div class="nav-backdrop" id="nav-backdrop"></div>
<?php else: ?>
    <?= $content ?>
<?php endif; ?>
    <script src="/assets/js/nav.js" type="module"></script>
    <script src="/assets/js/filter-panel.js" type="module"></script>
    <script src="/assets/js/searchable-select.js" type="module"></script>
    <script src="/assets/js/rowmenu.js" type="module"></script>
    <script src="/assets/js/responsive-table.js" type="module"></script>
</body>
</html>
