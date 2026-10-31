# Rendimiento

Mediciones de las Fases 5 y 6 con **100.000 incidentes**. Se sembraron 5.000 con el seeder por defecto y el resto con `php artisan incidents:seed-bulk --count=95000`, que tardó 11,4 s. De ellos, 59.989 están activos y 11.926 vencidos (19,9 % de los activos).

**Entorno:** Docker Desktop en Windows 11, MySQL 8.4 y PHP 8.4-FPM con opcache, sin caché de aplicación. Son tiempos locales e indicativos, no un benchmark.

## Resumen

| Consulta | `EXPLAIN` (página / conteo) | En PHP | HTTP de punta a punta |
|---|---|---|---|
| Listado sin filtros | `index` / `index` cubriente | 11,5 ms | 78 ms |
| Estado + prioridad | `index` / `range` cubriente | 11,0 ms | 47 ms |
| Búsqueda por texto | `fulltext` / `fulltext` | 39,0 ms | 75 ms |
| `assigned_to=me` | `index` / `ref` | 27,3 ms | 81 ms |
| Conteo de vencidos | `range` cubriente | 5,1 ms | 81 ms (listado `filter[overdue]=true`) |
| Dashboard, admin (5 consultas) | `index`/`range`, todas cubrientes salvo "sin asignar" | 110,5 ms | 140 ms |
| Dashboard, solicitante | — | 71,4 ms | — |
| Exportación completa CSV (100.000 filas, 18 MB) | mismo plan que el listado | — | 16,8 s |
| Exportación completa XLSX (100.000 filas, 6 MB) | mismo plan que el listado | — | 23,3 s |

✅ Ningún plan usa `type = ALL`. El listado y el dashboard responden por debajo de 300 ms, así que el dashboard no necesita caché (D-12).

- **En PHP:** `paginate()` o `DashboardStatsQuery::handle()` medidos dentro del contenedor `app`; mediana de 5 corridas.
- **HTTP de punta a punta:** `curl` a través del gateway con token Bearer; mediana de 5. Incluye el arranque de Laravel, la autenticación y la serialización.
- **Memoria de la exportación:** durante la exportación de 100.000 filas el contenedor `app` se mantuvo en 46 MB.

## Ajustes de índices hechos al medir

### D-38: `idx_incidents_created_at` pasa a `(created_at, deleted_at)`

Con el índice del plan, `(created_at)`, el conteo del listado sin filtros (`WHERE deleted_at IS NULL`) daba `type = ALL`, porque ningún índice incluía `deleted_at`.

| Índice | Conteo sin filtros | Efecto secundario |
|---|---|---|
| `(created_at)` (plan) | `ALL`, 98.393 filas | — |
| `(deleted_at, created_at)` | `ref` cubriente, 12,6 ms | ❌ El optimizador también lo prefiere para los conteos filtrados, porque estima 49.400 filas para `IS NULL`. Estado + prioridad sube a 200 ms y vencidos a 176 ms. |
| `(created_at, deleted_at)` | `index` cubriente, 10,7 ms | ✅ Ninguno. `ORDER BY created_at DESC, id DESC` sigue resolviéndose con el índice, porque MySQL omite la parte fijada por igualdad. Como `deleted_at` no es prefijo, no compite con los otros índices. |

### D-42: sufijo `deleted_at` en los índices de estado y prioridad

En la primera medición, el dashboard del admin tardaba **356 ms**. Los dos `GROUP BY` recorrían el índice completo y luego leían cada fila solo para comprobar `deleted_at` (154 ms y 142 ms). En lugar de cachear, se agregó `deleted_at` al final de los dos índices:

| Índice | Antes | Después |
|---|---|---|
| `idx_incidents_status_due` | `(status, due_at)` | `(status, due_at, deleted_at)` |
| `idx_incidents_priority_status` | `(priority, status)` | `(priority, status, due_at, deleted_at)` |

Con eso las agrupaciones quedan cubiertas por el índice (`Using index`):

| Medición | Antes | Después |
|---|---|---|
| Dashboard | 356 ms | 98–110 ms |
| Conteo de estado + prioridad | 32 ms | 11 ms |
| Conteo de vencidos | 24 ms | 5 ms |

## Cómo reproducir

```bash
docker compose exec app php artisan incidents:seed-bulk --count=95000
docker compose exec mysql mysql -uincidents -psecret incidents -e "ANALYZE TABLE incidents"
```

Después se corre `EXPLAIN` sobre el SQL que generan `ListIncidentsQuery::builder()` y `DashboardStatsQuery::handle()`. Los planes están abajo.

## Listado: detalle por consulta

### Listado sin filtros

```sql
select `id`, `type`, `title`, `priority`, `status`, `created_by`, `assigned_to`, `due_at`, `created_at`, `updated_at` from `incidents` where `incidents`.`deleted_at` is null order by `created_at` desc, `id` desc limit 15
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | index | idx_incidents_created_at | 15 | Using where; Backward index scan |

Conteo para la paginación:

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | index | idx_incidents_created_at | 98475 | Using where; Using index |

Resultados: 100,000. Tiempo de `paginate()` (conteo + página + eager loading), mediana de 5: **11.5 ms**.

### Estado (open,in_progress) + prioridad (high,critical)

```sql
select `id`, `type`, `title`, `priority`, `status`, `created_by`, `assigned_to`, `due_at`, `created_at`, `updated_at` from `incidents` where `status` in ('open', 'in_progress') and `priority` in (3, 4) and `incidents`.`deleted_at` is null order by `created_at` desc, `id` desc limit 15
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | index | idx_incidents_created_at | 77 | Using where; Backward index scan |

Conteo para la paginación:

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | range | idx_incidents_priority_status | 19030 | Using where; Using index |

Resultados: 15,095. Tiempo de `paginate()` (conteo + página + eager loading), mediana de 5: **11.0 ms**.

### Búsqueda por texto "impresora"

```sql
select `id`, `type`, `title`, `priority`, `status`, `created_by`, `assigned_to`, `due_at`, `created_at`, `updated_at` from `incidents` where match (`title`, `description`) against ('+impresora*' in boolean mode) and `incidents`.`deleted_at` is null order by `created_at` desc, `id` desc limit 15
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | fulltext | ft_incidents_search | 1 | Using where; Ft_hints: no_ranking; Using filesort |

Conteo para la paginación:

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | fulltext | ft_incidents_search | 1 | Using where; Ft_hints: no_ranking |

Resultados: 5,325. Tiempo de `paginate()` (conteo + página + eager loading), mediana de 5: **39.0 ms**.

### assigned_to=me (agente)

```sql
select `id`, `type`, `title`, `priority`, `status`, `created_by`, `assigned_to`, `due_at`, `created_at`, `updated_at` from `incidents` where `assigned_to` = 2 and `incidents`.`deleted_at` is null order by `created_at` desc, `id` desc limit 15
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | index | idx_incidents_created_at | 51 | Using where; Backward index scan |

Conteo para la paginación:

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | ref | idx_incidents_assignee_status | 28818 | Using where |

Resultados: 14,200. Tiempo de `paginate()` (conteo + página + eager loading), mediana de 5: **27.3 ms**.

### Conteo de vencidos

```sql
select count(*) as aggregate from `incidents` where `status` in ('open', 'in_progress', 'on_hold') and `due_at` < '2026-10-31 23:43:50' and `incidents`.`deleted_at` is null
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | range | idx_incidents_status_due | 15852 | Using where; Using index |

Resultado: 11,926. Tiempo, mediana de 5: **5.1 ms**.

## Dashboard: las cinco consultas (admin, sin filtros)

```sql
select status, count(*) as total from `incidents` where `incidents`.`deleted_at` is null group by `status`
```

| type | key | rows | Extra | tiempo |
|---|---|---|---|---|
| index | idx_incidents_status_due | 98475 | Using where; Using index | 18.8 ms |

```sql
select priority, count(*) as total, sum(case when status in ('open','in_progress','on_hold') and due_at < '2026-10-31 23:43:51' then 1 else 0 end) as overdue from `incidents` where `incidents`.`deleted_at` is null group by `priority`
```

| type | key | rows | Extra | tiempo |
|---|---|---|---|---|
| index | idx_incidents_priority_status | 98475 | Using where; Using index | 28.3 ms |

```sql
select count(*) as `aggregate` from `incidents` where `incidents`.`deleted_at` is null and `assigned_to` is null and `status` in ('open', 'in_progress', 'on_hold')
```

| type | key | rows | Extra | tiempo |
|---|---|---|---|---|
| range | idx_incidents_assignee_status | 22904 | Using index condition; Using where | 23.1 ms |

```sql
select DATE(CONVERT_TZ(created_at, '+00:00', '-05:00')) as day, count(*) as total from `incidents` where `incidents`.`deleted_at` is null and `created_at` >= '2026-10-02 05:00:00' group by `day`
```

| type | key | rows | Extra | tiempo |
|---|---|---|---|---|
| range | idx_incidents_created_at | 49237 | Using where; Using index; Using temporary | 28.8 ms |

```sql
select DATE(CONVERT_TZ(resolved_at, '+00:00', '-05:00')) as day, count(*) as total from `incidents` where `incidents`.`deleted_at` is null and `resolved_at` >= '2026-10-02 05:00:00' group by `day`
```

| type | key | rows | Extra | tiempo |
|---|---|---|---|---|
| range | idx_incidents_resolved_at | 3163 | Using index condition; Using where; Using temporary | 7.3 ms |


Tiempo total de `handle()` para el admin, mediana de 5: **110.5 ms**.
Para el solicitante (filtra por `created_by`): **71.4 ms**.

## Notas

- **Búsqueda:** el `Using filesort` está acotado a las filas que devuelve FULLTEXT (5.325 aquí), no a la tabla. Si un término muy amplio coincidiera con casi todas las filas, el siguiente paso sería ordenar por relevancia o restringir la búsqueda a `title`.
- **`assigned_to=me`:** la página recorre `idx_incidents_created_at` hacia atrás y filtra por responsable; con unas 51 entradas llena 15 resultados. El conteo usa `idx_incidents_assignee_status`.
- **Tendencia del dashboard:** `Using temporary` agrupa como mucho 30 días, sobre filas que ya salen filtradas por un rango del índice.
- **Exportación:** recorre `lazyByIdDesc(1000)`, con una consulta por lote de 1.000 más el eager loading del lote, y escribe con OpenSpout directo a la salida. Ni la memoria ni el tiempo de respuesta crecen con el tamaño. 100.000 filas tardan entre 17 y 23 s, dentro del `fastcgi_read_timeout` de 300 s del gateway. Para volúmenes mucho mayores, el siguiente paso sería un job en cola que deje el archivo para descargar (anexo).
