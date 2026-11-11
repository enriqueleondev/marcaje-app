# Política de cookies — Marcaje en [NOMBRE DE LA INSTITUCIÓN]

*Última actualización: [FECHA]*

> Plantilla. Revísala si cambias la configuración de sesión o agregas servicios de terceros.

El sistema **no usa cookies de analítica, publicidad ni seguimiento**, ni recursos de terceros: las fuentes y scripts se sirven desde el mismo dominio.

## Cookies técnicas (necesarias)

| Nombre | Para qué | Duración |
|---|---|---|
| `marcaje-session` (o el nombre que defina `SESSION_COOKIE`) | Mantener la sesión iniciada del personal. Solo HTTPS, inaccesible para JavaScript. | Hasta cerrar sesión o al vencer la sesión ([N] minutos de inactividad) |
| `XSRF-TOKEN` | Protección contra falsificación de solicitudes (CSRF). | Igual que la sesión |
| `remember_web_*` | Solo si el usuario marca "Mantener la sesión abierta". | [N] días |

## Almacenamiento local

- **Panel:** `localStorage` guarda únicamente la preferencia de tema claro u oscuro (`marcaje.theme`).
- **Kiosco (tabletas de entrada):** IndexedDB guarda el token del kiosco, el padrón de nombres y códigos para identificar alumnos sin conexión, y la cola de entradas pendientes de enviar. Se borra al desvincular el kiosco o limpiar los datos del navegador de la tableta.

Estas cookies y datos son estrictamente necesarios para que el sistema funcione, por lo que no requieren consentimiento adicional. Puedes borrarlos desde la configuración del navegador; al hacerlo se cerrará la sesión.
