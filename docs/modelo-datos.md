# Modelo de datos

Plan aprobado para el Paso 2. Código y tablas en inglés; interfaz en español.
Una instalación por cliente: no hay `institution_id`. Las tablas por defecto de Laravel (`sessions`, `cache`, `jobs`, `password_reset_tokens`) se mantienen.

## Instalación

### `settings`
| Columna | Tipo | Notas |
|---|---|---|
| `key` | string | único |
| `value` | json | |

Claves previstas: `institution_name`, `logo_path`, `timezone`, `grades` (3), `groups_per_grade` (4), `duplicate_window_minutes` (5).

### `groups`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `grade` | tinyint | |
| `number` | tinyint | |
| `label` | string, nullable | ej. "Matutino" |

Índice único `(grade, number)`.

## Personas

### `users`
Solo personal. Los alumnos no son usuarios.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `role` | string | `admin` (root/directivo) o `teacher` |
| `first_name` | string | |
| `last_name_paternal` | string | |
| `last_name_maternal` | string, nullable | |
| `email` | string | único; es el usuario para entrar |
| `password` | string | |
| `must_change_password` | bool | `true` tras una contraseña temporal |
| `password_expires_at` | timestamp, nullable | vencimiento de la temporal (24 h) |
| `is_active` | bool | |
| `last_login_at` | timestamp, nullable | |
| `remember_token`, timestamps, `deleted_at` | | baja = soft delete |

Índices: `(role, is_active)`.

### `students`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `first_name` | string | |
| `last_name_paternal` | string | |
| `last_name_maternal` | string, nullable | |
| `enrollment_number` | string | matrícula, única |
| `group_id` | FK `groups`, nullable | `null on delete` |
| timestamps, `deleted_at` | | baja = soft delete, conserva historial |

Sin `is_active`: como los alumnos no inician sesión, basta con la baja (soft delete). La matrícula sigue siendo única aunque el alumno esté de baja; reimportarlo por CSV lo reactiva.

Índices: `group_id`, `(last_name_paternal, last_name_maternal, first_name)`.

## Clases

### `courses`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `teacher_id` | FK `users` | |
| `name` | string | ej. "Biología I" |
| `group_id` | FK `groups`, nullable | informativo / filtro |
| `room` | string, nullable | |
| `roll_call_sort` | string | `seat` (default), `paternal`, `maternal`, `first_name`, `arrival` |
| `is_active` | bool | |
| timestamps | | |

### `course_schedules`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `course_id` | FK `courses` | cascade |
| `weekday` | tinyint | 1 = lunes … 7 = domingo |
| `starts_at`, `ends_at` | time | ventana editable de la lista |

Índice: `(weekday, starts_at)`.

### `course_student`
| Columna | Tipo | Notas |
|---|---|---|
| `course_id` | FK `courses` | cascade |
| `student_id` | FK `students` | cascade |
| `position` | int, nullable | orden por arrastre; `null` = alfabético |

Único `(course_id, student_id)`. Índice `student_id`.
"Volver al alfabético" pone todas las posiciones en `null`. Ordenar en el pase de lista (`roll_call_sort`) solo cambia la vista.

## Credenciales

### `credentials`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `student_id` | FK `students` | |
| `type` | string | `qr` o `nfc` |
| `value` | string | QR: 8 caracteres sin guion; NFC: UID hex en mayúsculas sin separadores |
| `revoked_at` | timestamp, nullable | |
| `revoked_by` | FK `users`, nullable | |
| timestamps | | |
| `active_value` | generada | `value` si no está revocada, si no `null` |
| `active_student` | generada | `student_id` si no está revocada, si no `null` |

Índices únicos: `(type, active_value)` (un código/pegatina activo pertenece a un solo alumno) y `(type, active_student)` (máximo un QR y un NFC activos por alumno).
Regenerar = revocar la actual y crear otra. Todo alumno recibe un QR al crearse; la baja revoca todas sus credenciales y la reactivación emite un QR nuevo. Un valor activo no puede repetirse entre tipos (un UID no puede coincidir con un QR activo). Alfabeto de 32 caracteres sin ambiguos (sin 0/O/1/I), `random_int`.

## Kioscos y entradas

### `kiosks`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `name`, `location` | string | |
| `read_mode` | string | `usb` (lectores USB), `camera` (cámara integrada o webcam) o `both`; se configura en Ajustes › Kioscos |
| `token_hash` | string | sha256, único; el token se muestra una sola vez |
| `last_seen_at`, `revoked_at` | timestamp, nullable | |
| timestamps | | |

### `entries`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `student_id` | FK `students` | |
| `kiosk_id` | FK `kiosks`, nullable | |
| `credential_id` | FK `credentials`, nullable | |
| `method` | string | `nfc`, `qr`, `manual` |
| `scanned_at` | datetime | hora del dispositivo (cola offline) |
| `received_at` | datetime | hora del servidor |
| `client_uuid` | uuid | único; reenvíos de la cola no duplican |
| `created_by` | FK `users`, nullable | entradas manuales: quién la registró |
| `manual_reason` | string, nullable | solo manuales: `forgot` (olvidó credencial), `damaged` (credencial dañada), `other` |
| `manual_note` | string(200), nullable | obligatorio si el motivo es `other` |

Índices: `(student_id, scanned_at)`, `scanned_at`.
Anti-duplicado (5 min, configurable) en kiosco y servidor; la lectura repetida se descarta.

## Pase de lista

### `roll_calls`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `course_id` | FK `courses` | |
| `course_schedule_id` | FK `course_schedules` | |
| `date` | date | |
| `starts_at`, `ends_at` | datetime | copia del horario de ese día |
| `confirmed_all_at` | timestamp, nullable | |
| timestamps | | |

Único `(course_schedule_id, date)`. Índice `(course_id, date)`.

### `attendance_marks`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | | |
| `roll_call_id` | FK `roll_calls` | cascade |
| `student_id` | FK `students` | |
| `entry_id` | FK `entries`, nullable | entrada del día anterior al fin de la clase |
| `status` | string, nullable | `present`, `late`, `absent` |
| `marked_by` | FK `users`, nullable | |
| `marked_at` | timestamp, nullable | |
| timestamps | | |

Único `(roll_call_id, student_id)`. Índices `(student_id, roll_call_id)`, `(status, entry_id)`.

Estado que se muestra:

| `status` | ¿Entrada? | UI |
|---|---|---|
| `null` | sí | Por confirmar |
| `null` | no | Falta |
| `present` | — | Presente |
| `late` | — | Retardo |
| `absent` | sí | No en aula |
| `absent` | no | Falta |

Reportes: `null` con entrada al cerrar la clase cuenta como presente "sin confirmar". El filtro "entró pero no llegó a clase" es `status = absent AND entry_id IS NOT NULL`.
La edición fuera de `starts_at`–`ends_at` se bloquea en el servidor (policy).

## Auditoría

### `audit_logs`
`id`, `user_id` (nullable), `action`, `subject_type` / `subject_id` (nullable), `meta` (json), `created_at`.
Credenciales regeneradas/revocadas, contraseñas temporales, bajas, cambios en listas.

### `student_imports`
`id`, `user_id`, `filename`, `rows_total`, `rows_created`, `rows_updated`, `errors` (json), timestamps.
La importación es todo o nada: si hay errores no se guarda ningún alumno y `errors` lista línea y problema.

## Relaciones
- `User` (teacher) hasMany `Course`.
- `Student` belongsTo `Group`; hasMany `Credential`, `Entry`, `AttendanceMark`; belongsToMany `Course` vía `course_student` (pivot `position`).
- `Course` hasMany `CourseSchedule`, `RollCall`; belongsToMany `Student`.
- `RollCall` hasMany `AttendanceMark`; `AttendanceMark` belongsTo `Entry` (nullable).
- `Kiosk` hasMany `Entry`.
