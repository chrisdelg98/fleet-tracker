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
    <link rel="stylesheet" href="/assets/css/palette.css">
</head>
<body class="<?= is_logged_in() ? 'app-shell' : 'auth-page' ?>">
<?php if (is_logged_in()): $u = current_user(); $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); ?>
    <?php
    // Los accesos y sus permisos viven en menu_usuario() (app/helpers/navegacion.php).
    $menu = menu_usuario($u);
    $enlacePrincipal = $menu['principal'];
    $grupos = $menu['grupos'];
    $meta = page_meta();
    // Accesos permitidos para la paleta de búsqueda (tecla "."); el ícono va renderizado.
    $accesosPaleta = array_map(
        static fn(array $a): array => $a + ['icono' => nav_icon($a['href'])],
        accesos_usuario($u)
    );
    ?>
    <header class="topbar">
        <div class="topbar__left">
            <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Mostrar u ocultar el menú" title="Menú" aria-controls="app-sidebar" aria-expanded="false">
                <span class="nav-toggle__bars" aria-hidden="true"></span>
            </button>
            <a class="topbar__brand" href="/" title="Disponibilidad de Flota">
                <img src="/assets/img/logo-small.png" alt="Disponibilidad de Flota" class="topbar__logo">
            </a>
            <?php if ($meta['titulo'] !== ''): ?>
                <div class="topbar__page">
                    <?php if (!empty($meta['padre'])): ?>
                        <a class="topbar__crumb" href="<?= e($meta['padre']['href']) ?>"><?= e($meta['padre']['label']) ?></a>
                        <span class="topbar__crumb-sep" aria-hidden="true">›</span>
                    <?php endif; ?>
                    <h1 class="topbar__page-title"<?= $meta['descripcion'] !== '' ? ' title="' . e($meta['descripcion']) . '"' : '' ?>><?= e($meta['titulo']) ?></h1>
                </div>
            <?php endif; ?>
        </div>

        <div class="topbar__nav">
            <?php if ($meta['accion'] !== ''): ?>
                <div class="topbar__actions"><?= $meta['accion'] ?></div>
            <?php endif; ?>
            <?php if ($meta['acciones'] !== ''): ?>
                <div class="topbar__actions"><?= $meta['acciones'] ?></div>
            <?php endif; ?>

            <?php
            // Iniciales del nombre para el avatar (máx. 2 letras).
            $partes = preg_split('/\s+/', trim($u['nombre'])) ?: [];
            $iniciales = mb_strtoupper(mb_substr($partes[0] ?? '', 0, 1) . (count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));
            ?>
            <div class="usermenu" data-usermenu>
                <button type="button" class="usermenu__trigger" data-usermenu-trigger aria-haspopup="true" aria-expanded="false">
                    <span class="usermenu__avatar" aria-hidden="true"><?= e($iniciales) ?></span>
                    <span class="usermenu__name"><?= e($u['nombre']) ?></span>
                    <svg class="usermenu__chevron" viewBox="0 0 20 20" width="12" height="12" aria-hidden="true"><path d="M5 8l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div class="usermenu__panel" data-usermenu-panel hidden>
                    <div class="usermenu__id">
                        <strong><?= e($u['nombre']) ?></strong>
                        <span><?= e(Rol::label($u['rol'])) ?></span>
                        <small><?= e($u['email']) ?></small>
                    </div>
                    <form method="post" action="/logout">
                        <?= csrf_field() ?>
                        <button type="submit" class="usermenu__item">
                            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false">
                                <path d="M7.75 2.5a2.75 2.75 0 0 0-2.75 2.75v9.5a2.75 2.75 0 0 0 2.75 2.75h3.5a2.75 2.75 0 0 0 2.75-2.75.75.75 0 0 0-1.5 0c0 .69-.56 1.25-1.25 1.25h-3.5c-.69 0-1.25-.56-1.25-1.25v-9.5c0-.69.56-1.25 1.25-1.25h3.5c.69 0 1.25.56 1.25 1.25a.75.75 0 0 0 1.5 0A2.75 2.75 0 0 0 11.25 2.5h-3.5Zm7.72 5.22a.75.75 0 0 0-1.06 1.06l.47.47H9.5a.75.75 0 0 0 0 1.5h5.38l-.47.47a.75.75 0 1 0 1.06 1.06l1.75-1.75a.75.75 0 0 0 0-1.06l-1.75-1.75Z" fill="currentColor"/>
                            </svg>
                            <span>Cerrar sesión</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <div class="app-shell__body">
        <aside class="sidebar" id="app-sidebar">
            <div class="sidebar__panel">
                <nav class="sidebar__nav" aria-label="Navegación principal">
                    <div class="sidebar__primary">
                        <?php foreach ($enlacePrincipal as $href => $label):
                            $activo = $href === '/' ? $ruta === '/' : str_starts_with((string) $ruta, $href);
                        ?>
                            <a href="<?= e($href) ?>" class="sidebar__link<?= $activo ? ' is-active' : '' ?>"><?= nav_icon($href) ?><span><?= e($label) ?></span></a>
                        <?php endforeach; ?>
                    </div>

                    <?php foreach ($grupos as $titulo => $items): ?>
                        <?php if ($items === []): continue; endif; ?>
                        <?php $slug = strtolower(str_replace(' ', '-', $titulo)); ?>
                        <div class="sidebar__group is-open" data-section="<?= e($slug) ?>">
                            <button type="button" class="sidebar__group-toggle" aria-expanded="true">
                                <span><?= e($titulo) ?></span>
                                <svg class="sidebar__chevron" viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><path d="M7 4l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div class="sidebar__group-links">
                                <div class="sidebar__group-inner">
                                    <?php foreach ($items as $href => $label):
                                        $activo = $href === '/' ? $ruta === '/' : str_starts_with((string) $ruta, $href);
                                    ?>
                                        <a href="<?= e($href) ?>" class="sidebar__link<?= $activo ? ' is-active' : '' ?>"><?= nav_icon($href) ?><span><?= e($label) ?></span></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <button type="button" class="sidebar__collapse" id="nav-close" aria-label="Ocultar menú" title="Ocultar menú">
                        <svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true"><path d="M11 5l-4 5 4 5M15.5 5l-4 5 4 5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>Ocultar Menú</span>
                    </button>
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
    <script type="application/json" id="app-accesos"><?= json_encode($accesosPaleta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php else: ?>
    <?= $content ?>
<?php endif; ?>
    <script src="/assets/js/nav.js" type="module"></script>
    <script src="/assets/js/palette.js" type="module"></script>
    <script src="/assets/js/filter-panel.js" type="module"></script>
    <script src="/assets/js/searchable-select.js" type="module"></script>
    <script src="/assets/js/rowmenu.js" type="module"></script>
    <script src="/assets/js/responsive-table.js" type="module"></script>
</body>
</html>
