<?php
/**
 * Definición única de los accesos del sistema y de quién los ve (plan §4). La consumen
 * el menú lateral del layout y el prototipo de lanzador (/lab), para que las reglas de
 * permisos no vivan en dos sitios.
 */

declare(strict_types=1);

/**
 * @return array{principal: array<string,string>, grupos: array<string, array<string,string>>}
 *         Cada entrada es ruta => etiqueta. Los grupos vacíos se devuelven igual; quien
 *         pinta decide si los omite.
 */
function menu_usuario(array $u): array
{
    $puedeGestionar = in_array($u['rol'], [Rol::ADMIN_GLOBAL, Rol::ENCARGADO], true);

    $principal = ['/' => 'Dashboard', '/live' => 'Live'];
    $grupos = ['Operación' => [], 'Consulta' => [], 'Administración' => []];

    if ($puedeGestionar) {
        // Timeline es una vista general, como Dashboard y Live, no un mantenimiento.
        $principal['/timeline'] = 'Timeline';
        $grupos['Operación']['/flota'] = 'Flota';
        $grupos['Operación']['/pilotos'] = 'Pilotos';
        $grupos['Operación']['/rutas'] = 'Rutas';
        $grupos['Operación']['/contactos'] = 'Contactos';
    }
    if ($u['rol'] !== Rol::CONSULTA_BASICO) {
        $grupos['Consulta']['/inventario'] = 'Inventario';
        $grupos['Consulta']['/inteligencia'] = 'Inteligencia';
    }
    if ($puedeGestionar) {
        $grupos['Consulta']['/historico'] = 'Histórico';
    }
    if ($u['rol'] === Rol::ADMIN_GLOBAL) {
        $grupos['Administración']['/admin'] = 'Administración';
    }

    return ['principal' => $principal, 'grupos' => $grupos];
}

/** Lista plana de accesos (ruta, etiqueta, grupo) para buscadores y lanzadores. */
function accesos_usuario(array $u): array
{
    $menu = menu_usuario($u);
    $lista = [];
    foreach ($menu['principal'] as $href => $label) {
        $lista[] = ['href' => $href, 'label' => $label, 'grupo' => 'General'];
    }
    foreach ($menu['grupos'] as $grupo => $items) {
        foreach ($items as $href => $label) {
            // En un lanzador conviene el destino final, no la portada de Administración.
            if ($href === '/admin') {
                $lista[] = ['href' => '/admin/estaciones', 'label' => 'Estaciones', 'grupo' => $grupo];
                $lista[] = ['href' => '/admin/usuarios', 'label' => 'Usuarios', 'grupo' => $grupo];
                $lista[] = ['href' => '/admin/catalogos', 'label' => 'Catálogos', 'grupo' => $grupo];
                continue;
            }
            $lista[] = ['href' => $href, 'label' => $label, 'grupo' => $grupo];
        }
    }
    return $lista;
}
