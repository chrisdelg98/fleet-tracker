<?php
/**
 * Cambio de la propia contraseña.
 *
 * Los requisitos se pintan desde password_reglas() —la misma lista que valida el servidor— y el
 * JS va marcando cuáles se cumplen mientras se escribe: así nadie descubre al guardar que le
 * faltaba una mayúscula.
 *
 * @var array                                                        $usuario
 * @var array<string, string>                                        $errores  campo => problema
 * @var bool                                                         $cambiada
 * @var list<array{clave: string, texto: string, obligatorio: bool}> $reglas
 */
set_page_meta('Cambiar contraseña', 'Actualiza la contraseña con la que entras a la plataforma.');

/** Ícono de un botón dentro del campo (generar / mostrar), como en la pantalla de usuarios. */
$iconoOjo = '<svg class="field__btn-icon field__btn-icon--show" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false"><path d="M10 4c4.1 0 7.3 2.9 8.4 5.4a1.5 1.5 0 0 1 0 1.2C17.3 13.1 14.1 16 10 16s-7.3-2.9-8.4-5.4a1.5 1.5 0 0 1 0-1.2C2.7 6.9 5.9 4 10 4Zm0 1.8c-3.1 0-5.7 2.2-6.7 4.2 1 2 3.6 4.2 6.7 4.2s5.7-2.2 6.7-4.2c-1-2-3.6-4.2-6.7-4.2Zm0 1.4a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z" fill="currentColor"/></svg>'
    . '<svg class="field__btn-icon field__btn-icon--hide" viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false"><path d="M3.3 2.3a.9.9 0 0 0-1.3 1.3l2.2 2.2C2.9 6.8 1.9 8.2 1.4 9.4a1.5 1.5 0 0 0 0 1.2C2.6 13.1 5.8 16 9.9 16c1.5 0 2.9-.4 4.1-1l2.4 2.4a.9.9 0 1 0 1.3-1.3L3.3 2.3Zm5.3 7.9 1.9 1.9a2.1 2.1 0 0 1-1.9-1.9Zm-1.5-1.5.2-.4A2.8 2.8 0 0 1 12.4 12l-.4.2-1.5-1.5a2.1 2.1 0 0 0-2.9-2.9L7.1 8.7ZM9.9 5.8c3.1 0 5.7 2.2 6.7 4.2-.3.6-.9 1.4-1.7 2.1l1.3 1.3c1-1 1.8-2 2.2-2.8a1.5 1.5 0 0 0 0-1.2C17.2 6.9 14 4 9.9 4c-.9 0-1.7.1-2.5.4l1.4 1.5c.3 0 .7-.1 1.1-.1Z" fill="currentColor"/></svg>';
$iconoGenerar = '<svg viewBox="0 0 20 20" width="17" height="17" aria-hidden="true" focusable="false"><path d="M11 2.2l1 2.8 2.8 1-2.8 1-1 2.8-1-2.8-2.8-1 2.8-1 1-2.8Zm5.2 6.6.6 1.7 1.7.6-1.7.6-.6 1.7-.6-1.7-1.7-.6 1.7-.6.6-1.7ZM6.2 10.6l.9 2.3 2.3.9-2.3.9-.9 2.3-.9-2.3-2.3-.9 2.3-.9.9-2.3Z" fill="currentColor"/></svg>';

/** Campo de contraseña con sus botones y, si lo hay, el problema debajo. */
$campo = static function (string $name, string $label, string $autocomplete, array $errores, bool $conGenerador = false) use ($iconoOjo, $iconoGenerar): string {
    $error = $errores[$name] ?? '';
    $generar = $conGenerador
        ? '<button type="button" class="field__btn" data-generar aria-label="Generar contraseña segura" title="Generar contraseña segura">' . $iconoGenerar . '</button>'
        : '';

    return '<label class="field' . ($error !== '' ? ' field--error' : '') . '">'
        . '<span class="field__label">' . e($label) . '</span>'
        . '<span class="field__control">'
        . '<input type="password" name="' . e($name) . '" id="pass-' . e($name) . '" required autocomplete="' . e($autocomplete) . '"'
        . ($error !== '' ? ' aria-invalid="true" aria-describedby="error-' . e($name) . '"' : '') . '>'
        . '<span class="field__btns">' . $generar
        . '<button type="button" class="field__btn" data-ver aria-pressed="false" aria-label="Mostrar contraseña" title="Mostrar contraseña">' . $iconoOjo . '</button>'
        . '</span></span>'
        . ($error !== '' ? '<small class="field__note field__note--error" id="error-' . e($name) . '">' . e($error) . '</small>' : '')
        . '</label>';
};
?>
<section class="module perfil-password">
    <?php if ($cambiada): ?>
        <div class="alert alert--ok" role="status">Contraseña cambiada. Úsala la próxima vez que entres.</div>
    <?php endif; ?>

    <div class="card perfil-password__card">
        <form method="post" action="/perfil/password" class="form" novalidate>
            <?= csrf_field() ?>

            <div class="perfil-password__head">
                <h2>Cambiar contraseña</h2>
                <p class="muted">La cuenta <strong><?= e($usuario['email']) ?></strong>. Pide la actual para que nadie
                   pueda cambiarla desde una sesión que dejaste abierta.</p>
            </div>

            <?= $campo('actual', 'Contraseña actual', 'current-password', $errores) ?>

            <hr class="perfil-password__sep">

            <?= $campo('nueva', 'Contraseña nueva', 'new-password', $errores, true) ?>

            <!-- La lista se marca sola mientras se escribe; sin JS se queda como recordatorio. -->
            <ul class="requisitos" id="requisitos">
                <?php foreach ($reglas as $regla): ?>
                    <li class="requisito" data-regla="<?= e($regla['clave']) ?>"<?= $regla['obligatorio'] ? '' : ' data-sugerido="1"' ?>>
                        <span class="requisito__marca" aria-hidden="true"></span>
                        <span><?= e($regla['texto']) ?><?= $regla['obligatorio'] ? '' : ' <em>(recomendado, no obligatorio)</em>' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?= $campo('confirmacion', 'Repite la contraseña nueva', 'new-password', $errores) ?>
            <small class="field__note" id="coincide" hidden></small>

            <div class="form__acciones">
                <a class="btn btn--ghost-dark" href="/">Cancelar</a>
                <button type="submit" class="btn btn--primary">Guardar contraseña</button>
            </div>
        </form>
    </div>
</section>

<script src="<?= e(asset('/assets/js/perfil-password.js')) ?>" type="module"></script>
