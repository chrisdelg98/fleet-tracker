<?php
/**
 * Enrutador propio simple (AGENTS.md §Stack): mapea método + ruta exacta a un handler.
 * Suficiente para Fase 0; los parámetros de ruta se agregarán cuando los CRUD los pidan.
 */

declare(strict_types=1);

final class Router
{
    /** @var array<string, callable> claves "METODO ruta" */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET ' . $path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST ' . $path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $handler = $this->routes[$method . ' ' . $path] ?? null;
        if ($handler === null) {
            $this->notFound($path);
            return;
        }

        $handler();
    }

    /** 404 en JSON para el API, texto plano para el resto. */
    private function notFound(string $path): void
    {
        if (str_starts_with($path, '/api/')) {
            json_error('Recurso no encontrado', 404);
        }
        http_response_code(404);
        echo 'Página no encontrada.';
    }
}
