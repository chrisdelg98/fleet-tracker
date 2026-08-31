<?php
/**
 * Desplegable de países agrupado por región (plan §5.3, §9.4). Todo campo de país en el
 * sistema sale de aquí — nunca texto libre. Se agrupa en optgroups y se ordena por el
 * campo `orden` (Centroamérica primero).
 */

declare(strict_types=1);

/** Etiquetas legibles por región, en el orden en que deben mostrarse. */
const REGION_LABELS = [
    RegionPais::CENTROAMERICA => 'Centroamérica',
    RegionPais::NORTEAMERICA  => 'Norteamérica',
    RegionPais::SURAMERICA    => 'Suramérica',
    RegionPais::CARIBE        => 'Caribe',
];

/** Países activos ordenados por `orden`. Se consulta una sola vez por request. */
function paises_activos(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = db()
        ->query('SELECT id, codigo_iso, nombre, region,
                        etiqueta_codigo_nacional, etiqueta_codigo_internacional
                   FROM paises WHERE activo = 1 ORDER BY orden')
        ->fetchAll();
    return $cache;
}

/**
 * Cómo llama cada país a los dos códigos de transporte del piloto. En El Salvador son "SV" y
 * "SVC"; donde no se haya definido, se usa el nombre genérico, que es lo que significan.
 *
 * @return array<int, array{nacional:string, internacional:string}> por id de país
 */
function etiquetas_codigo_piloto(): array
{
    $out = [];
    foreach (paises_activos() as $pais) {
        $out[(int) $pais['id']] = [
            'nacional'      => $pais['etiqueta_codigo_nacional'] ?: 'Código de transporte nacional',
            'internacional' => $pais['etiqueta_codigo_internacional'] ?: 'Código de transporte internacional',
        ];
    }
    return $out;
}

/** IDs válidos de país (para validar contra el catálogo). */
function paises_ids_validos(): array
{
    return array_map(static fn(array $p): int => (int) $p['id'], paises_activos());
}

/** Renderiza un <select> de países con optgroups por región. */
function render_paises_select(string $name, ?int $selectedId = null, bool $required = true, string $placeholder = 'Selecciona un país'): string
{
    $porRegion = [];
    foreach (paises_activos() as $p) {
        $porRegion[$p['region']][] = $p;
    }

    $req = $required ? ' required' : '';
    $html = '<select name="' . e($name) . '"' . $req . '>';
    $html .= '<option value="">' . e($placeholder) . '</option>';

    foreach (REGION_LABELS as $region => $label) {
        if (empty($porRegion[$region])) {
            continue;
        }
        $html .= '<optgroup label="' . e($label) . '">';
        foreach ($porRegion[$region] as $p) {
            $sel = $selectedId !== null && (int) $p['id'] === $selectedId ? ' selected' : '';
            $html .= '<option value="' . (int) $p['id'] . '"' . $sel . '>'
                . e($p['nombre']) . ' (' . e($p['codigo_iso']) . ')</option>';
        }
        $html .= '</optgroup>';
    }

    return $html . '</select>';
}
