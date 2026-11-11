# Pruebas de la versión de producción en local

Antes de desplegar, la app se corre en tu computadora **igual que en producción**:
- `APP_ENV=production` y `APP_DEBUG=false`.
- MySQL en Docker.
- Assets compilados con `npm run build`.
- Cachés de configuración, rutas y vistas.
- HTTPS mediante un túnel temporal para la Galaxy Tab.

Los datos son ficticios.

## 1. Preparar (una sola vez)

1. Arranca Docker: `sudo systemctl start docker` (para que arranque solo: `sudo systemctl enable docker`).
2. En la carpeta del proyecto:
   ```bash
   scripts/prod-local.sh --datos
   ```
   El script hace todo esto:
   - Crea `.env.production` (no se sube a git) con su propia `APP_KEY`.
   - Levanta MySQL en el puerto 3307.
   - Compila los assets.
   - Corre las migraciones.
   - **Borra la base** y genera datos de prueba: 300 alumnos, 15 docentes, 72 clases y dos semanas de historial.
   - Cachea la configuración y arranca el servidor en http://localhost:8000.

   Al terminar imprime las cuentas y **los códigos para vincular los dos kioscos**. Cópialos: solo se muestran esa vez. Si los pierdes, genera otros desde Kioscos › Administrar › Vincular otra vez.
3. Las siguientes veces corre `scripts/prod-local.sh` sin `--datos` para conservar lo que hayas probado.

**Cuentas de prueba** (contraseña `prueba-2026`):
- Dirección: `direccion@marcaje.test`.
- Docentes: `docente1@marcaje.test` … `docente14@marcaje.test`.
- `docente15@marcaje.test` tiene una contraseña temporal vigente (el script la imprime) para probar el cambio obligatorio.

## 2. Abrirla en la Galaxy Tab (HTTPS)

El kiosco necesita HTTPS (service worker, pantalla siempre encendida, identificadores únicos). En otra terminal:

```bash
scripts/tunnel.sh
```

Aparece una URL `https://…trycloudflare.com`, distinta cada vez.
- **En la tableta:** abre esa URL en Chrome.
- **Para vincular un kiosco:** en tu computadora entra a esa misma URL (no a `localhost`), ve a Kioscos › Administrar › Vincular otra vez y escanea el QR con la cámara de la tableta. El código QR lleva la dirección por la que entraste.
- **Mientras el túnel esté abierto,** cualquiera con la URL puede ver tu copia local. Ciérralo con Ctrl+C al terminar.

Conecta los lectores USB (NFC y QR) a la tableta con un adaptador OTG.

## 3. Checklist de pruebas manuales

Marca cada punto. Si algo falla, anota qué hiciste, qué esperabas y qué pasó.

### Acceso y roles
- [ ] Entrar como dirección: el menú muestra Escuela (Alumnos, Docentes, Grupos) y Asistencia (Pase de lista, Entrada manual, Clases, Credenciales, Kioscos, Reportes, Ajustes).
- [ ] Entrar como docente: el menú muestra solo Alumnos, Pase de lista, Entrada manual y Clases.
- [ ] Como docente, abrir a mano `/reportes`, `/kioscos`, `/ajustes` y `/docentes`: todas dan "403".
- [ ] Contraseña equivocada 5 veces: aparece "Demasiados intentos. Vuelve a intentar en 15 minutos." y ni la contraseña correcta deja entrar.
- [ ] `docente15@marcaje.test` con su contraseña temporal: obliga a elegir una nueva antes de usar el sistema.
- [ ] Dirección › Docentes › un docente › Generar contraseña temporal: se muestra una sola vez; al recargar ya no aparece.
- [ ] Desactivar un docente con su sesión abierta en otro navegador: en su siguiente clic sale al login con aviso.

### Gestión de personal (dirección)
- [ ] Docentes › Importar CSV › Descargar plantilla. Llenar 3 filas con un correo repetido y otra con un correo que ya existe: la vista previa marca el error en cada fila y no deja confirmar.
- [ ] Corregir el archivo e importarlo "como dirección": la vista previa dice "Se crearán N cuentas"; al confirmar aparece la lista de contraseñas temporales y "Copiar todo". Al recargar esa página ya no se muestran.
- [ ] Entrar con una de esas cuentas: obliga a cambiar la contraseña.
- [ ] En la ficha de un docente se ven "Último acceso" y "Sesiones abiertas". Con su sesión abierta en otro navegador, "Cerrar sus sesiones": en su siguiente clic sale al login.
- [ ] "Suspender cuenta" con su sesión abierta: sale de inmediato. "Reactivar cuenta": puede volver a entrar.
- [ ] Fallar 5 veces el login de un docente (aunque sea desde dispositivos distintos): queda "Bloqueada por intentos fallidos" en la lista. En su ficha, "Desbloquear ahora": ya puede entrar.
- [ ] Intentar quitarte tu propio rol de dirección, suspenderte o darte de baja: no se permite. Con una sola cuenta de dirección activa, tampoco se puede degradar ni suspender.
- [ ] Como docente, abrir `/docentes/importar`: 403.

### Kiosco (Galaxy Tab, por el túnel)
- [ ] Vincular escaneando el QR desde Kioscos › Administrar. Tocar "Iniciar kiosco": entra en pantalla completa.
- [ ] **QR:** pasar una credencial impresa por el lector QR. Pantalla de "Entrada registrada" con nombre, grupo, hora y "QR" durante 1.5 s, con sonido.
- [ ] **NFC:** acercar una pegatina vinculada al lector NFC. Lo mismo, con "NFC".
- [ ] **Vincular NFC:** en Alumnos › un alumno › Vincular NFC, pasar una pegatina nueva **con el mismo lector y la misma configuración** que el kiosco. Luego leerla en el kiosco.
- [ ] **Duplicado:** repetir la misma credencial antes de 5 minutos. "Tu entrada ya estaba registrada" con la hora original; no se crea otra entrada.
- [ ] **Desconocida:** credencial revocada o QR de otro sistema. "Credencial no reconocida".
- [ ] **Sin conexión:** activar el modo avión en la tableta y leer 3 credenciales. La confirmación sigue siendo inmediata y el estado dice "Sin conexión, 3 entradas por enviar". Al quitar el modo avión, en unos segundos queda "Sin pendientes" y las entradas salen en Kioscos con su hora original.
- [ ] **Recarga sin conexión:** con el modo avión, recargar la página. El kiosco vuelve a abrir.
- [ ] **Desvincular:** en el panel, Kioscos › Desvincular. La tableta muestra la pantalla de vincular en su siguiente lectura o sincronización.
- [ ] Probar los 2 kioscos a la vez con la misma credencial: el segundo marca duplicado.

### Kiosco con cámara (Galaxy Tab y PC)
Configura el modo en el panel: **Ajustes › Kioscos: modo de lectura** (Lector USB, Cámara o Ambos). El kiosco lo aplica al recargar.

En la **Galaxy Tab** (cámara integrada, Chrome con `BarcodeDetector` nativo):
- [ ] Modo "Cámara": recargar el kiosco. La pantalla de inicio explica para qué es la cámara; al tocar "Iniciar kiosco" Chrome pide permiso. Aceptar.
- [ ] Se ve la vista en vivo dentro del recuadro cuadrado con las esquinas y "Centra tu código QR aquí". Mostrar una credencial impresa: confirmación de 1.5 s con "QR" y sonido.
- [ ] Dejar el mismo QR frente a la cámara: no se repite la confirmación (pausa de 1.5 s y 5 s de espera para el mismo código). Pasado ese tiempo, si sigue ahí, sale "Tu entrada ya estaba registrada".
- [ ] Mostrar el QR desde la pantalla de un celular: se lee igual.
- [ ] Si hay cámara frontal y trasera: "Cambiar cámara" alterna; la frontal se ve en espejo. El selector recuerda la elegida al recargar.
- [ ] Modo "Ambos": con la cámara activa, pasar una pegatina por el lector NFC y un QR por el lector USB: los dos registran.
- [ ] Permiso negado: en Ajustes del sitio (candado) poner Cámara en "Bloquear" y recargar. Aparece el aviso con instrucciones y el lector USB sigue funcionando. Volver a permitir y recargar: la cámara regresa.
- [ ] Modo avión con la cámara activa: las lecturas por cámara entran a la cola ("por enviar") y se envían al volver la red.
- [ ] Modo "Lector USB": no pide permiso de cámara ni la enciende.

En la **PC** (webcam; Chrome de escritorio, que en Linux usa el lector de respaldo zxing):
- [ ] Abrir el kiosco por el túnel en Chrome, modo "Cámara" o "Ambos": pide permiso y muestra la webcam. Si hay más de una cámara, el selector permite elegir.
- [ ] Mostrar a la webcam un QR impreso o en el celular: registra la entrada (puede tardar un poco más que en la tableta).
- [ ] En el panel (cualquier página que no sea el kiosco) la cámara está bloqueada: en las herramientas de Chrome, la cabecera `Permissions-Policy` dice `camera=()`; en `/kiosco` dice `camera=(self)`.

### Pase de lista (docente, en la tableta o el celular)
- [ ] Pase de lista muestra las clases del día; la que está en curso dice "En curso".
- [ ] En la lista abierta, los alumnos que entraron por el kiosco aparecen "Por confirmar" con hora y método; los demás, "Falta".
- [ ] Tocar un lugar abre la barra de acciones. Presente, Retardo y Falta funcionan. Para un alumno con entrada, el botón dice "No en aula".
- [ ] "Confirmar todas las entradas" pasa a Presente solo a quienes tenían entrada y seguían sin decisión.
- [ ] Una entrada registrada por el kiosco durante la clase aparece sola en menos de 20 segundos.
- [ ] Los 5 órdenes funcionan, y al volver a entrar a la clase se recuerda el último.
- [ ] "Acomodar lugares": arrastrar con el dedo y con el mouse; "Volver al alfabético".
- [ ] En computadora: teclas P, R, F, C y ⌫, flechas, y Alt + flechas en el modo acomodar. En la tableta la leyenda de teclado no aparece.
- [ ] Abrir una clase que ya terminó: "Lista cerrada", sin poder editar.
- [ ] Como otro docente, abrir la URL de una lista ajena: 403.

### Entrada manual
- [ ] Como docente, buscar un alumno que **no** está en sus clases: no aparece.
- [ ] Registrar la entrada de un alumno propio con motivo "Olvidó su credencial". Aparece en "Entradas manuales de hoy" con quién la registró.
- [ ] Motivo "Otro" sin escribir el detalle: pide escribirlo.
- [ ] Repetir antes de 5 minutos: avisa que ya tiene entrada y no crea otra.
- [ ] En el pase de lista de ese alumno se lee "Entrada manual" en rosa.

### Alumnos y credenciales (dirección)
- [ ] Importar un CSV de prueba con un error a propósito: no se importa nada y se lista el error por línea. Corregirlo e importar.
- [ ] Imprimir las credenciales de un grupo en hoja carta y escanear varias con la cámara del celular: se leen los 8 caracteres.
- [ ] Regenerar el QR de un alumno: el anterior ya no funciona en el kiosco.
- [ ] Dar de baja a un alumno: su pegatina NFC queda libre para otro.

### Reportes (dirección)
- [ ] "Últimos 30 días": los números del resumen son coherentes con el historial.
- [ ] Filtros de grado y grupo, y búsqueda de un alumno.
- [ ] Las pestañas "Entró pero no llegó a clase", "Entradas manuales" y "Clases y docentes" (con sesiones sin pase de lista) tienen datos.
- [ ] Exportar CSV de cada pestaña y abrirlo en Excel: acentos correctos, una columna por campo.

### Ajustes (dirección)
- [ ] Cambiar el nombre de la institución: se ve en el panel, en el kiosco al recargar y en las credenciales impresas.
- [ ] Cambiar el anti-duplicado a 1 minuto y repetir una credencial en el kiosco después de 1 minuto: ahora sí registra.
- [ ] Cambiar la zona horaria: las horas del kiosco y del pase de lista cambian.

### Seguridad básica en el navegador
- [ ] Abrir `/.env` y `/storage/logs/laravel.log` en la URL del túnel: ambos dan 404.
- [ ] Provocar un error (por ejemplo, `/pase-de-lista/999/2026-01-01`): página genérica, sin detalles técnicos.
- [ ] En las herramientas de Chrome (Red), una respuesta del panel trae `Content-Security-Policy`, `Strict-Transport-Security` y `X-Frame-Options`.

## 4. Pruebas automatizadas contra MySQL

Las pruebas normales usan SQLite. Para correrlas contra el mismo MySQL de Docker (base `marcaje_test`):

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=marcaje_test DB_USERNAME=marcaje DB_PASSWORD=marcaje-local php artisan test
```

## 5. Volver al modo de desarrollo

```bash
php artisan optimize:clear
docker compose stop mysql
```

El `.env` de desarrollo no se toca: el modo producción usa `.env.production`.
