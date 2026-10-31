# Contrato API y tiempo real

Extraído de `docs/PLAN.md` (secciones 7 y 8). Cualquier cambio aquí va en el mismo commit que los API Resources y `frontend/src/types/api.ts`.

## 7. Contratos API backend ↔ frontend

Toda la comunicación pasa por `/api/v1` en el mismo origen, con un único formato de error; los tipos TypeScript de esta sección se copian tal cual a `frontend/src/types/api.ts` y los API Resources de Laravel deben producir exactamente esas formas.

**Convenciones**

- Headers: `Accept: application/json`, `Content-Type: application/json`, `Authorization: Bearer <token>` y `X-Request-Id` (si el cliente no lo envía, el backend lo genera y lo devuelve en la respuesta).
- JSON en snake\_case; fechas ISO-8601 en UTC (`2026-10-31T20:15:00Z`); enums como códigos de texto.
- Un recurso: `{ "data": {…} }`. Una colección paginada: `{ "data": […], "meta": {…}, "links": {…} }`.
- Límites: login 5 intentos por minuto (email + IP); resto de la API 120 peticiones por minuto por usuario.

**Formato único de error**

```json
{
  "message": "Los datos enviados no son válidos.",
  "code": "VALIDATION_ERROR",
  "errors": { "title": ["El título debe tener al menos 5 caracteres."] },
  "request_id": "9f1c2b7e-4d2a-4a8e-9a51-0c3b2f6d1e77"
}
```

| HTTP | `code` | Cuándo | Qué hace el frontend |
| --- | --- | --- | --- |
| 401 | `UNAUTHENTICATED` | Sin token, token expirado o revocado | El interceptor limpia la sesión y redirige a `/login?redirect=…` |
| 403 | `FORBIDDEN` | Sin permiso para la acción | Notificación "No tienes permiso para esta acción" |
| 404 | `NOT_FOUND` | No existe o está fuera de la visibilidad del usuario | Página o notificación de no encontrado |
| 409 | `INVALID_STATUS_TRANSITION`, `ASSIGNEE_REQUIRED`, `INCIDENT_LOCKED` | Regla de dominio incompatible con el estado actual | Notificación con `message` y recarga del detalle |
| 422 | `VALIDATION_ERROR` | Entrada inválida (incluye credenciales incorrectas en login) | Muestra `errors` en cada campo del formulario |
| 429 | `TOO_MANY_REQUESTS` | Límite de peticiones | Notificación con indicación de reintento |
| 500 | `SERVER_ERROR` | Error inesperado; sin traza en producción | Notificación genérica con `request_id` |

El login responde 422 con el mismo mensaje ("Credenciales inválidas") para usuario inexistente, contraseña errada o usuario inactivo, para no revelar qué cuentas existen.

**Endpoints**

| Método | Ruta | Permiso | Body | Respuesta |
| --- | --- | --- | --- | --- |
| GET | `/health` | Público | — | 200 `{status, db}` |
| POST | `/auth/login` | Público | `{email, password}` | 200 `{data: LoginResponse}` |
| POST | `/auth/logout` | Autenticado | — | 204 (revoca el token actual) |
| GET | `/auth/me` | Autenticado | — | 200 `{data: AuthUser}` |
| GET | `/catalogs` | Autenticado | — | 200 `{data: Catalogs}` |
| GET | `/users/assignable?search=` | `incidents.assign` | — | 200 `{data: UserSummary[]}` (máximo 20) |
| GET | `/incidents` | Autenticado (R-16) | Query (tabla siguiente) | 200 `Paginated<IncidentListItem>` |
| POST | `/incidents` | `incidents.create` | `IncidentCreatePayload` | 201 `{data: IncidentDetail}` + header `Location` |
| GET | `/incidents/{id}` | Autenticado (R-16) | — | 200 `{data: IncidentDetail}` |
| PATCH | `/incidents/{id}` | Policy `update` / `reprioritize` | `IncidentUpdatePayload` | 200 `{data: IncidentDetail}` |
| POST | `/incidents/{id}/transition` | Policy `transition` | `TransitionPayload` | 200 `{data: IncidentDetail}` |
| POST | `/incidents/{id}/assign` | Policy `assign` | `AssignPayload` | 200 `{data: IncidentDetail}` |
| DELETE | `/incidents/{id}` | `incidents.delete` | — | 204 |
| GET | `/incidents/{id}/activities?page=` | Autenticado (R-16) | — | 200 `Paginated<Activity>` (20 por página, más reciente primero) |
| GET | `/incidents/export?format=csv\|xlsx&…filtros` | `incidents.export` | — | 200 archivo en streaming con `Content-Disposition` |
| GET | `/dashboard/stats?date_from=&date_to=&type=` | `dashboard.view` (R-16) | — | 200 `{data: DashboardStats}` |

`PATCH` con `priority` exige el permiso de repriorizar además del de editar. La ruta `/incidents/export` se declara antes de `/incidents/{id}` para que no la capture el parámetro.

**Parámetros del listado** (los mismos aplican a la exportación, sin paginación)

| Parámetro | Ejemplo | Regla |
| --- | --- | --- |
| `page` | `1` | Entero ≥ 1. |
| `per_page` | `15` | 5 a 100; por defecto 15. |
| `sort` | `-priority` | Lista blanca: `id`, `title`, `priority`, `status`, `due_at`, `created_at`, `updated_at`. `-` = descendente. Por defecto `-created_at`; desempate siempre por `-id`. |
| `search` | `impresora` | 2 a 100 caracteres. Un número o `INC-123` busca por id exacto; el resto usa FULLTEXT con los operadores booleanos del usuario escapados. |
| `filter[status]` | `open,in_progress` | Lista separada por comas de estados válidos. |
| `filter[priority]` | `high,critical` | Lista de prioridades válidas. |
| `filter[assigned_to]` | `12` | Id de usuario, `me` o `unassigned`. |
| `filter[type]` | `incident` | `incident` o `request`. |
| `filter[overdue]` | `true` | Solo vencidos (R-13). |
| `filter[date_field]` | `created_at` | `created_at` (defecto) o `due_at`. |
| `filter[date_from]`, `filter[date_to]` | `2026-10-01` | `YYYY-MM-DD`, inclusivos, interpretados en America/Bogota y convertidos a UTC; `date_to` ≥ `date_from`. |

Cualquier parámetro inválido responde 422; nunca se ignora en silencio.

**Indicadores del dashboard**

Todos se calculan sobre `visibleTo($user)` y la población filtrada (`date_from` y `date_to` sobre `created_at`, y `type`), salvo la tendencia, que ignora el rango. Son cinco consultas; el resto se deriva de ellas.

| Indicador | Definición | Cómo se calcula |
| --- | --- | --- |
| Total | Incidentes de la población filtrada. | Suma de `by_status`. |
| Activos | Incidentes en `open`, `in_progress` u `on_hold`. | Suma de esos estados en `by_status`. |
| Vencidos | Activos con `due_at` anterior a ahora (R-13). | Suma de `by_priority[].overdue`. |
| Sin asignar | Activos sin `assigned_to`. | `COUNT` con `assigned_to IS NULL` y estado activo. |
| Por estado | Conteo de cada estado, en el orden del ciclo de vida, con 0 si no hay casos. | `GROUP BY status`. |
| Por prioridad | Conteo por prioridad y, dentro de cada una, cuántos están vencidos. | `GROUP BY priority` con `SUM(CASE WHEN … THEN 1 ELSE 0 END)` para los vencidos. |
| Tendencia | Creados y resueltos por día durante los últimos 30 días, en días de America/Bogota, con 0 en días sin datos. | Dos `GROUP BY DATE(CONVERT_TZ(columna, '+00:00', '-05:00'))`, sobre `created_at` y sobre `resolved_at`. |

Colombia no tiene horario de verano, así que el desfase fijo de −05:00 es exacto y no requiere las tablas de zonas horarias de MySQL. Un caso reabierto pierde su `resolved_at` (sección 4) y deja de contar como resuelto en la tendencia hasta que se resuelva de nuevo.

**Tipos (copiar a `frontend/src/types/api.ts`)**

```ts
export type Priority = 'low' | 'medium' | 'high' | 'critical';
export type IncidentStatus = 'open' | 'in_progress' | 'on_hold' | 'resolved' | 'closed' | 'cancelled';
export type IncidentType = 'incident' | 'request';
export type Role = 'admin' | 'agent' | 'requester';

export interface UserSummary { id: number; name: string }
export interface AuthUser extends UserSummary { email: string; roles: Role[]; permissions: string[] }
export interface LoginResponse { token: string; token_type: 'Bearer'; expires_at: string; user: AuthUser }

export interface IncidentListItem {
  id: number;
  code: string;                 // "INC-000123"
  type: IncidentType;
  title: string;
  priority: Priority;
  status: IncidentStatus;
  is_overdue: boolean;
  due_at: string;
  creator: UserSummary;
  assignee: UserSummary | null;
  created_at: string;
  updated_at: string;
}

export interface IncidentDetail extends IncidentListItem {
  description: string;
  resolved_at: string | null;
  closed_at: string | null;
  allowed_transitions: IncidentStatus[];   // calculado para el usuario actual
  can: { update: boolean; reprioritize: boolean; assign: boolean; delete: boolean };
}

export interface IncidentCreatePayload {
  type?: IncidentType;          // por defecto 'incident'
  title: string;
  description: string;
  priority: Priority;
  due_at?: string;              // si falta, lo calcula DueDatePolicy
  assigned_to?: number | null;  // solo con permiso incidents.assign
}
export type IncidentUpdatePayload = Partial<Pick<IncidentCreatePayload, 'type' | 'title' | 'description' | 'priority' | 'due_at'>>;
export interface TransitionPayload { status: IncidentStatus; note?: string }  // note obligatoria al reabrir
export interface AssignPayload { assigned_to: number | null }

export type ActivityAction = 'created' | 'updated' | 'assigned' | 'unassigned' | 'status_changed' | 'reprioritized' | 'deleted';
export interface Activity {
  id: number;
  action: ActivityAction;
  changes: Record<string, { from: unknown; to: unknown }>;
  note: string | null;
  actor: UserSummary | null;
  created_at: string;
}

export interface CatalogItem<T extends string> { value: T; label: string }
export interface Catalogs {
  priorities: CatalogItem<Priority>[];
  statuses: (CatalogItem<IncidentStatus> & { is_terminal: boolean })[];
  types: CatalogItem<IncidentType>[];
}

export interface DashboardFilters { date_from?: string; date_to?: string; type?: IncidentType }
export interface DashboardStats {
  total: number;
  active: number;               // open + in_progress + on_hold
  overdue: number;              // activos con due_at vencido
  unassigned: number;           // activos sin responsable
  by_status: { status: IncidentStatus; count: number }[];                 // todos los estados, orden del ciclo de vida, con 0
  by_priority: { priority: Priority; count: number; overdue: number }[];  // todas las prioridades, con 0
  trend: { date: string; created: number; resolved: number }[];           // 30 días (YYYY-MM-DD, America/Bogota), con 0
  generated_at: string;
}

export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; per_page: number; total: number; last_page: number; from: number | null; to: number | null };
  links: { first: string; last: string; prev: string | null; next: string | null };
}

export interface ApiError { message: string; code: string; errors?: Record<string, string[]>; request_id?: string }
```

## 8. Contratos de tiempo real

Laravel emite hacia Reverb, de forma sincrónica y después del commit, eventos con un payload mínimo por dos tipos de canal privado; el frontend nunca pinta datos del evento, solo decide qué volver a consultar.

**Canales**

| Canal | Quién se suscribe (autorizado en `routes/channels.php`) | Eventos |
| --- | --- | --- |
| `private-incidents.staff` | Usuarios con rol `admin` o `agent` | `incident.created`, `incident.updated`, `incident.status_changed`, `incident.assigned`, `incident.deleted` |
| `private-users.{id}` | Solo el usuario `{id}` | `incident.assigned_to_you`; además, los cinco eventos anteriores cuando afectan un caso que ese usuario creó |

**Payload común**

```json
{
  "id": 123,
  "code": "INC-000123",
  "event": "incident.status_changed",
  "changes": ["status"],
  "actor": { "id": 4, "name": "Ana Agente" },
  "occurred_at": "2026-10-31T20:15:00Z"
}
```

**Comportamiento esperado en el frontend**

- **Listado:** ante `created` o `deleted`, o si el `id` está en la página visible, vuelve a consultar con debounce de 1 s y muestra un aviso discreto ("Se actualizó la lista").
- **Detalle:** si el `id` coincide y el actor no es el usuario actual, recarga detalle y línea de tiempo.
- **Dashboard:** recarga las estadísticas con debounce de 2 s.
- **`incident.assigned_to_you`:** notificación con enlace al detalle.

**Conexión**

- Autorización de canales en `POST /api/broadcasting/auth` con el mismo token Bearer (`Broadcast::routes` con middleware `auth:sanctum` y prefijo `api`).
- Echo usa el broadcaster `reverb` contra el mismo origen; el gateway enruta `/app` y `/apps` al contenedor `reverb`.
- Echo se conecta después del login y se desconecta en el logout; un indicador en la cabecera muestra si la conexión está activa.
- Si el WebSocket falla, la aplicación sigue funcionando: los datos se cargan por HTTP y hay un botón "Actualizar".

**Emisión.** Los eventos implementan `ShouldBroadcastNow` y los dispara un listener que corre después del commit. Si Reverb no responde, el listener registra el error en el log y el comando responde normalmente: el tiempo real nunca rompe una operación de negocio.

