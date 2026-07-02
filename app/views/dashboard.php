<?php /** Landing autenticado de Fase 0. El dashboard "pantalla de aeropuerto" llega en Fase 2. */ ?>
<section class="card">
    <h1>Bienvenido</h1>
    <p>La base del sistema está en marcha. El dashboard de disponibilidad se habilita en la Fase 2.</p>
    <p class="muted">Sesión activa como <strong><?= e($usuario['nombre']) ?></strong> (<?= e($usuario['rol']) ?>).</p>
</section>
