<?php
/** Landing de Administración (plan §9.1). Solo Admin Global. */
set_page_meta('Administración', 'Gestiona sedes, usuarios y catálogos operativos desde un solo punto de control.');

$secciones = [
    [
        'href' => '/admin/estaciones',
        'kicker' => 'Estructura',
        'titulo' => 'Estaciones',
        'copy' => 'Sedes de la empresa, códigos, país y zona horaria para la operación regional.',
        'cta' => 'Administrar estaciones',
        'icono' => 'M3 21V6a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v3h4a2 2 0 0 1 2 2v10H3Zm3-11h3V7H6v3Zm0 5h3v-3H6v3Zm0 5h3v-3H6v3Zm5-10h3V7h-3v3Zm0 5h3v-3h-3v3Zm0 5h3v-3h-3v3Zm5 0h3v-3h-3v3Zm0-5h3v-3h-3v3Z',
    ],
    [
        'href' => '/admin/usuarios',
        'kicker' => 'Acceso',
        'titulo' => 'Usuarios',
        'copy' => 'Cuentas, roles, asignación por estación y control de acceso del personal.',
        'cta' => 'Gestionar usuarios',
        'icono' => 'M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7.6 0a3.1 3.1 0 1 0 0-6.2 3.1 3.1 0 0 0 0 6.2ZM2 19.6C2 16.5 5.13 14 9 14s7 2.5 7 5.6V21H2v-1.4Zm15.6-4.4c2.45 0 4.4 1.7 4.4 3.8V21h-4.5v-1.4c0-1.45-.42-2.8-1.15-3.95.4-.3.79-.45 1.25-.45Z',
    ],
    [
        'href' => '/admin/catalogos',
        'kicker' => 'Parámetros',
        'titulo' => 'Catálogos',
        'copy' => 'Países, categorías de vehículo, tipos de equipo, licencias y permisos operativos.',
        'cta' => 'Revisar catálogos',
        'icono' => 'M12 2.4 22.2 8 12 13.6 1.8 8 12 2.4Zm7.9 8.2 2.3 1.3L12 17.5 1.8 11.9l2.3-1.3L12 14.9l7.9-4.3Zm0 4.6 2.3 1.3L12 22.1 1.8 16.5l2.3-1.3L12 19.5l7.9-4.3Z',
    ],
];
?>
<section class="module">
    <div class="admin-grid">
        <?php foreach ($secciones as $s): ?>
            <a class="card admin-card" href="<?= e($s['href']) ?>">
                <span class="admin-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="25" height="25" focusable="false"><path d="<?= e($s['icono']) ?>" fill="currentColor"/></svg>
                </span>
                <span class="admin-card__kicker"><?= e($s['kicker']) ?></span>
                <h2><?= e($s['titulo']) ?></h2>
                <p class="muted"><?= e($s['copy']) ?></p>
                <span class="admin-card__cta">
                    <?= e($s['cta']) ?>
                    <svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true" focusable="false"><path d="M4 9h8.2l-3.1-3.1L10.5 4.5 16 10l-5.5 5.5-1.4-1.4L12.2 11H4V9Z" fill="currentColor"/></svg>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
