# SRD · Backend

> **Sistema de Registro Digital (SRD)** · APIs y lógica de negocio de una plataforma multi‑tenant para Juntas de Acción Comunal (JAC). **Rama de integración:** `dev`.

[Infraestructura](https://github.com/jsgh1/SRD-Docker-Infra/tree/dev) · [Frontend](https://github.com/jsgh1/SRD-Frontend/tree/dev) · [Base de datos](https://github.com/jsgh1/SRD-Database/tree/dev) · [Entregables](https://github.com/jsgh1/SRD-Docs/tree/dev)

## 1. Propósito y alcance

Este repositorio contiene **el código PHP/Laravel**, los contratos de API y las librerías transversales. **No contiene las migraciones definitivas**: esas se versionan en [SRD-Database](https://github.com/jsgh1/SRD-Database). Tampoco incluye los clientes React ni el orquestador Docker. Para ejecutar el sistema completo hay que **ensamblar temporalmente los repositorios** como se documenta en SRD-Docker-Infra.

SRD se diseña para atender varias juntas desde la misma plataforma manteniendo un contexto de organización en las solicitudes y verificando permisos del usuario en el servidor.

## 2. Tecnologías

| Componente | Tecnología |
| --- | --- |
| Runtime | PHP 8.4 en las imágenes Docker de desarrollo |
| Framework | Laravel 12 |
| Persistencia | MySQL 8.4, con una base y credenciales por microservicio |
| Pruebas | PHPUnit 11; pruebas de integración y E2E en SRD-Docker-Infra |
| Comunicación | HTTP/JSON mediante Gateway y llamadas internas entre servicios |
| Tiempo real | Laravel Reverb en el servicio Chat |
| Correo | SMTP; Mailpit solo en desarrollo |
| Seguridad | Validación de contexto de organización, roles, sesiones, desafío por correo y protección CSRF |

## 3. Directorios

```text
SRD-Backend/
├── contracts/                 # Esquemas OpenAPI JSON y evento de auditoría
├── packages/php/
│   └── src/                   # Código PHP reutilizable (seguridad, utilidades, pruebas)
├── services/
│   ├── gateway/               # Entrada HTTP; enrutamiento y controles de solicitudes
│   ├── identity/              # Login, sesiones, miembros, invitaciones, roles
│   ├── configuration/         # Juntas, términos, configuración y enlaces
│   ├── records/               # Personas, campos, filtros, planillas, exportación
│   ├── audit/                 # Registro y consulta de eventos de auditoría
│   ├── files/                 # Carpetas, documentos, fotos y control de archivos
│   ├── calendar/              # Eventos, participantes, recordatorios
│   ├── notifications/         # Notificaciones y entregas
│   ├── treasury/              # Libro de movimientos, recibos y exportación
│   ├── inventory/             # Activos, movimientos y exportación
│   └── chat/                  # Conversaciones, mensajes, llamadas y Reverb
└── .github/workflows/         # CI de PHPUnit para los 11 servicios
```

Cada carpeta de `services/` es una **aplicación Laravel propia**, con `app/`, `routes/`, `config/`, `composer.json`, `phpunit.xml` y pruebas. La separación por directorios representa límites de servicio, no una sola instancia compartida de Laravel.

## 4. Arquitectura y patrones presentes

```text
Navegador / app móvil / cliente Electron
                 │ HTTPS / API
                 ▼
              Nginx
                 │
                 ▼
          Gateway Laravel
        (sesión, CSRF, acceso)
                 │
     ┌───────────┼─────────────┐
     ▼           ▼             ▼
  Identity     Records       Files · ...
     │           │             │
     ▼           ▼             ▼
 srd_identity srd_records   srd_files  (MySQL)
```

- **API Gateway:** expone rutas `/api/v1/` para los clientes y distribuye la operación al servicio correspondiente; los puertos internos no se publican como API pública.
- **Servicios por dominio:** autenticación, personas, documentos, tesorería, inventario, etc. son procesos con datos separados.
- **Capas HTTP / aplicación / persistencia:** los controladores reciben y validan las peticiones; clases en `app/Application/` como `PersonService`, `LedgerService`, `InventoryService`, `CalendarService` o `ChatService` realizan operaciones del dominio. No se afirma que todos los servicios implementen una arquitectura hexagonal estricta.
- **Paquete compartido:** `packages/php/src/` centraliza elementos transversales y contratos usados por distintas aplicaciones.
- **Seguridad multi‑tenant:** el contexto de organización se transporta en solicitudes internas verificadas y cada controlador aplica sus controles de autorización. Una base por microservicio **no sustituye** el filtrado por junta dentro de cada servicio.
- **Outbox / tareas programadas:** existen tablas y publicadores de eventos de salida, servicios de entrega y *scheduler workers* para procesamiento diferido. Algunos flujos requieren reintentos e idempotencia.
- **Concurrencia optimista:** operaciones como el registro de personas y documentos usan versiones y respuestas de conflicto para evitar sobrescrituras; las pruebas verifican esos comportamientos.
- **Contratos API:** `contracts/*.openapi.json` documentan las fronteras HTTP entre componentes.

> Los patrones describen las implementaciones comprobables en este árbol. No se deben asumir colas externas, Kubernetes ni un ORM/repositorio independiente donde el código no los declare.

## 5. Relación con los otros cuatro repositorios

| Repositorio | Responsabilidad y punto de integración |
| --- | --- |
| [SRD-Frontend](https://github.com/jsgh1/SRD-Frontend/tree/dev) | React y clientes móvil/escritorio; consume `/api/v1/` |
| [SRD-Database](https://github.com/jsgh1/SRD-Database/tree/dev) | 72 migraciones Laravel `services/<servicio>/database/migrations`; se agregan en el ensamblaje |
| [SRD-Docker-Infra](https://github.com/jsgh1/SRD-Docker-Infra/tree/dev) | Dockerfiles, Compose, Nginx, SMTP de desarrollo, antivirus, integración y scripts |
| [SRD-Docs](https://github.com/jsgh1/SRD-Docs/tree/dev) | Entregables académicos PDF/Word y orientación general del proyecto |

**Importante:** el Dockerfile `docker/php.Dockerfile` de Infra copia `services/<servicio>` y `packages/php` del **mismo directorio de ensamblaje**. No intentes ejecutar `docker compose up` solamente desde este repositorio.

## 6. Puesta en marcha

1. Instala Git, Docker Desktop con Compose y Node.js (para la inicialización). El ensamblaje se documenta en [README de SRD-Docker-Infra](https://github.com/jsgh1/SRD-Docker-Infra/blob/dev/README.md#4-ensamblaje-de-los-cinco-repositorios).
2. Clona los cinco repositorios desde la rama `dev` y reúne temporalmente Infra, Backend, Frontend y las migraciones de Database en un directorio `workspace/`.
3. En un equipo compartido define un **proyecto Compose exclusivo** para SRD; no reutilices un proyecto ni volumen de otra persona.
4. En `workspace/`, ejecuta `./scripts/Up.ps1` en PowerShell; el script inicializa secretos locales, prepara MySQL, aplica migraciones y arranca los servicios.
5. Comprueba `docker compose ps`; interfaz por defecto: `http://127.0.0.1:8080`, Mailpit: `http://127.0.0.1:8025`. Ninguna credencial de ejemplo debe sustituir los secretos generados.

## 7. Pruebas y calidad

Para probar un servicio individual en un árbol **ya ensamblado**, con dependencias de desarrollo instaladas:

```powershell
Set-Location .\services\records
composer install --no-interaction
php vendor/phpunit/phpunit/phpunit
```

Los `phpunit.xml` configuran SQLite en memoria para las suites de funcionalidad. La CI `migration-phpunit.yml` incorpora temporalmente migraciones desde SRD-Database y ejecuta una matriz para los **11 servicios**. En la validación de migración del 23/10/2026 se obtuvieron **305 pruebas y 6.292 aserciones** exitosas ([ejecución](https://github.com/jsgh1/SRD-Backend/actions/runs/39979516811)). Es una evidencia histórica, no una garantía de ejecuciones futuras.

La matriz de PHPUnit instala PCOV y conserva un reporte Clover por servicio como artefacto de cada ejecución. La primera cobertura medida será una línea base; no se fija un mínimo global hasta revisar esa medición y separar las pruebas SQLite de las integraciones MySQL.

En el mismo job de cada servicio, CI compara las rutas y métodos registrados por `php artisan route:list --json` con las operaciones declaradas en su contrato OpenAPI. Esta comprobación cubre correspondencia de rutas; no sustituye la validación de esquemas, permisos ni respuestas.

Para pruebas del sistema real, el repositorio de infraestructura incluye scripts como `Test-Browser.ps1`, `Test-MySqlIsolation.ps1` y verificaciones de módulos. Las pruebas E2E **crean datos sintéticos**: usar exclusivamente entornos de prueba autorizados.

## 8. Seguridad y colaboración

- Nunca versionar `.env`, contraseñas, tokens, bases de datos con información real ni volcados de producción.
- Mantener la separación entre credenciales de MySQL de cada servicio; en staging se comprobaron **11/11 accesos propios** y **110/110 consultas cruzadas denegadas**.
- Validar autorización y `organization_id` en backend incluso si la interfaz oculta un botón.
- Cambios de rutas públicas o campos de datos requieren revisar `contracts/`, frontend y migraciones compatibles.
- Flujo recomendado: rama hija → PR → CI/revisión → `dev` → `qa` → `main`. No desplegar `dev` directamente sobre el VPS productivo.

## 9. Resolución rápida de problemas

| Síntoma | Qué revisar |
| --- | --- |
| Falta una tabla | Que SRD-Database se haya superpuesto **antes** del `docker build`, y que se ejecutara `php artisan migrate --force` |
| Error de autoload `Srd\\...` | Presencia de `packages/php/src/` y `composer install` / `composer dump-autoload` |
| No llega un correo de acceso | Mailpit/SMTP, servicio Identity y `identity-scheduler` |
| Un servicio no responde | `docker compose ps`, healthcheck y `docker compose logs --tail 100 <servicio>` |
| Falla una solicitud interna | Gateway, clave de llamadas internas, red Compose y contexto de organización |

---

**Código fuente:** [jsgh1/SRD-Backend](https://github.com/jsgh1/SRD-Backend) · **Operación:** [SRD-Docker-Infra](https://github.com/jsgh1/SRD-Docker-Infra).
