<?php /** Landing de Administración (plan §9.1). Solo Admin Global. */ ?>
<section class="module">
    <div class="module__head admin-head">
        <div>
            <p class="module__eyebrow">Configuración del sistema</p>
            <h1>Administración</h1>
            <p class="admin-head__copy muted">Gestiona sedes, usuarios y catálogos operativos desde un solo punto de control.</p>
        </div>
    </div>
    <div class="admin-grid">
        <a class="card admin-card" href="/admin/estaciones">
            <span class="admin-card__kicker">Estructura</span>
            <h2>Estaciones</h2>
            <p class="muted">Sedes de la empresa, códigos, país y zona horaria para la operación regional.</p>
            <span class="admin-card__cta">Administrar estaciones</span>
        </a>
        <a class="card admin-card" href="/admin/usuarios">
            <span class="admin-card__kicker">Acceso</span>
            <h2>Usuarios</h2>
            <p class="muted">Cuentas, roles, asignación por estación y control de acceso del personal.</p>
            <span class="admin-card__cta">Gestionar usuarios</span>
        </a>
        <a class="card admin-card" href="/admin/catalogos">
            <span class="admin-card__kicker">Parámetros</span>
            <h2>Catálogos</h2>
            <p class="muted">Países, categorías de vehículo, tipos de equipo, licencias y permisos operativos.</p>
            <span class="admin-card__cta">Revisar catálogos</span>
        </a>
    </div>
</section>
