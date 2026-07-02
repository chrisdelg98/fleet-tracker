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
