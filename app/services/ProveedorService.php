<?php
/**
 * Reglas del catálogo de proveedores y sus camiones.
 *
 * Lo central es que un proveedor no se duplique por cómo se escribe. Se compara siempre por la
 * clave de NombreCatalogo, y todo camino que crea proveedores —la pantalla, el Excel y la
 * reserva— pasa por aquí, así que no hay una puerta por donde se cuele «Transportes abc».
 */

declare(strict_types=1);

final class ProveedorService
{
    public function __construct(
        private PDO $pdo,
        private ProveedorModel $proveedores,
        private ProveedorCamionModel $camiones
    ) {
    }

    // ── Lectura ──

    /**
     * Proveedores con sus camiones, para la pantalla.
     *
     * Antes de listar se enlazan las reservas que aún no tienen proveedor del catálogo: así el
     * catálogo arranca con lo que ya se usó, sin un paso aparte al desplegar.
     */
    public function listar(string $q, string $estado): array
    {
        $this->enlazarPendientes();

        $estado = in_array($estado, ['activos', 'desactivados', 'todos'], true) ? $estado : 'activos';
        $lista = $this->proveedores->listar(trim($q), $estado);
        $camiones = $this->camiones->deProveedores(array_column($lista, 'id'), false);
        foreach ($lista as &$p) {
            $p['camiones'] = $camiones[(int) $p['id']] ?? [];
        }
        return $lista;
    }

    /**
     * ¿Ya existe un proveedor con ese nombre, o alguno que se le parezca?
     *
     * Es lo que el formulario pregunta mientras se escribe, para ofrecer el correcto antes de
     * que se cree una variante.
     *
     * @return array{exacto: ?array, parecidos: list<array{id:int, nombre:string}>}
     */
    public function parecidos(string $nombre): array
    {
        $clave = NombreCatalogo::clave($nombre);
        if ($clave === '') {
            return ['exacto' => null, 'parecidos' => []];
        }

        $exacto = $this->vigente($this->proveedores->porClave($clave));
        $parecidos = [];
        foreach ($this->proveedores->todosConClave() as $p) {
            if ((int) $p['activo'] !== 1 || $p['fusionado_en_id'] !== null) {
                continue;
            }
            if ($exacto !== null && (int) $p['id'] === (int) $exacto['id']) {
                continue;
            }
            if (NombreCatalogo::parecidas($clave, (string) $p['clave'])) {
                $parecidos[] = ['id' => (int) $p['id'], 'nombre' => (string) $p['nombre']];
            }
        }

        return [
            'exacto' => $exacto === null ? null : [
                'id' => (int) $exacto['id'], 'nombre' => (string) $exacto['nombre'], 'activo' => (int) $exacto['activo'],
            ],
            'parecidos' => array_slice($parecidos, 0, 3),
        ];
    }

    /**
     * Lo que el formulario de reserva ofrece al escribir: los proveedores activos y sus
     * camiones activos, con los datos para completar el resto a partir de la placa.
     */
    public function paraFormulario(): array
    {
        // La primera reserva después de desplegar también tiene que ver las placas viejas,
        // aunque nadie haya abierto todavía la pantalla de proveedores.
        $this->enlazarPendientes();
        return [
            'proveedores' => array_column($this->proveedores->activos(), 'nombre'),
            'camiones'    => $this->camiones->paraReservar(),
        ];
    }

    // ── Proveedores ──

    public function crear(array $input, array $user): int
    {
        [$nombre, $clave] = $this->nombreValido($input);
        $existente = $this->proveedores->porClave($clave);
        if ($existente !== null) {
            json_unprocessable(['nombre' => $this->yaExiste($existente)]);
        }

        return tx($this->pdo, function () use ($nombre, $clave, $user): int {
            $id = $this->proveedores->crear($nombre, $clave, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'proveedor', $id, AccionBitacora::CREAR, [
                'despues' => ['nombre' => $nombre],
            ]);
            return $id;
        });
    }

    public function renombrar(int $id, array $input, array $user): void
    {
        $actual = $this->proveedorVigente($id);
        [$nombre, $clave] = $this->nombreValido($input);
        $otro = $this->proveedores->porClave($clave);
        if ($otro !== null && (int) $otro['id'] !== $id) {
            json_unprocessable(['nombre' => $this->yaExiste($otro) . ' Si son el mismo, usa «Fusionar con…».']);
        }

        tx($this->pdo, function () use ($id, $actual, $nombre, $clave, $user): void {
            $this->proveedores->renombrar($id, $nombre, $clave);
            registrar_bitacora($this->pdo, $user['id'], 'proveedor', $id, AccionBitacora::EDITAR, [
                'antes' => ['nombre' => $actual['nombre']], 'despues' => ['nombre' => $nombre],
            ]);
        });
    }

    /** Desactivado no se ofrece al reservar; su historial y sus camiones se conservan. */
    public function cambiarActivo(int $id, bool $activo, array $user): void
    {
        $actual = $this->proveedorVigente($id);
        tx($this->pdo, function () use ($id, $actual, $activo, $user): void {
            $this->proveedores->setActivo($id, $activo);
            registrar_bitacora($this->pdo, $user['id'], 'proveedor', $id, AccionBitacora::EDITAR, [
                'antes' => ['activo' => (int) $actual['activo']], 'despues' => ['activo' => $activo ? 1 : 0],
            ]);
        });
    }

    /**
     * Junta un duplicado con el proveedor correcto: camiones y viajes pasan a `destino`, y
     * `origen` queda como alias desactivado para que su forma de escribirse lleve al correcto.
     */
    public function fusionar(int $origenId, int $destinoId, array $user): void
    {
        $origen = $this->proveedorVigente($origenId);
        if ($destinoId === $origenId) {
            json_unprocessable(['destino_id' => 'Elige otro proveedor: no se puede fusionar consigo mismo.']);
        }
        $destino = $this->proveedores->find($destinoId);
        if ($destino === null || $destino['fusionado_en_id'] !== null) {
            json_unprocessable(['destino_id' => 'Ese proveedor no existe.']);
        }

        tx($this->pdo, function () use ($origen, $destino, $user): void {
            $this->proveedores->fusionar((int) $origen['id'], (int) $destino['id'], (string) $destino['nombre']);
            // El que recibe queda activo: es el nombre que sobrevive, aunque estuviera apagado.
            if ((int) $destino['activo'] !== 1) {
                $this->proveedores->setActivo((int) $destino['id'], true);
            }
            registrar_bitacora($this->pdo, $user['id'], 'proveedor', (int) $origen['id'], AccionBitacora::EDITAR, [
                'antes' => ['nombre' => $origen['nombre']],
                'despues' => ['fusionado_en' => $destino['nombre']],
            ]);
        });
    }

    // ── Camiones ──

    /**
     * Valida los datos de un camión sin escribir nada: la misma regla sirve al formulario, al
     * Excel y a la reserva.
     *
     * @return array{data: ?array, errores: array<string, string>}
     */
    public function evaluarCamion(array $input, ?int $exceptId = null): array
    {
        $v = new Validator($input);
        $v->required('placa_motriz', 'La placa del cabezal')
          ->maxLen('placa_motriz', 30, 'La placa del cabezal')
          ->maxLen('placa_arrastre', 30, 'La placa del furgón')
          ->maxLen('piloto', 150, 'El motorista')
          ->maxLen('licencia', 40, 'La licencia')
          ->maxLen('documento', 40, 'El documento')
          ->maxLen('telefonos', 255, 'El teléfono')
          ->maxLen('codigo_nacional', 40, 'El código nacional')
          ->maxLen('codigo_internacional', 40, 'El código internacional');
        $errores = $v->errors();

        $data = $this->datosCamion($input);
        if (!isset($errores['placa_motriz']) && $data['placa_motriz'] !== null) {
            $otro = $this->camiones->porPlaca($data['placa_motriz']);
            if ($otro !== null && (int) $otro['id'] !== $exceptId) {
                $errores['placa_motriz'] = "La placa {$data['placa_motriz']} ya está registrada con «{$otro['proveedor']}».";
            }
        }
        return ['data' => $errores === [] ? $data : null, 'errores' => $errores];
    }

    public function crearCamion(int $proveedorId, array $input, array $user): int
    {
        $this->proveedorVigente($proveedorId);
        $data = $this->camionValido($input, null);

        return tx($this->pdo, function () use ($proveedorId, $data, $user): int {
            $id = $this->camiones->crear($proveedorId, $data, $user['id']);
            registrar_bitacora($this->pdo, $user['id'], 'proveedor_camion', $id, AccionBitacora::CREAR, [
                'despues' => $data + ['proveedor_id' => $proveedorId],
            ]);
            return $id;
        });
    }

    /** Edita el camión; con otro `proveedor_id` lo mueve, que es lo que pasa si cambia de dueño. */
    public function editarCamion(int $id, array $input, array $user): void
    {
        $actual = $this->camion($id);
        $proveedorId = !empty($input['proveedor_id']) ? (int) $input['proveedor_id'] : (int) $actual['proveedor_id'];
        if ($proveedorId !== (int) $actual['proveedor_id']) {
            $this->proveedorVigente($proveedorId);
        }
        $data = $this->camionValido($input, $id);

        tx($this->pdo, function () use ($id, $actual, $proveedorId, $data, $user): void {
            $this->camiones->actualizar($id, $proveedorId, $data);
            registrar_bitacora($this->pdo, $user['id'], 'proveedor_camion', $id, AccionBitacora::EDITAR, [
                'antes' => array_intersect_key($actual, array_flip([...ProveedorCamionModel::CAMPOS, 'proveedor_id'])),
                'despues' => $data + ['proveedor_id' => $proveedorId],
            ]);
        });
    }

    public function cambiarActivoCamion(int $id, bool $activo, array $user): void
    {
        $actual = $this->camion($id);
        tx($this->pdo, function () use ($id, $actual, $activo, $user): void {
            $this->camiones->setActivo($id, $activo);
            registrar_bitacora($this->pdo, $user['id'], 'proveedor_camion', $id, AccionBitacora::EDITAR, [
                'antes' => ['activo' => (int) $actual['activo']], 'despues' => ['activo' => $activo ? 1 : 0],
            ]);
        });
    }

    public function camion(int $id): array
    {
        $camion = $this->camiones->find($id);
        if ($camion === null) {
            json_error('Camión no encontrado', 404);
        }
        return $camion;
    }

    // ── Desde la reserva y el Excel ──

    /**
     * El proveedor (y su camión) de una reserva: lo encuentra por su clave o lo crea.
     *
     * Corre dentro de la transacción de la reserva. Lo que se escribió en la reserva actualiza
     * el camión del catálogo —el motorista de hoy es el que se propondrá mañana—, pero solo con
     * lo que vino lleno: una reserva que no trae licencia no borra la que ya se tenía.
     *
     * Usar un proveedor o un camión desactivado lo reactiva: si se volvió a contratar, se necesita.
     *
     * @param bool $estricto Con false, una placa que ya es de otro proveedor se deja como está en
     *                       vez de cortar: es el modo para enlazar reservas viejas, donde no hay
     *                       nadie a quien preguntar.
     * @param string $origen   De dónde viene el alta, para la bitácora: reserva, carga masiva…
     * @return array{id: int, nombre: string}
     */
    public function paraReserva(array $tercero, ?int $usuarioId, bool $estricto = true, string $origen = 'reserva'): array
    {
        $proveedor = $this->encontrarOCrear((string) ($tercero['proveedor'] ?? ''), $usuarioId, $origen);
        $id = (int) $proveedor['id'];

        $datos = $this->datosCamion($tercero);
        if ($datos['placa_motriz'] === null) {
            return ['id' => $id, 'nombre' => (string) $proveedor['nombre']];
        }

        $camion = $this->camiones->porPlaca($datos['placa_motriz']);
        if ($camion === null) {
            $idCamion = $this->camiones->crear($id, $datos, $usuarioId);
            registrar_bitacora($this->pdo, $usuarioId, 'proveedor_camion', $idCamion, AccionBitacora::CREAR, [
                'despues' => $datos + ['proveedor_id' => $id], 'origen' => $origen,
            ]);
        } elseif ((int) $camion['proveedor_id'] !== $id) {
            if ($estricto) {
                json_unprocessable(['placa_motriz' => "La placa {$datos['placa_motriz']} está registrada con «{$camion['proveedor']}»."
                    . ' Cambia el proveedor, o muévela a este desde Proveedores si cambió de dueño.']);
            }
        } else {
            $nuevos = array_filter($datos, static fn($v): bool => $v !== null);
            $this->camiones->actualizar((int) $camion['id'], $id, $nuevos + $camion);
            if ((int) $camion['activo'] !== 1) {
                $this->camiones->setActivo((int) $camion['id'], true);
            }
        }
        return ['id' => $id, 'nombre' => (string) $proveedor['nombre']];
    }

    /**
     * Enlaza al catálogo las reservas hechas antes de que existiera, de la más vieja a la más
     * nueva: así cada camión termina con los datos de su último viaje.
     */
    public function enlazarPendientes(): int
    {
        $pendientes = $this->proveedores->viajesSinEnlazar();
        if ($pendientes === []) {
            return 0;
        }
        tx($this->pdo, function () use ($pendientes): void {
            foreach ($pendientes as $viaje) {
                if (trim((string) $viaje['proveedor']) === '') {
                    continue;
                }
                $p = $this->paraReserva($viaje, null, false, 'reservas anteriores al catálogo');
                $this->proveedores->enlazarViaje((int) $viaje['movimiento_id'], $p['id'], $p['nombre']);
            }
        });
        return count($pendientes);
    }

    // ── Internos ──

    /**
     * Busca por clave (siguiendo una fusión hasta el que sobrevivió) y, si no hay, lo crea.
     *
     * @return array fila del proveedor vigente
     */
    private function encontrarOCrear(string $nombreEscrito, ?int $usuarioId, string $origen): array
    {
        $nombre = NombreCatalogo::mostrar($nombreEscrito);
        $clave = NombreCatalogo::clave($nombre);
        if ($clave === '') {
            json_unprocessable(['proveedor' => 'Indica de qué proveedor es la unidad.']);
        }

        $existente = $this->vigente($this->proveedores->porClave($clave));
        if ($existente !== null) {
            if ((int) $existente['activo'] !== 1) {
                $this->proveedores->setActivo((int) $existente['id'], true);
                $existente['activo'] = 1;
            }
            return $existente;
        }

        $id = $this->proveedores->crear($nombre, $clave, $usuarioId);
        registrar_bitacora($this->pdo, $usuarioId, 'proveedor', $id, AccionBitacora::CREAR, [
            'despues' => ['nombre' => $nombre], 'origen' => $origen,
        ]);
        return ['id' => $id, 'nombre' => $nombre, 'activo' => 1];
    }

    /** Si el proveedor es un alias fusionado, el que sobrevivió; si no, el mismo. */
    private function vigente(?array $proveedor): ?array
    {
        $vistos = [];
        while ($proveedor !== null && $proveedor['fusionado_en_id'] !== null) {
            $siguiente = (int) $proveedor['fusionado_en_id'];
            if (isset($vistos[$siguiente])) {
                break;   // un ciclo no debería existir; si existe, no se queda dando vueltas
            }
            $vistos[$siguiente] = true;
            $proveedor = $this->proveedores->find($siguiente);
        }
        return $proveedor;
    }

    /** El proveedor para operar sobre él: existe y no es un alias fusionado. */
    private function proveedorVigente(int $id): array
    {
        $p = $this->proveedores->find($id);
        if ($p === null || $p['fusionado_en_id'] !== null) {
            json_error('Proveedor no encontrado', 404);
        }
        return $p;
    }

    /** @return array{0: string, 1: string} [nombre para mostrar, clave] */
    private function nombreValido(array $input): array
    {
        $nombre = NombreCatalogo::mostrar((string) ($input['nombre'] ?? ''));
        if ($nombre === '') {
            json_unprocessable(['nombre' => 'Escribe el nombre del proveedor.']);
        }
        if (mb_strlen($nombre) > 150) {
            json_unprocessable(['nombre' => 'El nombre no puede pasar de 150 caracteres.']);
        }
        return [$nombre, NombreCatalogo::clave($nombre)];
    }

    /** Explica un choque de nombre según en qué estado está el que ya existe. */
    private function yaExiste(array $existente): string
    {
        if ($existente['fusionado_en_id'] !== null) {
            $vigente = $this->vigente($existente);
            return 'Ese nombre ya corresponde a «' . ($vigente['nombre'] ?? $existente['nombre']) . '».';
        }
        if ((int) $existente['activo'] !== 1) {
            return "«{$existente['nombre']}» ya existe y está desactivado: actívalo en vez de crearlo otra vez.";
        }
        return "Ya existe «{$existente['nombre']}».";
    }

    private function camionValido(array $input, ?int $exceptId): array
    {
        ['data' => $data, 'errores' => $errores] = $this->evaluarCamion($input, $exceptId);
        if ($errores !== []) {
            json_unprocessable($errores);
        }
        return $data;
    }

    /** Datos del camión limpios: placas en mayúsculas y sin espacios, vacíos como null. */
    private function datosCamion(array $input): array
    {
        $data = [];
        foreach (ProveedorCamionModel::CAMPOS as $campo) {
            $valor = trim((string) ($input[$campo] ?? ''));
            if ($valor !== '' && in_array($campo, ['placa_motriz', 'placa_arrastre'], true)) {
                $valor = NombreCatalogo::placa($valor);
            } elseif ($valor !== '' && $campo === 'piloto') {
                $valor = mb_strtoupper($valor, 'UTF-8');
            }
            $data[$campo] = $valor === '' ? null : $valor;
        }
        return $data;
    }
}
