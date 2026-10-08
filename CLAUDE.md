# formulario-mensaje

Plugin de formularios para WordPress. El código del plugin vive en `plugin/` (fichero principal: `plugin/formulario-mensaje.php`).

## Entorno local (`.runtime/`)

WordPress autocontenido con binarios portables: Apache 2.4 + mod_php 8.3, MariaDB 11.8, Mailpit, WP-CLI, Composer, Xdebug y el Chromium de Playwright. Todo `.runtime/` es regenerable y está fuera de git; no se edita a mano. Las versiones, puertos y credenciales están en `scripts/runtime.config.psd1` y se aplican con `rt setup`.

- WordPress: http://localhost:8088 — admin `admin` / `admin`
- Mailpit (correo capturado): http://127.0.0.1:8025
- MariaDB: `127.0.0.1:3307`, bases `wordpress` (sitio) y `wordpress_tests` (PHPUnit)

`plugin/` está enlazado por junction en `.runtime/www/wp-content/plugins/formulario-mensaje`: los cambios se ven en la siguiente petición, sin copiar nada.

## Comandos

El CLI es `scripts/rt.ps1`. Desde Bash: `./rt.sh <comando>`. Desde PowerShell: `.\scripts\rt.ps1 <comando>` (o `.\rt.cmd` para comandos sin comillas ni tuberías en los argumentos).

| Comando | Qué hace |
| --- | --- |
| `rt setup` | Instala o repara todo; idempotente (~70 s con la caché de descargas) |
| `rt start` / `stop` / `restart` / `status [--json]` | Servicios |
| `rt reset` | Devuelve WordPress al estado recién instalado, vacía el buzón y activa el plugin |
| `rt snapshot [nombre]` / `rt restore [nombre]` | Guarda y recupera la base de datos |
| `rt wp <args>` | WP-CLI, p. ej. `rt wp eval '...'`, `rt wp plugin activate formulario-mensaje` |
| `rt sql -e "..."` | Consulta SQL sobre la base de datos del sitio |
| `rt phpunit [args]` | Tests de integración (`tests/phpunit/*Test.php`) |
| `rt e2e [args]` | Tests de Playwright (`tests/e2e/*.spec.ts`) |
| `rt mail [--json]` / `rt mail clear` | Correos que ha enviado WordPress |
| `rt logs wp\|php\|apache\|access\|mariadb\|mailpit [n]` / `rt logs clear` | Logs; `wp` es el `debug.log` de WordPress |
| `rt xdebug debug\|off` | Depuración paso a paso (puerto 9003) |
| `rt destroy` | Borra `.runtime` conservando la caché de descargas |

## Ciclo de trabajo

1. `rt status`; si algo está parado, `rt start`.
2. Editar en `plugin/`.
3. Verificar: `rt phpunit` para la lógica, `rt e2e` para el flujo en navegador, `rt mail` para los correos y `rt logs wp` para avisos y errores de PHP (`WP_DEBUG` está activo y no se muestran en pantalla).
4. Si el estado de WordPress queda sucio, `rt reset`.

## Tests

- PHPUnit usa `wp-phpunit` con su propia base de datos; `tests/phpunit/bootstrap.php` carga el plugin si existe su fichero principal. El correo se inspecciona con `tests_retrieve_phpmailer_instance()`.
- Playwright corre con un solo worker porque todos los tests comparten el mismo WordPress. La sesión de administrador la crea `tests/e2e/auth.setup.ts`; los helpers de correo están en `tests/e2e/support/mailpit.ts`.
- Los datos del entorno (URL, credenciales, rutas) se leen de `.runtime/conf/env.json`; no se escriben a mano en los tests.

## A tener en cuenta

- La máquina es Windows ARM64 y los binarios son x64 emulados: la primera petición tras arrancar tarda un par de segundos.
- Ningún correo sale del equipo: un mu-plugin (`scripts/templates/rt-environment.php`) fuerza el envío a Mailpit y además impide borrar desde WordPress el plugin enlazado, porque el borrado seguiría el enlace y eliminaría `plugin/`.
- WP-CLI no puede descomprimir paquetes con rutas muy largas (límite de 260 caracteres de Windows); por eso WordPress se descarga con `rt setup` y no con `wp core download`.
