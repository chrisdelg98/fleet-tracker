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
