# AGENTS.md — Instrucciones para agentes de código

> Este archivo define cómo trabajar en este proyecto. Léelo completo antes de escribir cualquier línea de código.

## 📖 Fuente de verdad

La especificación completa del sistema vive en **`docs/plan-sistema-disponibilidad-flota.md`**. Ese documento manda. Las fases de trabajo y sus criterios de cierre viven en **`docs/fases-implementacion.md`**. En particular:

- **§2** — La disponibilidad es un estado CALCULADO, nunca un campo editable. Es el concepto central de todo el sistema.
- **§5** — Modelo de datos completo (tablas, campos, relaciones). No inventar campos ni renombrar los existentes.
- **§6** — Máquina de estados de movimientos. Respetar las transiciones exactas.
- **§8** — Checklist de 18 reglas de negocio. TODAS se validan en backend.
- **§9** — Principios de usabilidad poka-yoke. Son requisito, no sugerencia.
- **§10** — Stack y lineamientos técnicos.

Si algo no está especificado en el plan, **preguntar antes de asumir**. No agregar funcionalidades no pedidas.

## 🛠 Stack (no negociable)

- **Backend:** PHP 8.x tradicional, SIN frameworks (no Laravel, no Slim, no Symfony). Enrutador propio simple + controladores + servicios + PDO.
- **Base de datos:** MySQL/MariaDB. Acceso SOLO con PDO + prepared statements. Jamás concatenar SQL.
- **Frontend:** HTML semántico + CSS propio (custom properties, grid/flexbox) + JavaScript vanilla ES6+. SIN React, Vue, jQuery, Bootstrap, Tailwind ni plantillas compradas.
- **Sin build steps:** los assets se sirven directos. No webpack, no vite, no npm salvo justificación explícita aprobada.
- Micro-librería puntual solo si un elemento nativo no alcanza, y se pregunta antes de agregarla.

## 📁 Estructura del proyecto

```
public/           → Document root. index.php (front controller) + assets/
  assets/css/     → Un design-system propio (app.css) + estilos por módulo
  assets/js/      → Módulos ES6 vanilla, un archivo por pantalla + utilidades comunes
app/
  controllers/    → Reciben request, validan permisos, delegan a servicios
  services/       → Reglas de negocio (disponibilidad, traslapes, estados)
  models/         → Acceso a datos con PDO (un archivo por tabla)
  helpers/        → Utilidades (fechas/timezone, respuesta JSON, auth)
config/           → Carga de .env, conexión PDO, constantes de enums
database/
  migrations/     → SQL numerado: 001_estaciones.sql, 002_usuarios.sql...
  seeds/          → Datos iniciales (países, catálogos, admin)
docs/             → El plan y documentación
```

## ✅ Convenciones obligatorias

1. **Fechas:** almacenar SIEMPRE en UTC. Convertir a timezone de la estación solo al renderizar (`DateTimeZone` IANA). Nunca `date()` sin timezone explícito.
2. **Seguridad:** toda ruta de escritura valida sesión + rol + estación en backend. Ocultar botones en UI no es seguridad. `password_hash()`/`password_verify()` para contraseñas. Escapar toda salida HTML con `htmlspecialchars()`.
3. **Enums:** definirlos como constantes PHP en un solo archivo (`config/enums.php`) y usarlos siempre por constante, nunca strings mágicos repetidos.
4. **Bitácora:** toda escritura (crear/editar/cambiar estado/cancelar) registra fila en `bitacora` con snapshot antes/después. Sin excepciones.
5. **Validaciones críticas en backend** (aunque la UI también las tenga): no-traslape de movimientos, notas obligatorias si estado_vehiculo != OPERATIVO, no movimientos sobre unidades con en_disponibilidad = false, motivo obligatorio al cancelar.
6. **Idioma:** interfaz, mensajes de error y comentarios de negocio en **español**. Nombres de variables/funciones en inglés o español consistente (elegir uno y mantenerlo).
7. **Respuestas API:** JSON con estructura consistente `{ok: bool, data|error, message}`. Códigos HTTP correctos (401, 403, 409 para conflictos de traslape, 422 validación).
8. **CSS:** todo color/espaciado sale de custom properties definidas en `:root` de app.css. Los 4 colores de estado de disponibilidad son globales y únicos en todo el sistema.
9. **Desplegables buscables (estándar de UI):** TODO `<select>` de formulario se presenta como un combobox con filtro de texto, mediante el componente propio **`public/assets/js/searchable-select.js`** (vanilla, sin librerías). Se carga globalmente desde el layout y realza automáticamente cada `<select>`; para excluir uno, marcarlo con `data-no-search`. El `<select>` nativo permanece como fuente de verdad (envío del form y `.value` siguen funcionando) y el componente sincroniza su vista al recibir el evento `change`, por lo que al poblar un formulario en modo edición hay que despachar `change` en los selects. Si se crean selects dinámicamente, llamar a `enhanceSelects(contenedor)`. No introducir otra librería o patrón de desplegable: este es el único.

## 🚦 Flujo de trabajo por fases

- Trabajar en el orden estricto de **`docs/fases-implementacion.md`** (Fase 0 → 4). No adelantar entregables de fases futuras sin instrucción explícita.
- **Cada fase termina con su Validación Final ejecutada punto por punto.** Al cerrar una fase, entregar el reporte de validación: cada punto con ✅/❌ y su evidencia (comando, request/respuesta o consulta SQL). Un ❌ = la fase sigue abierta.
- Commits pequeños y descriptivos en español: `feat: CRUD de pilotos con alerta de licencia`, `fix: validación de traslape en edición`.
- Si un cambio toca el modelo de datos, crear una migración SQL nueva numerada; nunca editar migraciones ya aplicadas.

## ⚡ Eficiencia (cómo trabajar)

- **No re-explicar lo conocido.** La documentación ya define el qué y el porqué; no repetirla en las respuestas ni en comentarios extensos. Referenciar la sección (`plan §6`) en lugar de parafrasearla.
- **Leer antes de escribir.** Antes de crear cualquier archivo, revisar qué ya existe (helpers, servicios, estilos). No duplicar lógica: si algo se necesita dos veces, va a un helper/servicio compartido.
- **No regenerar archivos completos por un cambio puntual**; editar lo necesario.
- **Sin código especulativo:** nada de campos "por si acaso", abstracciones prematuras ni opciones de configuración no pedidas. El plan define el alcance exacto.
- **Comentarios solo donde aportan:** reglas de negocio no obvias (ej. por qué el override tiene prioridad). Cero comentarios que narran lo evidente.
- **Ir directo:** ante una tarea clara según la documentación, implementarla sin pedir confirmaciones innecesarias. Preguntar únicamente cuando el plan realmente no cubre el caso.
- La medida de eficiencia es simple: **pasar las Validaciones Finales de la fase al primer intento**, con el mínimo de código necesario.

## 🔒 Seguridad e integridad de datos (NUNCA olvidarlo)

Estos dos principios están por encima de la velocidad. Ante cualquier duda entre "más rápido" y "más seguro/íntegro", gana lo segundo.

**Seguridad — en cada endpoint, siempre:**
1. Verificar sesión → 401 si no hay.
2. Verificar rol y estación contra la acción y el recurso → 403 si no corresponde (la autorización vive en backend; la UI solo oculta).
3. Validar y sanear TODA entrada (tipos, enums contra constantes, longitudes, fechas parseables) → 422 con mensaje claro.
4. PDO prepared statements en el 100% de las queries — la concatenación de SQL está prohibida sin excepción.
5. `htmlspecialchars()` en toda salida hacia HTML. `password_hash()`/`password_verify()` para credenciales. Cookies de sesión con `httponly`, `samesite=Lax` y regeneración de ID al hacer login.
6. Los exports e informes filtran por el alcance del rol **en la consulta**, no después.

**Integridad — en cada escritura, siempre:**
1. **Transacciones** para operaciones múltiples: movimiento + bitácora, cambio de estado + override, apartar retorno + movimiento de regreso. Todo o nada.
2. La validación de no-traslape corre **dentro** de la transacción con bloqueo adecuado (`SELECT ... FOR UPDATE`) para resistir requests simultáneas.
3. FKs y restricciones UNIQUE definidas en la base de datos, no solo validadas en PHP — la BD es la última línea de defensa.
4. Nunca borrado físico de unidades, pilotos ni movimientos (soft-delete / estados finales).
5. Toda escritura deja su fila en bitácora con snapshot antes/después, dentro de la misma transacción.
6. Fechas en UTC en la BD sin excepción; la conversión a hora local ocurre solo en la capa de presentación.

## 🚫 Prohibido

- Adoptar un framework "por conveniencia".
- Campos de país como texto libre (siempre FK al catálogo `paises`).
- Editar disponibilidad como campo directo.
- Borrado físico de unidades, pilotos o movimientos.
- Librerías pesadas de frontend.
- Funcionalidades de costos, tarifas o GPS (fuera de alcance, plan §1).