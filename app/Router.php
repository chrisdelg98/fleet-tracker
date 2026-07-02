<?php
/**
 * Enrutador propio (AGENTS.md §Stack). Soporta rutas con parámetros nombrados
 * (ej. /api/unidades/{id}) y los métodos GET/POST/PUT/PATCH/DELETE. El handler recibe
 * un arreglo asociativo con los parámetros capturados.
 */

declare(strict_types=1);

final class Router
{
    /** @var array<int, array{method:string, regex:string, params:string[], handler:callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void    { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void   { $this->add('POST', $path, $handler); }
    public function put(string $path, callable $handler): void    { $this->add('PUT', $path, $handler); }
    public function patch(string $path, callable $handler): void  { $this->add('PATCH', $path, $handler); }
    public function delete(string $path, callable $handler): void { $this->add('DELETE', $path, $handler); }

    private function add(string $method, string $path, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $params = array_combine($route['params'], $matches) ?: [];
                ($route['handler'])($params);
                return;
            }
        }

        $this->notFound($path);
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
