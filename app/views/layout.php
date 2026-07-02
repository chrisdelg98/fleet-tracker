<?php /** Layout base. Recibe $title y $content ya renderizado. */ ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php if (is_logged_in()): $u = current_user(); ?>
    <header class="topbar">
        <span class="topbar__brand">Disponibilidad de Flota</span>
        <nav class="topbar__nav">
            <span class="topbar__user"><?= e($u['nombre']) ?> · <?= e($u['rol']) ?></span>
            <form method="post" action="/logout" class="topbar__logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--ghost">Cerrar sesión</button>
            </form>
        </nav>
    </header>
<?php endif; ?>
    <main class="page">
        <?= $content ?>
    </main>
</body>
</html>
