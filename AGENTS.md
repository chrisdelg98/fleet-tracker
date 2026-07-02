# AGENTS.md — Instrucciones para agentes de código

> Este archivo define cómo trabajar en este proyecto. Léelo completo antes de escribir cualquier línea de código.

## 📖 Fuente de verdad

La especificación completa del sistema vive en **`docs/plan-sistema-disponibilidad-flota.md`**. Ese documento manda. En particular:

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

## 🚦 Flujo de trabajo

- **Implementar SOLO la Fase 1 primero** (plan §11). No adelantar Fase 2/3 sin instrucción explícita.
- Commits pequeños y descriptivos en español: `feat: CRUD de pilotos con alerta de licencia`, `fix: validación de traslape en edición`.
- Antes de dar por terminado un módulo, verificar contra el checklist de reglas (§8) y los principios poka-yoke (§9).
- Si un cambio toca el modelo de datos, crear una migración SQL nueva numerada; nunca editar migraciones ya aplicadas.

## 🚫 Prohibido

- Adoptar un framework "por conveniencia".
- Campos de país como texto libre (siempre FK al catálogo `paises`).
- Editar disponibilidad como campo directo.
- Borrado físico de unidades, pilotos o movimientos.
- Librerías pesadas de frontend.
- Funcionalidades de costos, tarifas o GPS (fuera de alcance, plan §1).
