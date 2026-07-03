<?php
/**
 * Pantalla de reportes y suscripciones de Fase 4. Usa formularios POST tradicionales
 * con CSRF y redirección para mantener la UX coherente con el resto del sistema.
 */

declare(strict_types=1);

final class InteligenciaController
{
    public function __construct(private InteligenciaService $service)
    {
    }

    public function index(): void
    {
        $user = require_login_web();
        if (!InteligenciaService::tieneAcceso($user)) {
            http_response_code(403);
            echo 'No tienes acceso a inteligencia.';
            return;
        }

        $flash = $_SESSION['flash_inteligencia'] ?? null;
        unset($_SESSION['flash_inteligencia']);

        $filtros = $this->service->filtros($_GET, $user);
        render('inteligencia/index', [
            'usuario' => $user,
            'flash' => $flash,
            'filtros' => $filtros,
            'reportes' => $this->service->reportes($user, $filtros),
            'suscripciones' => $this->service->listarSuscripciones($user),
            'estaciones' => $this->service->opcionesEstaciones($user),
            'paises' => $this->service->opcionesPaises($user),
            'alcanceTotal' => $this->service->alcance($user) === null,
            'tiposSuscripcion' => SuscripcionCorreoModel::tipos(),
        ], 'Inteligencia · Disponibilidad de Flota');
    }

    public function crearSuscripcion(): void
    {
        $user = require_login_web();
        if (!InteligenciaService::tieneAcceso($user)) {
            http_response_code(403);
            echo 'No tienes acceso a inteligencia.';
            return;
        }
        if (!csrf_valid($_POST['_csrf'] ?? null)) {
            $this->redirect('error', 'La sesión del formulario expiró. Intenta de nuevo.');
        }

        try {
            $this->service->crearSuscripcion($_POST, $user);
            $this->redirect('ok', 'Suscripción creada.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }

    public function eliminarSuscripcion(array $p): void
    {
        $user = require_login_web();
        if (!InteligenciaService::tieneAcceso($user)) {
            http_response_code(403);
            echo 'No tienes acceso a inteligencia.';
            return;
        }
        if (!csrf_valid($_POST['_csrf'] ?? null)) {
            $this->redirect('error', 'La sesión del formulario expiró. Intenta de nuevo.');
        }

        try {
            $this->service->eliminarSuscripcion((int) $p['id'], $user);
            $this->redirect('ok', 'Suscripción eliminada.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }

    public function probarSuscripcion(array $p): void
    {
        $user = require_login_web();
        if (!InteligenciaService::tieneAcceso($user)) {
            http_response_code(403);
            echo 'No tienes acceso a inteligencia.';
            return;
        }
        if (!csrf_valid($_POST['_csrf'] ?? null)) {
            $this->redirect('error', 'La sesión del formulario expiró. Intenta de nuevo.');
        }

        try {
            $this->service->enviarPrueba((int) $p['id'], $user);
            $this->redirect('ok', 'Correo de prueba enviado a ' . $user['email'] . '.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }

    private function redirect(string $type, string $message): void
    {
        $_SESSION['flash_inteligencia'] = ['type' => $type, 'message' => $message];
        header('Location: /inteligencia');
        exit;
    }
}