# 🚛 Sistema de Disponibilidad y Tracking de Flota

Plataforma web para la **visibilidad en tiempo real de la disponibilidad de flota** entre las estaciones de la empresa en Centroamérica. Permite consultar qué unidades están libres en cualquier país —hoy, mañana o la próxima semana—, gestionar reservas y movimientos, aprovechar **retornos** para que ningún contenedor viaje vacío, y mantener el **inventario vehicular** completo de la organización.

> 💡 **La idea en una frase:** usar la flota de la empresa hermana antes que contratar terceros, y llenar los retornos en vez de regresar vacíos.

---

## ✨ Funcionalidades principales

| Módulo | Descripción |
|--------|-------------|
| 🛫 **Dashboard de disponibilidad** | Tablero estilo "pantalla de aeropuerto": estado de cada unidad con código de color, cuándo se libera, y retornos disponibles. Filtrable por país, fecha (incluso futura), tipo de equipo y estado. |
| 🚚 **Flota** | Registro de unidades por estación: placas, marca/modelo, capacidad, tipo de equipo, permisos especiales, estado del vehículo (operativo / mantenimiento / inoperativo / de baja). |
| 👤 **Pilotos** | Gestión de pilotos con tipo y número de licencia, y alertas de vencimiento. |
| 🗺 **Rutas** | Catálogo de rutas establecidas (origen, destino, distancia, tiempo estimado) reutilizables al programar movimientos, con opción de ruta personalizada. |
| 📅 **Movimientos y reservas** | Programación de viajes con máquina de estados (reservado → programado → en tránsito → completado), validación de traslapes y gestión de retornos. |
| 📦 **Inventario vehicular** | Censo completo de vehículos de la empresa (incluye automóviles, motocicletas, etc. que no participan en disponibilidad), con conteos por categoría y export a Excel/CSV. Acceso por niveles. |
| 📜 **Histórico** | Bitácora completa de toda la actividad: quién hizo qué y cuándo. Base para reportes de utilización, rutas más usadas y retornos aprovechados. |

## 🧠 Concepto central

La disponibilidad **no es un campo que alguien edita**: es un **estado calculado** a partir de los movimientos y bloqueos de cada unidad. Si un viaje termina mañana a las 12:00, el sistema sabe que la unidad está ocupada hoy y libre mañana a las 12:01 — sin que nadie toque un switch. Esto elimina de raíz los datos desactualizados.

## 🔐 Roles

| Rol | Alcance |
|-----|---------|
| **Admin Global** | Gestión total: estaciones, usuarios, catálogos y flota de cualquier país. |
| **Encargado de Estación** | Gestiona la flota, pilotos y movimientos de su estación; consulta la disponibilidad global. |
| **Consulta Básico** | Ve la disponibilidad de todas las estaciones. |
| **Consulta Inventario** | Lo anterior + inventario vehicular de su estación con descarga. |
| **Consulta Regional** | Lo anterior + inventario completo de todas las estaciones. Pensado para directivos. |

## 🛠 Stack tecnológico

- **Backend:** PHP 8.x tradicional (sin frameworks) · PDO con prepared statements
- **Base de datos:** MySQL / MariaDB · fechas en UTC, presentación en hora local de cada estación
- **Frontend:** HTML semántico · CSS moderno propio · JavaScript vanilla (ES6+)
- **Filosofía:** ligero, rápido, sin dependencias pesadas, estética corporativa sobria, usable sin manual (poka-yoke)

## 🚀 Puesta en marcha (desarrollo)

```bash
# 1. Clonar el repositorio
git clone <url-del-repo> && cd flota-disponibilidad

# 2. Configurar el entorno
cp .env.example .env
#    → editar .env con las credenciales de la base de datos

# 3. Crear la base de datos y aplicar migraciones + seeds
#    (scripts SQL numerados en database/migrations y database/seeds)

# 4. Servir en local
php -S localhost:8000 -t public
```

## 📁 Estructura del proyecto

```
public/       → Document root: front controller y assets (css/js/img)
app/          → Controladores, servicios (reglas de negocio), modelos (PDO), helpers
config/       → Configuración, conexión, enums
database/     → Migraciones SQL numeradas y seeds iniciales
docs/         → 📘 Plan de implementación completo (la fuente de verdad)
```

## 📘 Documentación

La especificación funcional completa —modelo de datos, máquina de estados, reglas de negocio y fases— está en [`docs/plan-sistema-disponibilidad-flota.md`](docs/plan-sistema-disponibilidad-flota.md). Las convenciones para desarrollo asistido por agentes están en [`AGENTS.md`](AGENTS.md).

## 🗺 Roadmap

- [x] **Fase 0** — Especificación y diseño del sistema
- [ ] **Fase 1** — Base: autenticación, CRUDs, movimientos y dashboard de disponibilidad
- [ ] **Fase 2** — Retornos, timeline por unidad, inventario vehicular, histórico
- [ ] **Fase 3** — Reportes de utilización y notificaciones

---

*Proyecto interno. Uso corporativo.*
