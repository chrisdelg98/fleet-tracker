<?php
/**
 * Usuario Admin Global inicial (plan §10). Sin estación (estacion_id NULL, permitido para
 * ADMIN_GLOBAL). La contraseña se hashea con password_hash en el seed runner; cámbiala tras
 * el primer login.
 */

declare(strict_types=1);

return [
    'nombre'   => 'Administrador Global',
    'email'    => 'admin@flota.com',
    'password' => 'Admin.123456',
    'rol'      => Rol::ADMIN_GLOBAL,
];
