# 🚛 Flete Finder

Plataforma web para la **disponibilidad y el tracking de la flota** entre las estaciones de la empresa en Centroamérica. Muestra qué unidades están libres en cualquier país —hoy, mañana o la próxima semana—, permite reservar y seguir cada movimiento, aprovechar **retornos** para que ningún equipo regrese vacío y registrar también los fletes hechos con **camiones de proveedores**.

> 💡 **La idea en una frase:** usar la flota de la empresa antes que contratar terceros, y llenar los retornos en vez de regresar vacíos.

**Producción:** https://fleet.efltrackingsystem.com

---

## ✨ Funcionalidades principales

### Operación diaria

| Módulo | Descripción |
|--------|-------------|
| 🛫 **Dashboard** | Tablero de disponibilidad: estado de cada unidad con código de color, actividad, cuándo se libera, retorno y piloto. Filtros por fecha (incluso futura), categoría, estación, estado, alcance (INT/NAC), retorno y demora. Desde cada fila se reserva, confirma, marca salida o llegada, edita, cancela, aparta retorno o reenvía el aviso. |
| 📅 **Reservas y movimientos** | Formulario en dos columnas con pestañas **Flota propia / Proveedor**. Estados: reserva → programado → en tránsito → completado (o cancelado). Valida traslapes de unidad, equipo de apoyo y piloto; respeta el alcance nacional/internacional de la unidad; clasifica la operación como **Interno (IN)** o **Externo (EX)**. |
| ↩ **Retornos** | Un viaje con «Retorno disponible» sale en el tablero para que otra estación lo tome. Apartarlo crea el movimiento de regreso enlazado; una vez tomado, la oferta ya no se puede retirar. |
| 🚚 **Proveedores** | Una reserva puede hacerse con un camión contratado: proveedor, placas, motorista, licencia, documento, teléfono y códigos. Al escribir una placa ya usada, el resto se completa con lo de la última vez. |
| 📺 **Live** | Pantalla de oficina (wallboard) que se refresca sola. |
| 🗓 **Timeline** | Vista tipo Gantt de la ocupación de cada unidad. |

### Datos maestros

| Módulo | Descripción |
|--------|-------------|
| 🚚 **Flota** | Unidades por estación: placa, categoría, equipo, capacidad, permisos, alcance INT/NAC y estado del vehículo. Carga masiva con plantilla Excel. |
| 👤 **Pilotos** | Licencia y vencimiento, documento, teléfono y códigos de transporte (con el nombre que usa cada país). Carga masiva con plantilla Excel. |
| 🗺 **Rutas** | Catálogo de rutas con ciudades y tiempo estimado; se puede usar «Otra ruta…» para escribir países y ciudades a mano. |
| ✉️ **Contactos** | Listas de correo reutilizables para los avisos de reserva. |

### Consulta y administración

| Módulo | Descripción |
|--------|-------------|
| 📦 **Inventario** | Censo de todos los vehículos (incluidos los que no participan en disponibilidad), con export a Excel. Acceso por niveles. |
| 📊 **Inteligencia** | Utilización por estación, rutas más usadas y retornos aprovechados. |
| 📜 **Histórico** | Movimientos pasados y bitácora del sistema (quién hizo qué y cuándo), con export CSV. |
| ⚙️ **Administración** | Estaciones, usuarios, catálogos y **Correos enviados** (registro de avisos sin guardar direcciones). |

## 🧠 Concepto central

La disponibilidad **no es un campo que alguien edita**: es un **estado calculado** a partir de los movimientos y bloqueos de cada unidad. Si un viaje termina mañana a las 12:00, el sistema sabe que la unidad está ocupada hoy y libre mañana a las 12:01, sin que nadie toque un switch. Esto elimina de raíz los datos desactualizados.

## ✉️ Notificaciones por correo

| Aviso | Cuándo sale | A quién |
|-------|-------------|---------|
| **Reserva** | Al crear una reserva con correos en «Notificar a» (la lista de contactos rellena ese campo). Se puede reenviar desde la fila. | Cada contacto, con «responder a» a quien reservó, más una copia para quien reservó. Con proveedor lleva sus datos, sin indicar que es contratado. |
| **Retorno disponible** | Al ofrecer retorno en un viaje internacional con unidad propia (al crearlo o al marcarlo después). | Usuarios suscritos al país de origen. |

Si el correo falla, la reserva se guarda igual y la pantalla lo avisa. Cada intento queda en **Administración › Correos enviados**.

## 🔐 Roles

| Rol | Alcance |
|-----|---------|
| **Admin Global** | Gestión total: estaciones, usuarios, catálogos y flota de cualquier país. |
| **Encargado de Estación** | Gestiona flota, pilotos, rutas, contactos y movimientos de su estación; consulta la disponibilidad global. |
| **Consulta Básico** | Ve la disponibilidad de todas las estaciones. |
| **Consulta Inventario** | Lo anterior + inventario e inteligencia. |
| **Consulta Regional** | Lo anterior + inventario completo de todas las estaciones. Pensado para directivos. |

## 🛠 Stack tecnológico

- **Backend:** PHP 8.x sin frameworks · router propio · PDO con prepared statements
- **Base de datos:** MySQL / MariaDB · fechas en UTC, presentación en la hora local de cada estación
- **Frontend:** HTML semántico · CSS propio con tokens de diseño · JavaScript vanilla (módulos ES)
- **Correo:** cliente SMTP propio (cuerpo en base64, Reply-To)
- **Filosofía:** ligero, sin dependencias pesadas, usable sin manual; pocos campos, pocas decisiones

## 🚀 Puesta en marcha (desarrollo)

```bash
# 1. Clonar el repositorio
git clone <url-del-repo> && cd fleet-tracker

# 2. Configurar el entorno
cp .env.example .env
#    → base de datos, APP_URL, APP_TIMEZONE y datos SMTP (MAIL_*)

# 3. Crear la base de datos, aplicar migraciones y datos iniciales
php database/migrate.php      # aplica en orden las pendientes de database/migrations
php database/seed.php         # catálogos, estaciones y Admin Global (re-ejecutable)
php database/seed-demo.php --force   # opcional: datos de ejemplo (solo APP_ENV=local)

# 4. Servir en local
php -S localhost:8000 -t public
```

## 🌐 Despliegue

- Hosting cPanel con el **document root en `public/`**; los archivos se suben por FTP.
- **Primero las migraciones, después los archivos:** si el código nuevo llega antes que la columna que usa, la pantalla falla.
- CSS y JS llevan su fecha de modificación en la URL (`app.css?v=…`), así que subir un archivo basta para que el navegador tome la versión nueva.
- `.env`, `storage/` y `tools/` quedan fuera del acceso web.

### Herramientas de servidor (`tools/`)

```bash
php tools/probar-correo.php                     # diagnostica DNS, puerto, cifrado y autenticación SMTP
php tools/probar-correo.php alguien@dominio     # además envía un correo de prueba
php tools/limpiar-movimientos.php               # muestra qué movimientos de prueba se borrarían
php tools/limpiar-movimientos.php --confirmar   # respalda y borra (no toca flota, pilotos ni catálogos)
```

## 📁 Estructura del proyecto

```
public/       → Document root: front controller (index.php) y assets (css/js/img)
app/          → Controladores, servicios (reglas de negocio), modelos (PDO), helpers y vistas
config/       → Configuración, carga del .env y enums
database/     → Migraciones SQL numeradas, seeds y sus runners
tools/        → Scripts de diagnóstico y mantenimiento para el servidor
docs/         → 📘 Especificación, fases, sistema de diseño y análisis en curso
storage/      → Logs (fuera del repositorio)
```

## 📘 Documentación

- [`docs/plan-sistema-disponibilidad-flota.md`](docs/plan-sistema-disponibilidad-flota.md): especificación funcional (modelo de datos, máquina de estados, reglas de negocio). Es la fuente de verdad.
- [`docs/fases-implementacion.md`](docs/fases-implementacion.md): fases y sus criterios de validación.
- [`docs/design-system.md`](docs/design-system.md): tokens y componentes de la interfaz.
- [`docs/analisis-flota-externa.md`](docs/analisis-flota-externa.md) y [`docs/analisis-mantenimientos.md`](docs/analisis-mantenimientos.md): análisis temporales de trabajo en curso; se eliminan al terminar su implementación.
- [`AGENTS.md`](AGENTS.md): convenciones para desarrollo asistido por agentes.

## 🗺 Roadmap

- [x] **Fase 0** — Cimientos: especificación, autenticación, migraciones
- [x] **Fase 1** — Datos maestros: estaciones, flota, pilotos, rutas, catálogos
- [x] **Fase 2** — Motor de disponibilidad, reservas y dashboard
- [x] **Fase 3** — Retornos, inventario e histórico
- [x] **Fase 4** — Inteligencia y notificaciones por correo
- [x] **Flota externa** — Movimientos con camión de proveedor, operación IN/EX y avisos con sus datos
- [ ] **Catálogo de proveedores** — Gestión, carga con Excel, desactivar y evitar nombres duplicados
- [ ] **Flota externa (siguientes fases)** — Tabla de movimientos con reporte mensual IN/EX, terceros con retorno en el tablero, importación de movimientos
- [ ] **Mantenimientos** — Módulo de mantenimiento de unidades

---

*Proyecto interno. Uso corporativo.*
