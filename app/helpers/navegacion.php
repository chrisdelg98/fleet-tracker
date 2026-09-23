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

    // Timeline es una vista general de solo lectura, como Dashboard y Live: quien consulta
    // también necesita saber dónde está cada unidad. Lo que cambia por rol es el alcance.
    $principal = ['/' => 'Dashboard', '/live' => 'Live', '/timeline' => 'Timeline'];
    // Mantenimientos va en su propio grupo, no dentro de Operación: es un módulo con sus
    // propias secciones adentro, y colgarlo de los datos maestros lo escondía entre ellos.
    // Mantenimientos va al final: se consulta de vez en cuando, no a diario como Operación.
    $grupos = ['Operación' => [], 'Consulta' => [], 'Administración' => [], 'Mantenimientos' => []];

    if ($puedeGestionar) {
        $grupos['Operación']['/flota'] = 'Flota';
        $grupos['Operación']['/pilotos'] = 'Pilotos';
        $grupos['Operación']['/proveedores'] = 'Proveedores';
        $grupos['Operación']['/rutas'] = 'Rutas';
        $grupos['Operación']['/contactos'] = 'Contactos';
        $grupos['Mantenimientos']['/mantenimientos'] = 'Mantenimientos';
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
                $lista[] = ['href' => '/admin/correos', 'label' => 'Correos enviados', 'grupo' => $grupo];
                continue;
            }
            $lista[] = ['href' => $href, 'label' => $label, 'grupo' => $grupo];
        }
    }
    return $lista;
}
