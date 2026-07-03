<?php
/**
 * Dashboard de disponibilidad (plan §7.1) y su endpoint (§10). El endpoint implementa el
 * cálculo §2 para el rango consultado; el dashboard vive de él. Disponibilidad visible para
 * todos los roles (sin restricción, plan §4).
 */

declare(strict_types=1);

final class DisponibilidadController
{
    public function __construct(
        private DisponibilidadService $service,
        private CatalogoModel $catalogos,
        private UnidadModel $unidades,
        private RutaModel $rutas,
        private PilotoModel $pilotos
    ) {
    }

    /** GET / — dashboard "pantalla de aeropuerto". */
    public function dashboard(): void
    {
        $user = require_login_web();
        // Datos para el formulario de reserva: se opera solo sobre la propia estación
        // (el Admin Global sobre cualquiera).
        $estacionGestion = $user['rol'] === Rol::ADMIN_GLOBAL ? null : (int) $user['estacion_id'];
        $reservables = array_values(array_filter(
            $this->unidades->listar($estacionGestion),
            static fn(array $u): bool => (int) $u['en_disponibilidad'] === 1
                && $u['estado_vehiculo'] !== EstadoVehiculo::DE_BAJA
        ));

        render('dashboard', [
            'usuario'     => $user,
            'puedeReservar' => in_array($user['rol'], [Rol::ADMIN_GLOBAL, Rol::ENCARGADO], true),
            'estaciones'  => $this->catalogos->activos('estaciones', 'codigo'),
            'tiposEquipo' => $this->catalogos->activos('tipos_equipo', 'orden'),
            'reservables' => $reservables,
            'rutas'       => $this->rutas->listar(),
            'pilotos'     => $this->pilotos->listar($estacionGestion),
            'fechaHoy'    => (new DateTimeImmutable('now'))->format('Y-m-d'),
        ], 'Dashboard · Disponibilidad de Flota');
    }

    /** GET /api/disponibilidad — flota con su estado calculado para el rango consultado. */
    public function apiDisponibilidad(): void
    {
        require_login_api();
        [$desde, $hasta] = $this->rangoDeConsulta($_GET);
        json_ok([
            'desde'    => $desde,
            'hasta'    => $hasta,
            'unidades' => $this->service->calcular($desde, $hasta, $this->filtrosDeQuery($_GET)),
        ]);
    }

    /**
     * Resuelve el rango UTC a consultar. Acepta `desde`/`hasta` (datetime) explícitos o
     * `fecha` (Y-m-d) que se expande al día completo. Default: hoy.
     */
    private function rangoDeConsulta(array $q): array
    {
        if (!empty($q['desde']) && !empty($q['hasta'])) {
            return [$this->utc($q['desde'], '00:00:00'), $this->utc($q['hasta'], '23:59:59')];
        }
        $fecha = !empty($q['fecha']) ? (string) $q['fecha'] : (new DateTimeImmutable('now'))->format('Y-m-d');
        $dia = substr($fecha, 0, 10);
        return [$dia . ' 00:00:00', $dia . ' 23:59:59'];
    }

    private function utc(string $valor, string $horaPorDefecto): string
    {
        $v = trim($valor);
        // Si viene solo fecha, completa la hora; normaliza el separador 'T'.
        $v = str_replace('T', ' ', $v);
        if (strlen($v) <= 10) {
            $v .= ' ' . $horaPorDefecto;
        }
        return $v;
    }

    private function filtrosDeQuery(array $q): array
    {
        $estados = [];
        if (!empty($q['estado'])) {
            $estados = array_values(array_filter(array_map('trim', explode(',', (string) $q['estado']))));
        }
        return [
            'estacion_id'    => $q['estacion_id'] ?? null,
            'tipo_equipo_id' => $q['tipo_equipo_id'] ?? null,
            'placa'          => $q['placa'] ?? null,
            'estados'        => $estados,
            'solo_retorno'   => !empty($q['solo_retorno']),
            'sin_retorno'    => !empty($q['sin_retorno']),
            'retorno_hacia'  => $q['retorno_hacia'] ?? null,
        ];
    }
}
