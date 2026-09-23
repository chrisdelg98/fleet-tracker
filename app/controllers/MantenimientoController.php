<?php
/**
 * Módulo de mantenimientos: un solo acceso en el menú y, dentro, sus secciones.
 *
 * Control es la portada porque es la accionable —qué unidad hay que meter al taller esta
 * semana—; el historial y los costos son consulta, y talleres y configuración, apoyo.
 *
 * Leer puede cualquiera con sesión; escribir, Admin Global y Encargado, cada uno sobre las
 * unidades y talleres de su estación (eso lo impone el servicio, no esta clase).
 */

declare(strict_types=1);

final class MantenimientoController
{
    private const ESCRITURA = [Rol::ADMIN_GLOBAL, Rol::ENCARGADO];

    public function __construct(
        private MantenimientoService $service,
        private MantenimientoModel $mantenimientos,
        private TallerService $tallerService,
        private TallerModel $talleres,
        private CatalogoModel $catalogos,
        private UnidadModel $unidades,
        private CatalogoAdminService $config,
        private LecturaOdometroModel $lecturas
    ) {
    }

    // ── Pantallas ──

    /** GET /mantenimientos — Control: el semáforo. */
    public function control(): void
    {
        $user = require_login_web();
        $filtros = [
            'estacion_id'  => (string) ($_GET['estacion_id'] ?? ''),
            'categoria_id' => (string) ($_GET['categoria_id'] ?? ''),
            'estado'       => (string) ($_GET['estado'] ?? ''),
            'q'            => trim((string) ($_GET['q'] ?? '')),
        ];
        $r = $this->service->control($filtros);

        render('mantenimientos/control', [
            'usuario'    => $user,
            'filas'      => $r['filas'],
            'conteo'     => $r['conteo'],
            'filtros'    => $filtros,
            'estaciones' => $this->catalogos->activos('estaciones', 'codigo'),
            'categorias' => $this->catalogos->activos('categorias_vehiculo', 'orden'),
        ] + $this->datosDelModal($user), 'Mantenimientos · Flete Finder');
    }

    /** GET /mantenimientos/historial — la bitácora de intervenciones. */
    public function historial(): void
    {
        $user = require_login_web();
        $filtros = $this->filtrosHistorial();
        $r = $this->mantenimientos->historial(
            $filtros,
            max(1, (int) ($_GET['pagina'] ?? 1)),
            in_array((int) ($_GET['por_pagina'] ?? 0), [15, 30, 50], true) ? (int) $_GET['por_pagina'] : 15
        );

        render('mantenimientos/historial', [
            'usuario'    => $user,
            'resultado'  => $r,
            'filtros'    => $filtros,
            'estaciones' => $this->catalogos->activos('estaciones', 'codigo'),
        ] + $this->datosDelModal($user), 'Historial de mantenimientos · Flete Finder');
    }

    /** GET /mantenimientos/historial.csv — lo mismo que se ve, para Excel. */
    public function historialCsv(): void
    {
        require_login_web();
        $filas = $this->mantenimientos->historialCompleto($this->filtrosHistorial());

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mantenimientos-' . date('Ymd') . '.csv"');
        $salida = fopen('php://output', 'w');
        fwrite($salida, "\xEF\xBB\xBF");   // BOM: Excel abre el CSV con tildes correctas
        fputcsv($salida, ['Fecha', 'Placa', 'Marca', 'Modelo', 'Estación', 'Tipo', 'Descripción',
                          'Taller', 'Propio', 'Km', 'Costo', 'Moneda', 'Costo USD', 'Factura', 'Observaciones']);
        foreach ($filas as $f) {
            fputcsv($salida, [
                $f['fecha'], $f['placa_unidad'], $f['marca'], $f['modelo'], $f['estacion_codigo'],
                $f['tipo'], $f['descripcion'], $f['taller'], $f['es_propio'] ? 'Sí' : 'No',
                $f['km'], $f['costo'], $f['moneda'], $f['costo_usd'], $f['factura'], $f['observaciones'],
            ]);
        }
        fclose($salida);
    }

    /** GET /mantenimientos/costos — en qué se va el dinero. */
    public function costos(): void
    {
        $user = require_login_web();
        $por = in_array((string) ($_GET['por'] ?? ''), ['unidad', 'marca', 'taller'], true) ? (string) $_GET['por'] : 'unidad';
        $filtros = $this->filtrosHistorial();

        render('mantenimientos/costos', [
            'usuario'    => $user,
            'por'        => $por,
            'filas'      => $this->mantenimientos->costos($por, $filtros),
            'filtros'    => $filtros,
            'estaciones' => $this->catalogos->activos('estaciones', 'codigo'),
        ] + $this->datosDelModal($user), 'Costos de mantenimiento · Flete Finder');
    }

    /** GET /mantenimientos/talleres */
    public function talleresPage(): void
    {
        $user = require_login_web();
        $filtros = [
            'estacion_id' => (string) ($_GET['estacion_id'] ?? ''),
            'q'           => trim((string) ($_GET['q'] ?? '')),
            'estado'      => (string) ($_GET['estado'] ?? 'activos'),
        ];
        render('mantenimientos/talleres', [
            'usuario'    => $user,
            'talleres'   => $this->talleres->listar($filtros),
            'filtros'    => $filtros,
            'estaciones' => $this->catalogos->activos('estaciones', 'codigo'),
        ] + $this->datosDelModal($user), 'Talleres · Flete Finder');
    }

    /** GET /mantenimientos/configuracion — tipos, planes y monedas. */
    /**
     * GET /mantenimientos/configuracion/{seccion}
     *
     * Una pantalla por catálogo. Las tres juntas cabían, pero había que leer tres tablas para
     * encontrar un dato, y la de en medio se llevaba las preguntas de las otras dos.
     */
    public const CONFIG = [
        'planes'  => ['planes_mantenimiento', 'Planes de servicio'],
        'tipos'   => ['tipos_mantenimiento',  'Tipos de mantenimiento'],
        'monedas' => ['monedas',              'Monedas'],
    ];

    public function configuracion(array $params = []): void
    {
        $user = require_login_web();
        $clave = (string) ($params['seccion'] ?? 'planes');
        if (!isset(self::CONFIG[$clave])) {
            header('Location: /mantenimientos/configuracion/planes');
            return;
        }
        [$tabla, $titulo] = self::CONFIG[$clave];

        render('mantenimientos/configuracion', [
            'usuario'     => $user,
            'clave'       => $clave,
            'tabla'       => $tabla,
            'titulo'      => $titulo,
            'spec'        => CatalogoAdminService::spec($tabla),
            'items'       => $this->catalogos->activos($tabla, $tabla === 'monedas' ? 'orden' : 'nombre'),
            'categorias'  => $tabla === 'planes_mantenimiento'
                ? $this->catalogos->activos('categorias_vehiculo', 'orden')
                : [],
            'puedeEditar' => in_array($user['rol'], self::ESCRITURA, true),
        ] + $this->datosDelModal($user), $titulo . ' · Flete Finder');
    }

    /**
     * POST /api/mantenimientos/planes/{id}/categorias
     *
     * Asigna el plan a unas categorías y marca cuáles no llevan plan. Se manda el estado
     * completo, no diferencias: lo que no venga marcado vuelve al plan por defecto.
     */
    public function apiAplicarPlan(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $body = request_body();
        $this->service->aplicarPlanACategorias(
            (int) $p['id'],
            array_map('intval', (array) ($body['categorias'] ?? [])),
            array_map('intval', (array) ($body['sin_plan'] ?? [])),
            $user
        );
        json_ok(null, 'Plan aplicado.');
    }

    /**
     * POST /api/talleres/lote — crea de golpe los talleres que faltaban en un archivo.
     *
     * Evita el círculo de «corrige el Excel, vuelve a subirlo»: el archivo ya dice qué talleres
     * faltan y en qué estación, así que se confirman aquí y la carga sigue.
     */
    public function apiTalleresLote(): void
    {
        $user = require_role_api(self::ESCRITURA);
        $creados = [];
        foreach ((array) (request_body()['talleres'] ?? []) as $t) {
            $nombre = trim((string) ($t['nombre'] ?? ''));
            $estacion = (int) ($t['estacion_id'] ?? 0);
            if ($nombre === '' || $estacion <= 0) {
                continue;
            }
            // encontrarOCrear comprueba el permiso sobre la estación y no duplica por acentos.
            $id = $this->tallerService->encontrarOCrear($nombre, $estacion, $user['id']);
            if ($id !== null) {
                $creados[] = $nombre;
            }
        }
        $n = count($creados);
        json_ok(['creados' => $creados], $n === 1 ? 'Taller creado.' : "{$n} talleres creados.");
    }

    // ── API: intervenciones ──

    public function apiCreate(): void
    {
        $user = require_role_api(self::ESCRITURA);
        $id = $this->service->crear(request_body(), $user);
        json_ok(['id' => $id], 'Mantenimiento registrado.', 201);
    }

    public function apiShow(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        json_ok($this->service->intervencion((int) $p['id'], $user));
    }

    public function apiUpdate(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $this->service->editar((int) $p['id'], request_body(), $user);
        json_ok(null, 'Mantenimiento actualizado.');
    }

    public function apiDelete(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $this->service->eliminar((int) $p['id'], $user);
        json_ok(null, 'Mantenimiento eliminado.');
    }

    // ── API: kilometraje ──

    public function apiLectura(): void
    {
        $user = require_role_api(self::ESCRITURA);
        $id = $this->service->registrarLectura(request_body(), $user);
        json_ok(['id' => $id], 'Kilometraje registrado.', 201);
    }

    /** POST /api/mantenimientos/lecturas — la captura mensual, de varias unidades a la vez. */
    public function apiLecturas(): void
    {
        $user = require_role_api(self::ESCRITURA);
        $body = request_body();
        $r = $this->service->registrarLecturas(
            is_array($body['filas'] ?? null) ? $body['filas'] : [],
            (string) ($body['fecha'] ?? ''),
            $user
        );
        $n = $r['guardadas'];
        json_ok($r, $n === 1 ? '1 kilometraje registrado.' : "{$n} kilometrajes registrados.");
    }

    // ── API: talleres ──

    public function apiTallerCreate(): void
    {
        $user = require_role_api(self::ESCRITURA);
        $id = $this->tallerService->crear(request_body(), $user);
        json_ok(['id' => $id], 'Taller agregado.', 201);
    }

    public function apiTallerShow(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        json_ok($this->tallerService->taller((int) $p['id'], $user));
    }

    public function apiTallerUpdate(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $this->tallerService->editar((int) $p['id'], request_body(), $user);
        json_ok(null, 'Taller actualizado.');
    }

    public function apiTallerActivo(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $activo = !empty(request_body()['activo']);
        $this->tallerService->cambiarActivo((int) $p['id'], $activo, $user);
        json_ok(null, $activo ? 'Taller activado.' : 'Taller desactivado.');
    }

    // ── API: configuración (tipos, planes, monedas) ──

    public function apiConfigCreate(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $tabla = $this->tablaDeModulo($p);
        json_ok(['id' => $this->config->crear($tabla, request_body(), $user)], 'Guardado.', 201);
    }

    public function apiConfigUpdate(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $tabla = $this->tablaDeModulo($p);
        $this->config->actualizar($tabla, (int) $p['id'], request_body(), $user);
        json_ok(null, 'Guardado.');
    }

    public function apiConfigActivo(array $p): void
    {
        $user = require_role_api(self::ESCRITURA);
        $tabla = $this->tablaDeModulo($p);
        $activo = !empty(request_body()['activo']);
        $this->config->cambiarActivo($tabla, (int) $p['id'], $activo, $user);
        json_ok(null, $activo ? 'Activado.' : 'Desactivado.');
    }

    // ── Internos ──

    /**
     * Solo las tablas del módulo entran por aquí: la ruta recibe el nombre, y sin esta lista
     * blanca un catálogo de Administración sería editable por cualquier encargado.
     */
    private function tablaDeModulo(array $p): string
    {
        $tabla = (string) ($p['tabla'] ?? '');
        if (!in_array($tabla, CatalogoAdminService::DE_MODULO, true)) {
            json_error('Catálogo no válido', 404);
        }
        return $tabla;
    }

    private function filtrosHistorial(): array
    {
        return [
            'desde'       => (string) ($_GET['desde'] ?? ''),
            'hasta'       => (string) ($_GET['hasta'] ?? ''),
            'estacion_id' => (string) ($_GET['estacion_id'] ?? ''),
            'unidad_id'   => (string) ($_GET['unidad_id'] ?? ''),
            'tipo_id'     => (string) ($_GET['tipo_id'] ?? ''),
            'taller_id'   => (string) ($_GET['taller_id'] ?? ''),
            'q'           => trim((string) ($_GET['q'] ?? '')),
        ];
    }

    /**
     * Lo que necesita el modal de registro, que vive en las cinco pantallas: sin esto habría que
     * ir a otra sección para anotar un mantenimiento, y entonces no se anota.
     */
    private function datosDelModal(array $user): array
    {
        $estaciones = $this->catalogos->activos('estaciones', 'codigo');
        $escribibles = array_values(array_filter(
            $estaciones,
            static fn(array $e): bool => can_write_station($user, (int) $e['id'])
        ));

        return [
            'unidadesParaModal' => $this->unidades->listar(null, [], true),
            'ultimasLecturas' => $this->lecturas->ultimasPorUnidad(),
            'tiposMantenimiento' => $this->catalogos->activos('tipos_mantenimiento'),
            'monedas' => $this->catalogos->activos('monedas', 'orden'),
            'talleresParaModal' => $this->talleres->activos(array_column($escribibles, 'id')),
            'estacionesEscribibles' => $escribibles,
            'puedeRegistrar' => in_array($user['rol'], self::ESCRITURA, true),
        ];
    }
}
