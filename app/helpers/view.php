<?php
/**
 * Render de vistas PHP dentro del layout base. Toda salida hacia HTML se escapa con
 * htmlspecialchars() en las vistas (AGENTS.md §Seguridad 5).
 */

declare(strict_types=1);

/** Atajo de escape para usar en las plantillas. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Renderiza una acción compacta y reutilizable para filas de tabla. */
function action_chip(string $label, array $options = []): string
{
    $variant = $options['variant'] ?? 'neutral';
    $icon = $options['icon'] ?? 'edit';
    $class = trim('action-chip action-chip--' . $variant . ' ' . ($options['class'] ?? ''));
    $attrs = $options['attrs'] ?? [];
    $tag = isset($options['href']) ? 'a' : 'button';

    if ($tag === 'a') {
        $attrs['href'] = $options['href'];
    } else {
        $attrs['type'] = $options['type'] ?? 'button';
    }

    $attrs['class'] = trim(($attrs['class'] ?? '') . ' ' . $class);

    $parts = [];
    foreach ($attrs as $name => $value) {
        if ($value === null || $value === false) {
            continue;
        }
        if ($value === true) {
            $parts[] = e((string) $name);
            continue;
        }
        $parts[] = e((string) $name) . '="' . e((string) $value) . '"';
    }

    return sprintf(
        '<%1$s %2$s><span class="action-chip__icon" aria-hidden="true">%3$s</span><span>%4$s</span></%1$s>',
        $tag,
        implode(' ', $parts),
        action_chip_icon($icon),
        e($label)
    );
}

function action_chip_icon(string $icon): string
{
    return match ($icon) {
        'delete' => '<svg viewBox="0 0 20 20" focusable="false"><path d="M7.5 2.75A1.75 1.75 0 0 0 5.75 4.5V5H3.5a.75.75 0 0 0 0 1.5h.62l.7 8.38A2.25 2.25 0 0 0 7.06 17h5.88a2.25 2.25 0 0 0 2.24-2.12l.7-8.38h.62a.75.75 0 0 0 0-1.5h-2.25v-.5A1.75 1.75 0 0 0 12.5 2.75h-5Zm5.25 2.25v-.5a.25.25 0 0 0-.25-.25h-5a.25.25 0 0 0-.25.25V5h5.5Zm-4 3a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 8.75 8Zm3.25 0a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5a.75.75 0 0 1 .75-.75Z" fill="currentColor"/></svg>',
        'toggle-off' => '<svg viewBox="0 0 20 20" focusable="false"><path d="M5.75 4.5a5.75 5.75 0 1 0 0 11.5h8.5a5.75 5.75 0 0 0 0-11.5h-8.5Zm0 1.5a4.25 4.25 0 1 1 0 8.5 4.25 4.25 0 0 1 0-8.5Z" fill="currentColor"/></svg>',
        'toggle-on' => '<svg viewBox="0 0 20 20" focusable="false"><path d="M5.75 4.5a5.75 5.75 0 1 0 0 11.5h8.5a5.75 5.75 0 0 0 0-11.5h-8.5Zm8.5 1.5a4.25 4.25 0 1 1 0 8.5 4.25 4.25 0 0 1 0-8.5Z" fill="currentColor"/></svg>',
        'state' => '<svg viewBox="0 0 20 20" focusable="false"><path d="M10 2.5a1 1 0 0 1 1 1v5.8l3.15 1.82a1 1 0 1 1-1 1.73l-3.65-2.1A1 1 0 0 1 9 9.9V3.5a1 1 0 0 1 1-1Zm0 15a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" fill="currentColor"/></svg>',
        default => '<svg viewBox="0 0 20 20" focusable="false"><path d="m14.69 2.86 2.45 2.45a1.25 1.25 0 0 1 0 1.77l-8.7 8.7-3.74.67.67-3.74 8.7-8.7a1.25 1.25 0 0 1 1.77 0ZM13.2 5.1l1.7 1.7 1.18-1.17-1.7-1.7L13.2 5.1Zm.64 2.77-1.7-1.7-5.6 5.6-.3 1.7 1.7-.3 5.9-5.3Z" fill="currentColor"/></svg>',
    };
}

/**
 * Menú de acciones por fila (estándar reutilizable para todas las tablas). Emite el markup
 * que consume rowmenu.js (botón "⋮" + menú porteado a <body>). Cada item:
 *   ['label' => 'Editar', 'danger' => false, 'attrs' => ['data-action' => 'editar', 'data-id' => 5]]
 * Los data-* los maneja el JS del módulo (delegación en document). Poner acciones destructivas
 * al final (ej. Eliminar) marcadas con 'danger' => true.
 */
function row_menu(array $items): string
{
    $items = array_values(array_filter($items));
    if ($items === []) {
        return '<span class="muted">—</span>';
    }

    $kebab = '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M10 6.2a1.4 1.4 0 1 0 0-2.8 1.4 1.4 0 0 0 0 2.8Zm0 5.2a1.4 1.4 0 1 0 0-2.8 1.4 1.4 0 0 0 0 2.8Zm0 5.2a1.4 1.4 0 1 0 0-2.8 1.4 1.4 0 0 0 0 2.8Z" fill="currentColor"/></svg>';
    $html = '<div class="rowmenu" data-rowmenu>'
        . '<button type="button" class="rowmenu__trigger" data-rowmenu-trigger aria-haspopup="true" aria-expanded="false" aria-label="Acciones">' . $kebab . '</button>'
        . '<div class="rowmenu__menu" role="menu">';

    foreach ($items as $item) {
        $class = 'rowmenu__item' . (!empty($item['danger']) ? ' rowmenu__item--danger' : '');
        $attrs = '';
        foreach (($item['attrs'] ?? []) as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $attrs .= ' ' . e((string) $name) . '="' . e((string) $value) . '"';
        }
        $html .= '<button type="button" role="menuitem" class="' . $class . '"' . $attrs . '>' . e((string) $item['label']) . '</button>';
    }

    return $html . '</div></div>';
}

/**
 * Ícono de un ítem del menú lateral. La clave es la ruta ('/', '/flota', …); si no hay ícono
 * definido se devuelve un punto neutro para que la lista no pierda la alineación.
 */
function nav_icon(string $ruta): string
{
    $path = match ($ruta) {
        '/' => 'M3 9.5 10 3l7 6.5V16a1.5 1.5 0 0 1-1.5 1.5h-3v-5h-5v5h-3A1.5 1.5 0 0 1 3 16V9.5Z',
        '/live' => 'M10 5.5a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Zm0 2a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5ZM4.2 3.3a.9.9 0 0 1 .1 1.3 7.5 7.5 0 0 0 0 10.8.9.9 0 1 1-1.2 1.3 9.3 9.3 0 0 1 0-13.4.9.9 0 0 1 1.1 0Zm11.6 0a.9.9 0 0 1 1.1 0 9.3 9.3 0 0 1 0 13.4.9.9 0 1 1-1.2-1.3 7.5 7.5 0 0 0 0-10.8.9.9 0 0 1 .1-1.3Z',
        '/flota' => 'M2 6.25A1.25 1.25 0 0 1 3.25 5h7.5A1.25 1.25 0 0 1 12 6.25V8h2.4a1.5 1.5 0 0 1 1.2.6l1.6 2.1a1.5 1.5 0 0 1 .3.9v2.65A.75.75 0 0 1 16.75 15h-.9a2.35 2.35 0 0 1-4.6 0H9.1a2.35 2.35 0 0 1-4.6 0h-1A1.5 1.5 0 0 1 2 13.5v-7.25ZM12 9.5v3h.36a2.35 2.35 0 0 1 3.28 0H16v-1.15L14.4 9.5H12ZM6.8 13.4a.95.95 0 1 0 1.9 0 .95.95 0 0 0-1.9 0Zm6.75 0a.95.95 0 1 0 1.9 0 .95.95 0 0 0-1.9 0Z',
        '/pilotos' => 'M10 3a3.25 3.25 0 1 1 0 6.5A3.25 3.25 0 0 1 10 3Zm0 8c3.1 0 5.75 1.72 5.75 3.9V16a1 1 0 0 1-1 1h-9.5a1 1 0 0 1-1-1v-1.1C4.25 12.72 6.9 11 10 11Z',
        '/rutas' => 'M5.5 2.5a2.75 2.75 0 0 1 2.75 2.75c0 1.9-2.06 4.2-2.53 4.7a.3.3 0 0 1-.44 0C4.81 9.45 2.75 7.15 2.75 5.25A2.75 2.75 0 0 1 5.5 2.5Zm0 2a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8Zm9 5.5a2.75 2.75 0 0 1 2.75 2.75c0 1.9-2.06 4.2-2.53 4.7a.3.3 0 0 1-.44 0c-.47-.5-2.53-2.8-2.53-4.7A2.75 2.75 0 0 1 14.5 10Zm0 2a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8ZM8.2 12.1a.75.75 0 0 1 0 1.5H7a1.6 1.6 0 0 1 0-3.2h5.2a.75.75 0 0 1 0 1.5H7a.1.1 0 0 0 0 .2h1.2Z',
        '/timeline' => 'M6 2a1 1 0 0 1 1 1v1h6V3a1 1 0 1 1 2 0v1h1.25A2.75 2.75 0 0 1 19 6.75v8.5A2.75 2.75 0 0 1 16.25 18h-12.5A2.75 2.75 0 0 1 1 15.25v-8.5A2.75 2.75 0 0 1 3.75 4H5V3a1 1 0 0 1 1-1Zm10.25 6h-12.5a.75.75 0 0 0-.75.75v6.5c0 .414.336.75.75.75h12.5a.75.75 0 0 0 .75-.75v-6.5a.75.75 0 0 0-.75-.75ZM5 10h3v2H5v-2Zm4 0h3v2H9v-2Zm4 0h2v2h-2v-2Z',
        '/inventario' => 'M3.5 3h13A1.5 1.5 0 0 1 18 4.5v2A1.5 1.5 0 0 1 16.5 8h-13A1.5 1.5 0 0 1 2 6.5v-2A1.5 1.5 0 0 1 3.5 3Zm0 6.5h13a1.5 1.5 0 0 1 1.5 1.5v4.5a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 2 15.5V11a1.5 1.5 0 0 1 1.5-1.5ZM7 12a.75.75 0 0 0 0 1.5h6A.75.75 0 0 0 13 12H7Z',
        '/inteligencia' => 'M4 15.5a1 1 0 0 1-1-1V4a1 1 0 0 1 2 0v9.5h11a1 1 0 0 1 0 2H4Zm3.5-3a.9.9 0 0 1-.9-.9V9a.9.9 0 1 1 1.8 0v2.6a.9.9 0 0 1-.9.9Zm3.4 0a.9.9 0 0 1-.9-.9V6.6a.9.9 0 1 1 1.8 0v5a.9.9 0 0 1-.9.9Zm3.4 0a.9.9 0 0 1-.9-.9V8a.9.9 0 1 1 1.8 0v3.6a.9.9 0 0 1-.9.9Z',
        '/historico' => 'M10 2.5a7.5 7.5 0 1 1-6.9 4.55.75.75 0 1 1 1.38.6A6 6 0 1 0 10 4c-1.6 0-3.05.63-4.12 1.65l1.3.02a.75.75 0 0 1-.02 1.5l-3.1-.05a.75.75 0 0 1-.74-.76l.05-3.1a.75.75 0 1 1 1.5.03l-.02 1.13A7.47 7.47 0 0 1 10 2.5Zm.75 3.75v3.44l2.4 1.4a.75.75 0 1 1-.76 1.29l-2.77-1.62a.75.75 0 0 1-.37-.65V6.25a.75.75 0 0 1 1.5 0Z',
        '/admin' => 'M8.94 2.5h2.12a1 1 0 0 1 .98.8l.2.98c.36.14.7.33 1 .55l.95-.32a1 1 0 0 1 1.19.45l1.06 1.84a1 1 0 0 1-.21 1.25l-.75.66c.03.2.04.4.04.6s-.01.4-.04.6l.75.66a1 1 0 0 1 .21 1.25l-1.06 1.84a1 1 0 0 1-1.19.45l-.95-.32c-.3.22-.64.4-1 .55l-.2.98a1 1 0 0 1-.98.8H8.94a1 1 0 0 1-.98-.8l-.2-.98a5.4 5.4 0 0 1-1-.55l-.95.32a1 1 0 0 1-1.19-.45l-1.06-1.84a1 1 0 0 1 .21-1.25l.75-.66a4.9 4.9 0 0 1 0-1.2l-.75-.66a1 1 0 0 1-.21-1.25l1.06-1.84a1 1 0 0 1 1.19-.45l.95.32c.3-.22.64-.4 1-.55l.2-.98a1 1 0 0 1 .98-.8ZM10 7.75a2.25 2.25 0 1 0 0 4.5 2.25 2.25 0 0 0 0-4.5Z',
        default => 'M10 7.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z',
    };

    return '<svg class="sidebar__icon" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="' . $path . '" fill="currentColor"/></svg>';
}

/**
 * Identidad de la pantalla (título, descripción y acciones) que se pinta en la topbar en lugar
 * de ocupar una cabecera propia dentro del contenido. La fija la vista y el layout la lee después,
 * porque render() captura la vista completa antes de incluir el layout.
 *
 * @param array $opciones ['padre'    => ['label' => 'Administración', 'href' => '/admin'],
 *                         'acciones' => HTML de acciones secundarias (junto a Cerrar sesión),
 *                         'accion'   => HTML de la acción principal (extremo derecho)]
 */
function set_page_meta(string $titulo, string $descripcion = '', array $opciones = []): void
{
    $GLOBALS['__page_meta'] = [
        'titulo' => $titulo,
        'descripcion' => $descripcion,
        'padre' => $opciones['padre'] ?? null,
        'acciones' => $opciones['acciones'] ?? '',
        'accion' => $opciones['accion'] ?? '',
    ];
}

function page_meta(): array
{
    return $GLOBALS['__page_meta'] ?? ['titulo' => '', 'descripcion' => '', 'padre' => null, 'acciones' => '', 'accion' => ''];
}

/**
 * Renderiza una plantilla de app/views/ envuelta en el layout.
 *
 * @param string $template Ruta relativa sin extensión, ej. "auth/login".
 * @param array  $data     Variables disponibles en la plantilla.
 */
function render(string $template, array $data = [], string $title = 'Disponibilidad de Flota'): void
{
    extract($data, EXTR_SKIP);

    ob_start();
    require BASE_PATH . '/app/views/' . $template . '.php';
    $content = ob_get_clean();

    require BASE_PATH . '/app/views/layout.php';
}
