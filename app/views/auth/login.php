<?php
/**
 * @var string|null $error Mensaje genérico de credenciales inválidas.
 * @var string|null $email Correo reingresado tras un intento fallido.
 */
?>
<main class="auth-layout">
    <section class="auth-hero">
        <div class="auth-hero__inner">
            <img src="/assets/img/logo.png" alt="Disponibilidad de Flota" class="auth-hero__logo">
            <p class="auth-hero__eyebrow">Plataforma operativa regional</p>
            <h1 class="auth-hero__title">Disponibilidad de Flota</h1>
            <p class="auth-hero__copy">
                Consulta disponibilidad, programa movimientos, aprovecha retornos y mantén visibilidad
                ejecutiva de la operación desde una sola plataforma.
            </p>

            <div class="auth-hero__highlights">
                <article class="auth-highlight">
                    <strong>Disponibilidad en tiempo real</strong>
                    <span>Visibilidad clara por estación, fecha y estado operativo.</span>
                </article>
                <article class="auth-highlight">
                    <strong>Operación unificada</strong>
                    <span>Movimientos, reservas, inventario e inteligencia en una sola capa.</span>
                </article>
            </div>
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <div class="auth-card__head">
                <p class="auth-card__eyebrow">Acceso seguro</p>
                <h2 class="auth-card__title">Ingresar al sistema</h2>
                <p class="auth-card__subtitle">Usa tu cuenta corporativa para entrar a la operación.</p>
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
                <button type="submit" class="btn btn--primary btn--block">Ingresar al sistema</button>
            </form>
        </div>
    </section>
</main>
