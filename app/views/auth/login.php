<?php /** @var string|null $error Mensaje genérico de credenciales inválidas. */ ?>
<section class="auth">
    <div class="card auth__card">
        <h1 class="auth__title">Disponibilidad de Flota</h1>
        <p class="auth__subtitle">Ingresa con tu cuenta de estación.</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert--error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/login" class="form" novalidate>
            <?= csrf_field() ?>
            <label class="field">
                <span class="field__label">Correo</span>
                <input type="email" name="email" required autocomplete="username" autofocus
                       value="<?= e($email ?? '') ?>">
            </label>
            <label class="field">
                <span class="field__label">Contraseña</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit" class="btn btn--primary btn--block">Ingresar</button>
        </form>
    </div>
</section>
