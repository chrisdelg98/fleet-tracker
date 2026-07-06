<?php
/**
 * Login (plan §10). Diseño split: imagen de marca a un lado, formulario al otro.
 *
 * @var string|null $error Mensaje genérico de credenciales inválidas.
 * @var string|null $email Correo reingresado tras un intento fallido.
 */
?>
<main class="auth-split">
    <aside class="auth-visual">
        <div class="auth-visual__brand">
            <img src="/assets/img/logo-small.png" alt="EFL" class="auth-visual__brand-mark">
            <strong>Disponibilidad de Flota</strong>
            <span>Plataforma operativa regional</span>
        </div>
    </aside>

    <section class="auth-form-side">
        <div class="auth-form-wrap">
            <img src="/assets/img/logo-small.png" alt="EFL" class="auth-form__logo">
            <div class="auth-form__head">
                <p class="auth-form__eyebrow">Acceso seguro</p>
                <h1 class="auth-form__title">Ingresar a la plataforma</h1>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/login" class="form" novalidate>
                <?= csrf_field() ?>
                <label class="field">
                    <span class="field__label">Correo</span>
                    <input type="email" name="email" required autocomplete="username" autofocus
                           value="<?= e($email ?? '') ?>" placeholder="correo@empresa.com">
                </label>
                <label class="field">
                    <span class="field__label">Contraseña</span>
                    <input type="password" name="password" required autocomplete="current-password" placeholder="Ingresa tu contraseña">
                </label>
                <button type="submit" class="btn btn--primary btn--block">Ingresar</button>
            </form>
        </div>
    </section>
</main>
