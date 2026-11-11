# Revisión de seguridad

*10 de noviembre de 2026.*

## Alcance y método

`/security-review` analiza los cambios pendientes de una rama; como todo el código ya estaba en `main`, se hizo una **revisión manual completa** de rutas, políticas, componentes Livewire, API del kiosco, configuración y vistas, más:

- `composer audit` y `npm audit --omit=dev`: **0 vulnerabilidades**.
- Pruebas automatizadas nuevas en `tests/Feature/Security/` (autorización por rol en todas las rutas, IDOR, cabeceras, bloqueo de login, límite del kiosco, CSV, bitácora).
- Prueba en Chrome real de que la CSP no rompe Livewire, Alpine, el arrastre de lugares ni el kiosco.

Resultado: **135 pruebas pasando**, 9 hallazgos corregidos, 6 riesgos aceptados y documentados, y 4 pendientes para el despliegue.

## Hallazgos corregidos

| # | Severidad | Hallazgo | Corrección |
|---|---|---|---|
| 1 | Alta | **Inyección de JavaScript por nombres** en confirmaciones `onsubmit="return confirm('… {{ nombre }} …')"` (baja de docente, eliminar grupo, desvincular kiosco). Blade escapa HTML, pero el navegador decodifica el atributo antes de ejecutarlo: un nombre con `'` rompía el script y uno malicioso podía ejecutar código en la sesión de dirección. | Se reemplazaron por `data-confirm="…"` manejado en `app.js` (el nombre es solo texto). Prueba que impide volver a usar manejadores en línea. |
| 2 | Media | **Docentes podían cambiar el grupo de cualquier alumno.** | Regla: el docente ve a todos, pero solo cambia grupo y registra entradas manuales de alumnos inscritos en sus clases (`StudentPolicy::changeGroup` y `registerManualEntry`). |
| 3 | Media | **Sin cabeceras de seguridad ni CSP.** | Middleware `SecurityHeaders`: CSP con nonce por petición, `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (cámara, micrófono, ubicación, pagos, USB apagados), `Cross-Origin-Opener-Policy` y `Strict-Transport-Security` (1 año) en HTTPS. El kiosco y la API tienen una CSP más estricta, sin `unsafe-eval`. |
| 4 | Media | **Cookie de sesión no forzada a HTTPS** y contenido de sesión sin cifrar (puede guardar una contraseña temporal recién generada como aviso de un solo uso). | En `APP_ENV=production`, `secure` y `encrypt` quedan activos aunque falten las variables. `http_only` y `same_site=lax` ya estaban. `.env.production.example` con todo explícito. |
| 5 | Media | **Inyección de fórmulas en CSV exportados** (un nombre importado como `=HYPERLINK(…)` se ejecutaría al abrir el reporte en Excel). | `App\Support\Csv` antepone `'` a celdas que empiezan con `=`, `+`, `-`, `@`, tabulador o retorno. Aplica a todos los reportes. |
| 6 | Baja | **Bloqueo de login débil**: solo por cuenta+IP y de 60 s. | Dos límites: 5 intentos por cuenta+IP y 20 por IP (frena a quien prueba muchas cuentas), con **bloqueo de 15 minutos**. Se registran en bitácora los accesos, fallos y bloqueos (`auth.login`, `auth.failed`, `auth.locked`). |
| 7 | Baja | Detrás de un proxy o túnel la app no sabía que la conexión era HTTPS (no enviaba HSTS y generaba enlaces `http://`). | Se confía en cabeceras `X-Forwarded-*` **solo desde proxies locales** (127.0.0.1, ::1). Confiar en cualquiera permitiría falsear la IP y saltarse el límite por IP. |
| 8 | Baja | Entradas manuales de docentes sin restricción de alumno. | Búsqueda limitada a alumnos de sus clases y autorización al elegir y al guardar. |
| 9 | Baja | Páginas de error con `<style>` sin nonce (se verían sin estilo con la CSP). | Vistas de error publicadas en `resources/views/errors` con nonce. |

## Verificado sin cambios

- **Autorización por rol en todas las rutas**: una prueba recorre automáticamente las rutas exclusivas de dirección (más de 25) y comprueba 403 para docentes y redirección al login para invitados; otra falla si aparece una ruta web sin login fuera de las públicas (`/entrar`, `/kiosco`, manifiesto).
- **IDOR**: los ids de los componentes Livewire críticos están bloqueados (`#[Locked]`); clases y pases de lista ajenos dan 403; descargas e impresión de credenciales, kioscos y reportes son solo de dirección. Probado.
- **Alumnos**: no tienen cuenta ni rol (decisión de producto), así que "un alumno solo ve lo suyo" no aplica.
- **Token de kiosco**: aleatorio de 40 caracteres, se guarda solo su SHA-256, se muestra una vez, se puede regenerar o revocar, y la API limita a 240 peticiones por minuto por kiosco. Una sesión de usuario no sirve para la API.
- **Contraseñas**: bcrypt, mínimo 10 caracteres con letras y números; las temporales vencen en 24 h, obligan a cambiarse y cierran la sesión al vencer; las cuentas desactivadas salen en su siguiente petición.
- **CSRF**: activo en todo el grupo `web` (incluye Livewire). La API del kiosco usa token Bearer sin cookies, por lo que no es vulnerable a CSRF.
- **Subida de CSV**: solo `.csv`/`.txt`, máximo 2 MB, se procesa en memoria y nunca se guarda ni se sirve; validación de todo o nada.
- **Inyección SQL y XSS**: consultas con parámetros (incluido el escape de `%`/`_` en búsquedas); salida escapada por Blade; los únicos `{!! !!}` son SVG de QR generados a partir de valores que controla el sistema.
- **Asignación masiva**: todos los modelos con `$fillable`/`$guarded`.
- **Errores**: con `APP_DEBUG=false` la página de error no muestra mensajes, clases ni rutas (probado).
- **`.env` y `storage`** quedan fuera de `public/`; no hay enlace `public/storage`.
- **Bitácora (`audit_logs`)** con autor: altas, bajas y reactivaciones de alumnos y personal; cambios de grupo; contraseñas (temporal y cambio propio); credenciales (regenerar, revocar, vincular y reemplazar NFC); entradas manuales; importaciones CSV; kioscos (alta, nuevo token, desvincular); "confirmar todas" del pase de lista; accesos y fallos de login. Probado en `AuditTrailTest`.

## Riesgos aceptados

| Riesgo | Por qué se acepta | Mitigación |
|---|---|---|
| `'unsafe-eval'` en la CSP del panel | Alpine (que trae Livewire) evalúa sus expresiones. La versión "CSP" de Livewire obligaría a reescribir las expresiones del pase de lista. | No hay HTML generado por usuarios; todo pasa por el escape de Blade. El kiosco va sin `unsafe-eval`. |
| `style-src-attr 'unsafe-inline'` | Algunas vistas usan atributos `style` (márgenes, anchos de barras). | Los estilos en línea no ejecutan código. |
| Padrón (nombres y códigos) guardado en las tabletas | Necesario para identificar alumnos sin conexión. | Tabletas bajo resguardo de la escuela; se borra al desvincular. Mencionado en el aviso de privacidad. |
| Marcas individuales del pase de lista fuera de `audit_logs` | Cada marca guarda quién y cuándo (`marked_by`, `marked_at`), sin duplicarlo en la bitácora. | Se registra "confirmar todas". |
| La bitácora no tiene pantalla de consulta | Los datos están en la tabla `audit_logs`. | Pendiente si dirección lo necesita. |
| Aviso de un solo uso con la contraseña temporal en la sesión | Se muestra una vez al crear la cuenta. | Sesión cifrada en producción y se borra en la siguiente petición. |

## Pendiente para el despliegue (no son fallas del código)

1. En Hostinger, la raíz del subdominio debe apuntar a `public/`. Si no se puede, no desplegar: expondría `.env`.
2. `APP_DEBUG=false`, `APP_ENV=production` y HTTPS activo (ver `.env.production.example`).
3. Respaldos automáticos de la base de datos.
4. Repetir `composer audit` y `npm audit` antes de cada despliegue.
