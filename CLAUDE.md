# formulario-mensaje

Plugin de formularios para WordPress, pensado para webs propias y de clientes. Se distribuye por las releases de GitHub y las webs lo actualizan desde el escritorio de WordPress (ver «Distribución y actualizaciones»). El código del plugin vive en `plugin/` (fichero principal: `plugin/formulario-mensaje.php`). El resto del repositorio es el entorno para desarrollarlo y probarlo de forma agéntica.

## Reglas de trabajo

Dos de ellas definen cómo se escribe cada línea del plugin y el usuario las ha marcado como muy importantes: se desarrolla siempre siguiendo los principios SOLID y las buenas prácticas de desarrollo de plugins para WordPress con PHP (regla 8).

### 1. Documentación actualizada antes de escribir código

Siempre, antes de escribir o modificar cualquier código (PHP del plugin, tests, TypeScript, configuración o scripts), consulta el MCP de Context7 para leer la documentación actual de las librerías y servicios que ese código usa. No escribas una API de memoria: confirma primero firma, parámetros y comportamiento en la versión que usa el proyecto (las versiones están en `scripts/runtime.config.psd1`, `composer.json` y `package.json`).

Para que la consulta sea eficiente:

- Llama directamente a `query-docs` con los identificadores de la tabla. Usa `resolve-library-id` solo para una librería que no esté en ella, y añádela a la tabla al terminar.
- Una consulta por concepto y bien concreta («registrar una ruta REST con `permission_callback`»), no palabras sueltas ni varios temas mezclados.
- Consulta solo lo que el cambio toca y no repitas en la misma sesión algo que ya has consultado.
- Si Context7 no devuelve nada útil, dilo y acude a la documentación oficial; no rellenes el hueco suponiendo.

| Librería o servicio | Identificador en Context7 |
| --- | --- |
| WordPress: funciones | `/websites/developer_wordpress_reference_functions` |
| WordPress: hooks (acciones y filtros) | `/websites/developer_wordpress_reference_hooks` |
| WordPress: clases | `/websites/developer_wordpress_reference_classes` |
| WordPress: manuales (plugins, REST API, bloques) | `/kasparsd/wp-docs-md` |
| WP-CLI | `/wp-cli/handbook` |
| PHPUnit 9.6 | `/websites/phpunit_de_en_9_6` |
| Playwright | `/websites/playwright_dev` |
| Estándares de código de WordPress (PHPCS) | `/wordpress/wordpress-coding-standards` |
| PHPCompatibilityWP | `/phpcompatibility/phpcompatibilitywp` |
| Elementor: addons y widgets clásicos | `/elementor/elementor-developers-docs` |
| Cloudflare Turnstile | `/websites/developers_cloudflare_turnstile` |
| hCaptcha | `/websites/hcaptcha` |
| reCAPTCHA v3 | `/websites/developers_google_recaptcha_v3` |
| Resend | `/websites/resend` |
| PHPMailer | `/phpmailer/phpmailer` |
| Gutenberg: `@wordpress/components`, `api-fetch`, `scripts`, bloques | `/wordpress/gutenberg` |
| GitHub Actions (flujos, permisos del `GITHUB_TOKEN`) | `/websites/github_en_actions` |
| GitHub CLI (`gh release`) | `/websites/cli_github_manual` |
| GitHub: releases, enlaces a la última release, API REST | `/websites/github_en` |

### 2. Skills de WordPress para desarrollar el plugin

Siempre que se desarrolle el plugin hay que usar las skills de WordPress instaladas en `.claude/skills/`. Antes de empezar una tarea, carga la skill que le corresponde y sigue su procedimiento y sus comprobaciones; no trabajes en el plugin sin ninguna cargada.

`wp-plugin-development` es la base y se carga en cualquier trabajo sobre el plugin. Según lo que toque la tarea, añade además:

| La tarea toca… | Skill |
| --- | --- |
| Arquitectura, hooks, activación y desinstalación, ajustes, almacenamiento, cron, seguridad, empaquetado | `wp-plugin-development` |
| Endpoints o rutas de la REST API | `wp-rest-api` |
| Bloques de Gutenberg | `wp-block-development` |
| Patrones de bloques | `wp-patterns` |
| Interactividad en el frontend (`data-wp-*`) | `wp-interactivity-api` |
| Interfaz con componentes del WordPress Design System | `wpds` |
| Abilities API | `wp-abilities-api`, `wp-abilities-audit`, `wp-abilities-verify` |
| Operaciones con WP-CLI | `wp-wpcli-and-ops` |
| Rendimiento | `wp-performance` |
| Análisis estático con PHPStan | `wp-phpstan` |
| Requisitos del directorio de plugins de WordPress.org | `wp-plugin-directory-guidelines` |
| No está claro cuál aplica | `wordpress-router` |

Al seguirlas en este proyecto:

- Donde una skill use `wp`, aquí es `./rt.sh wp`: WP-CLI no está en el PATH.
- Las rutas de sus scripts vienen como `skills/<nombre>/scripts/...`; aquí llevan `.claude/` delante.
- El entorno local es `.runtime`. Las skills `wp-env`, `wp-playground` y `blueprint` describen entornos alternativos que este proyecto no usa.

### 3. Ninguna tarea está terminada sin tests y sin verificar

- Cada cambio de comportamiento lleva su test: PHPUnit para la lógica y Playwright cuando cambia algo visible (el formulario, una pantalla de administración, un correo).
- Una tarea está terminada cuando se cumplen las cinco cosas: `rt lint` sin errores, `rt phpunit` en verde, `rt e2e` en verde, `rt check` sin hallazgos y `rt logs wp` sin avisos ni errores nuevos (ejecuta `rt logs clear` antes de probar para distinguirlos).
- Antes de verificar: si has tocado `src/`, `rt build`; si has añadido o cambiado textos de interfaz, ejecuta `rt i18n`, tradúcelos en cada `.po` de `plugin/languages/` (los nuevos quedan al final, con el `msgstr` vacío) y vuelve a ejecutarlo (falla mientras falte alguno). Los tests usan lo compilado. Para el JavaScript, además, `npm run lint:js` sin errores.
- Además, no puede quedar ningún hallazgo de la revisión de seguridad sin corregir o justificar (regla 7).
- Y el código cumple los principios SOLID y las buenas prácticas de WordPress (regla 8): que funcione y pase los tests no basta.
- Si algo no se ha podido verificar, se dice expresamente al informar; no se da por bueno.

### 4. Versiones mínimas y estándar de código

- **PHP 8.1 como mínimo.** No uses sintaxis ni funciones de PHP 8.2 o posterior. El entorno ejecuta PHP 8.3, así que los tests no lo detectan: lo vigila `rt lint` con PHPCompatibility.
- **WordPress 7.0 como mínimo.** El entorno prueba con 7.1 y ninguna herramienta avisa si usas algo añadido en 7.1. Al consultar la documentación de una función, hook o clase, mira su `@since`: si es posterior a 7.0, no lo uses o protégelo con una comprobación de existencia.
- **Estándares de código de WordPress**, definidos en `phpcs.xml.dist` y comprobados con `rt lint`. `rt lint fix` corrige lo que se puede arreglar automáticamente.
- La cabecera del plugin declara `Requires at least: 7.0` y `Requires PHP: 8.1`.

### 5. Idioma

- **En inglés**: identificadores (funciones, clases, variables, hooks, claves de opciones) y todos los textos de la interfaz, que deben ser siempre traducibles con el text domain del plugin.
- **En español**: comentarios y docblocks, mensajes de commit, documentación y la conversación con el usuario.
- El inglés de EE. UU. (`en_US`) es el idioma de origen: son los textos del código y no lleva ficheros de traducción, así que se escriben en inglés estadounidense («color», no «colour») y con comillas y apóstrofos tipográficos (“ ” ’). La primera traducción es `es_ES`, que sirve además de reserva a las demás variantes del español. Cada texto de interfaz que se añada o cambie se traduce en la misma tarea a todos los idiomas que haya en `plugin/languages/`.

### 6. Git

- Solo se hace commit cuando el usuario lo pide. Al terminar una tarea, los cambios se dejan sin confirmar y se avisa.
- Nunca se hace push sin que lo pida.
- Mensajes en español: un título corto y un cuerpo que explica el porqué del cambio.
- `.mcp.json` contiene una clave en claro y está en `.gitignore`: no se añade nunca al repositorio.
- Los commits y los push se hacen con la herramienta Bash, no con PowerShell, y con `git commit` o `git push` como comando propio: la revisión de seguridad de commits solo se dispara así (regla 7).

### 7. Seguridad

El plugin de Claude Code `security-guidance` está activado para el proyecto y revisa el código en tres momentos: avisa al editar si reconoce un patrón peligroso, revisa con un modelo el diff al terminar cada turno y revisa cada commit. Sus hallazgos llegan como avisos durante el trabajo o justo después de responder.

- Las reglas de seguridad del plugin de formularios están en `.claude/claude-security-guidance.md`. Se cumplen al escribir el código, no solo cuando una revisión las reclama.
- Un hallazgo se corrige. Si es un falso positivo, se explica en un comentario junto a la línea por qué es seguro (la revisión lo tiene en cuenta) y se le cuenta al usuario al informar.
- Nunca se silencia un hallazgo desactivando el plugin o alguna de sus capas (`SECURITY_GUIDANCE_DISABLE`, `ENABLE_PATTERN_RULES`, `ENABLE_CODE_SECURITY_REVIEW`, `ENABLE_STOP_REVIEW`, `ENABLE_COMMIT_REVIEW`) si el usuario no lo ha pedido.
- Tras un commit, la revisión tarda alrededor de medio minuto: espera su resultado antes de dar el commit por bueno.
- Cuando el plugin gana una superficie nueva (subida de ficheros, peticiones salientes, exportaciones, una ruta REST), se actualiza `.claude/claude-security-guidance.md` en la misma tarea. Si además hay una llamada peligrosa reconocible por su nombre, se añade un patrón a `.claude/security-patterns.json`.
- La revisión es una ayuda: no sustituye a `rt lint`, que es quien comprueba de forma sistemática el escapado, el saneado y los nonces, ni a los tests.

### 8. Principios SOLID y buenas prácticas de WordPress

**Regla de primer orden, al mismo nivel que los tests y la seguridad.** Todo el código de `plugin/` se diseña siguiendo los principios SOLID y las buenas prácticas de desarrollo de plugins para WordPress con PHP. No es una preferencia de estilo: el código que funciona y pasa los tests pero los incumple no está terminado (regla 3).

**SOLID, aplicado a este plugin**

- **S, responsabilidad única.** Cada clase tiene un solo motivo para cambiar: validar, guardar, enviar correo, renderizar y registrar hooks son clases distintas. Los callbacks de hooks y los controladores REST son finos: comprueban permisos, sanean la entrada y delegan en un servicio; no contienen lógica de negocio. No hay clases que hagan de todo, y el fichero principal solo define constantes, carga las clases y arranca el plugin.
- **O, abierto/cerrado.** Añadir un tipo de campo, una acción, un proveedor de captcha o un transporte de correo consiste en crear una clase nueva y registrarla, no en modificar las existentes. Un `switch` o una cadena de `if` sobre el tipo es señal de que falta una interfaz y un registro.
- **L, sustitución de Liskov.** Cualquier implementación de una interfaz se puede cambiar por otra sin que quien la usa lo note: mismo contrato de parámetros, de retorno y de errores. Ninguna responde «no soportado» ni obliga a comprobar su clase concreta con `instanceof`.
- **I, segregación de interfaces.** Interfaces pequeñas y con un único propósito. Si una clase tiene que implementar métodos que no necesita, la interfaz se divide.
- **D, inversión de dependencias.** Las clases reciben sus dependencias por el constructor y dependen de interfaces, no de clases concretas. Sin singletons, sin `global`, sin estado estático y sin crear servicios con `new` dentro de la lógica. El acceso a `$wpdb`, al correo y a las peticiones HTTP queda detrás de clases propias (repositorios, transportes, clientes), para que la lógica se pueda probar aislada.

**Composición: cómo se construyen y conectan los objetos**

- **Contenedor propio, mínimo y perezoso.** Guarda cómo se construye cada servicio y lo crea la primera vez que se pide. Sin librerías: ni un contenedor de terceros ni `psr/container`, porque WordPress no aísla las dependencias de cada plugin y dos versiones del mismo paquete chocan.
- **Un proveedor por módulo** (correo, envíos, webhooks…) declara sus servicios y registra sus hooks. El callback de cada hook pide el servicio cuando el hook se dispara, no antes: una petición que no usa el plugin no construye nada, y lo que es solo de administración cuelga de hooks de administración.
- **El contenedor no es un localizador de servicios.** Solo lo conocen el arranque y los proveedores; ningún servicio lo recibe ni le pide nada.
- **Una sola excepción al «sin estado estático»**: un acceso estático al contenedor para las clases que no construimos nosotros y que por eso no admiten inyección por constructor. El caso conocido es el widget de Elementor, que Elementor instancia por su cuenta con los datos de cada elemento. Se usa únicamente en esos adaptadores y responde con un error de «no inicializado» si el plugin no ha arrancado.
- **Adaptadores sobre servicios.** Las rutas REST, el shortcode, el bloque, el widget, los comandos de WP-CLI y las abilities son adaptadores finos sobre el mismo servicio. Ninguno reimplementa la lógica ni llama a otro adaptador para obtenerla.
- **Fuera del contenedor**: los hooks de activación y desactivación se registran en el nivel superior del fichero principal, no dentro de otro hook, y `uninstall.php` hace su propia composición mínima, porque se ejecuta sin que el plugin haya arrancado.

SOLID no justifica la sobreingeniería, que va contra la ligereza del plugin: una abstracción se crea cuando hay una variación real (dos implementaciones que existen o que están en `PLAN.md`), no por si acaso.

**Buenas prácticas de WordPress y PHP**

- **Las APIs de WordPress antes que una solución propia**: REST API, Options y Settings API, HTTP API, `wp_mail`, `$wpdb`, WP-Cron, transients, capabilities. El plugin se integra con hooks; nunca modifica ni asume el interior del núcleo, del tema o de otros plugins.
- **Nada se ejecuta al cargar un fichero.** Un fichero declara (clases, funciones) o arranca, no las dos cosas. Todo lo demás cuelga de un hook, del más específico posible y lo más tarde que sirva.
- **Scripts y estilos** registrados y encolados con las funciones de WordPress, con sus dependencias y versión, y solo en las páginas que los usan.
- **Ciclo de vida completo**: activación, desactivación y desinstalación tienen cada una su sitio y hacen solo lo suyo; `uninstall.php` comprueba `WP_UNINSTALL_PLUGIN` antes de borrar nada. Las tareas de cron pueden ejecutarse tarde o más de una vez sin daño y tienen una vía manual para lanzarlas. El esquema de la base de datos lleva versión y sus cambios son migraciones que se pueden ejecutar más de una vez sin daño.
- **Nada en el espacio global sin prefijo**: clases en el namespace del plugin; funciones, hooks, opciones, tablas y handles de scripts con el prefijo (ver «Convenciones del plugin»).
- **PHP tipado**: tipos en parámetros, retornos y propiedades, y `readonly` y enumeraciones donde aporten. Los errores se devuelven de forma coherente (`WP_Error` o excepciones propias, no las dos cosas mezcladas en una misma capa) y nunca se silencian con `@`.
- **Rendimiento**: sin consultas ni carga de clases en peticiones que no usan el plugin; las opciones se cargan automáticamente solo si hacen falta en cada petición.
- **Extensible por diseño**: los puntos donde otro desarrollador querría intervenir exponen acciones y filtros con prefijo y documentados.
- La seguridad y la traducción son parte de las buenas prácticas y tienen sus propias reglas (7 y 5). La skill `wp-plugin-development` es la referencia de procedimiento (regla 2).

**Cómo se comprueba.** Ninguna herramienta lo hace: `rt lint` no detecta un mal diseño. Por eso, antes de dar una tarea por terminada se repasa el diff contra esta regla, y al informar se dice cómo se ha aplicado y cualquier excepción, con su motivo. Si para cumplirla hay que reorganizar código ya escrito, se reorganiza en la misma tarea en vez de añadir un parche encima.

## Convenciones del plugin

| | |
| --- | --- |
| Slug, carpeta y fichero principal | `formulario-mensaje` |
| Text domain | `formulario-mensaje` |
| Prefijo de funciones, hooks y opciones | `formulario_mensaje_` |
| Namespace de las clases | `Formulario_Mensaje`, con subnamespaces por módulo (`Formulario_Mensaje\Mail`) |
| Nombres de clases, interfaces, traits y enums | Palabras con mayúscula inicial separadas por guiones bajos: `Smtp_Transport`, `Mail_Transport` |
| Ficheros PHP | Uno por clase, interfaz, trait o enum, en minúsculas con guiones y con el prefijo de su tipo: `class-smtp-transport.php`, `interface-mail-transport.php`, `trait-…`, `enum-…`. Carpetas en minúsculas con guiones, una por subnamespace |
| Carga de clases | Autoloader propio registrado en el fichero principal; sin PSR-4 y sin Composer en producción |
| PHP mínimo | 8.1 |
| WordPress mínimo | 7.0 |
| Distribución | Releases de GitHub (`alvarofontela/formulario-mensaje`), una por tag de versión; el zip es el de `scripts/package.py` |
| Versiones y tags | SemVer sin «v» delante: `1.0.0`, `1.2.3`; las preliminares, con sufijo (`1.3.0-beta.1`) |

El slug está definido en `scripts/runtime.config.psd1` y los prefijos y el text domain en `phpcs.xml.dist`; si cambia uno, hay que cambiarlo en los dos sitios y aquí. El slug está además en `.github/workflows/release.yml` y en `Updates_Provider`, junto al repositorio de GitHub, que también va en la cabecera `Update URI`.

## Puesta en marcha desde un clon

Hace falta Windows con PowerShell 7, Node.js, git y Python 3.10 o posterior (el de python.org, no el de Microsoft Store; lo usa el plugin `security-guidance`). PHP, Apache, MariaDB y el resto de servicios los descarga el propio entorno.

1. `./rt.sh setup` instala y arranca todo, incluidas las dependencias de Composer y npm.
2. Crea tu `.mcp.json` con tu clave de Context7 (no está en el repositorio):
   `claude mcp add --scope project --transport http context7 https://mcp.context7.com/mcp --header "Authorization: Bearer TU_CLAVE"`
3. `./rt.sh phpunit` y `./rt.sh e2e` deben pasar.

## Qué no se edita a mano

- `.runtime/`: se regenera con `rt setup`. Su configuración sale de `scripts/runtime.config.psd1`.
- `.claude/skills/`: copia de [WordPress/agent-skills](https://github.com/WordPress/agent-skills) (commit `3cf7f6f`). Para actualizarlas, clona ese repositorio y ejecuta en él `node shared/scripts/skillpack-build.mjs --clean --targets=claude` y `node shared/scripts/skillpack-install.mjs --dest=<ruta de este proyecto> --targets=claude`.
- `.claude/skills/security-audit/`: la única que no viene de ahí. Es copia de `skills/security-audit/` de [cloudflare/security-audit-skill](https://github.com/cloudflare/security-audit-skill) (commit `c1c8a8c`), con su `LICENSE` (MIT). Para actualizarla se vuelve a copiar esa carpeta. Es una auditoría de seguridad bajo demanda y no sustituye a la regla 7. Sus validadores (`validate-*.cjs`) no funcionan con Node en Windows, porque necesitan `O_NOFOLLOW` y `O_NONBLOCK`: se lanzan en un contenedor (con Docker Desktop arrancado), montando la skill y la carpeta de la auditoría, p. ej. `docker run --rm --network none -v "<ruta de la skill>:/skill:ro" -v "<carpeta de la auditoría>:/run:ro" node:22-alpine node /skill/validate-findings.cjs /run/findings.json`.
- `vendor/` y `node_modules/`: salen de `composer.lock` y `package-lock.json`.
- `plugin/build/`: resultado de compilar `src/` con `rt build`. Se versiona para que `plugin/` sea instalable tal cual.
- En `plugin/languages/`, todo menos los `.po`: la plantilla `.pot` y los compilados (`.mo`, `.l10n.php` y `.json`) los genera `rt i18n`. Los `.po` sí se editan, a mano o con Poedit: son la fuente de las traducciones.
- El mu-plugin del entorno se edita en `scripts/templates/rt-environment.php` y se aplica con `rt setup`, no en `.runtime/www`.

## Revisión de seguridad (`security-guidance`)

| Fichero | Qué hace |
| --- | --- |
| `.claude/settings.json` | Activa el plugin para cualquiera que abra el proyecto con Claude Code. |
| `.claude/claude-security-guidance.md` | Reglas propias del proyecto que se añaden a la revisión del diff de cada turno. Solo pueden añadir comprobaciones, no suprimirlas. |
| `.claude/security-patterns.json` | Patrones propios para PHP de WordPress que avisan al editar, sin llamar a ningún modelo. |
| `.claude/claude-security-guidance.local.md`, `.claude/security-patterns.local.json` | Variantes personales de los dos anteriores; no se versionan. |

Límites que conviene conocer al tocar esos ficheros:

- Las reglas comparten un máximo de 8192 caracteres con las de usuario (`~/.claude/claude-security-guidance.md`), que van delante: lo que sobra se corta por el final. El plugin añade unos 30 caracteres de envoltorio, así que el fichero no puede pasar de 8160 (`len()` en Python, no bytes). Hoy ocupa 8155: al añadir texto hay que recortar otro, medirlo y comprobar en `~/.claude/security/log.txt` que no aparece «truncating». Se envían al modelo en cada revisión, así que no llevan secretos.
- Como mucho 50 patrones, con avisos de hasta 1024 caracteres. Un patrón mal formado o con una expresión regular que el plugin considere lenta se descarta sin avisar: tras cambiarlos, mira `~/.claude/security/log.txt`. Cada aviso sale una vez por fichero y sesión, y solo mira el texto que se escribe en esa edición.
- La revisión de commits no lee las reglas del proyecto; tiene las suyas y puede leer otros ficheros del repositorio para seguir el flujo de los datos.
- Las revisiones con modelo no miran nada dentro de `vendor/`, `node_modules/`, `build/` ni `dist/`, ni los ficheros `.min.js`: se revisan los fuentes, no lo compilado. Tampoco miran los ficheros que git ignora.
- Cada revisión con modelo consume uso de la cuenta (Opus por defecto; `SECURITY_REVIEW_MODEL` lo cambia). El registro de lo que hace el plugin está en `~/.claude/security/log.txt`.

## Entorno local (`.runtime/`)

WordPress autocontenido con binarios portables: Apache 2.4 + mod_php 8.3, MariaDB 11.8, Mailpit, WP-CLI, Composer, Xdebug y el Chromium de Playwright. Las versiones, puertos y credenciales están en `scripts/runtime.config.psd1` y se aplican con `rt setup`.

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
| `rt lint [args]` / `rt lint fix` | PHPCS sobre `plugin/`: estándares de WordPress y compatibilidad con PHP 8.1+ |
| `rt phpunit [args]` | Tests de integración (`tests/phpunit/*Test.php`) |
| `rt e2e [args]` | Tests de Playwright (`tests/e2e/*.spec.ts`) |
| `rt matrix [args]` | Matriz de combinaciones (`tests/matriz/`): muchos formularios con configuraciones distintas, probados con varios navegadores a la vez |
| `rt build` | Compila `src/` (constructor en React y bloque) a `plugin/build/` con `@wordpress/scripts` |
| `rt i18n` | Regenera el `.pot` desde el código, lo fusiona en cada `.po` de `plugin/languages/` y compila sus `.mo`, `.l10n.php` y `.json`. Falla si a un idioma le queda un texto sin traducir, dudoso («fuzzy») o con marcadores (`%s`, `{reference}`) distintos de los del original |
| `rt package` | Crea en `dist/` el zip instalable del plugin (`formulario-mensaje-<versión>.zip`), con la carpeta del plugin dentro. Lo genera `scripts/package.py` con Python para que sea como uno hecho en Linux: rutas con `/`, permisos Unix y nombres en UTF-8. Empaqueta `plugin/` tal como está (antes, `rt build` e `rt i18n` si procede) y le añade `src/` y `webpack.config.js`, el código legible de lo que va compilado en `build/` |
| `rt check [args]` | Pasa Plugin Check, la herramienta con la que WordPress.org revisa lo que recibe, al plugin instalado (con las comprobaciones que necesitan ejecutarlo) y al contenido del zip, que regenera antes. Falla si encuentra algo, salvo el aviso de la cabecera `Update URI`, que se ignora a propósito. Instala Plugin Check en el entorno si falta |
| `rt shot <ruta> <png> [opciones]` | Captura de pantalla con el Chromium del entorno; imprime los errores de consola. Opciones en `scripts/shot.mjs` (`--admin`, `--full`, `--no-js`, `--click=`, `--wait=`) |
| `npm run lint:js` | ESLint con las reglas de WordPress sobre `src/` y `plugin/assets/js/` (`-- --fix` corrige el formato) |
| `rt mail [--json]` / `rt mail clear` | Correos que ha enviado WordPress |
| `rt logs wp\|php\|apache\|access\|mariadb\|mailpit [n]` / `rt logs clear` | Logs; `wp` es el `debug.log` de WordPress |
| `rt xdebug debug\|off` | Depuración paso a paso (puerto 9003) |
| `rt destroy` | Borra `.runtime` conservando la caché de descargas |

## Ciclo de trabajo

1. `rt status`; si algo está parado, `rt start`.
2. Carga las skills que correspondan a la tarea (regla 2) y consulta en Context7 lo que vayas a usar (regla 1).
3. Edita en `plugin/` aplicando SOLID y las buenas prácticas de WordPress (regla 8) y escribe los tests del cambio.
4. Verifica según la regla 3: `rt lint`, `rt phpunit`, `rt e2e` y `rt logs wp`. Usa `rt mail` para revisar los correos. Atiende los avisos de seguridad que hayan llegado (regla 7) y repasa el diff contra la regla 8.
5. Si el estado de WordPress queda sucio, `rt reset`.
6. Informa de lo hecho y de lo verificado, y deja los cambios sin commit (regla 6).

## Dónde está cada cosa

| Ruta | Qué hay |
| --- | --- |
| `plugin/formulario-mensaje.php` | Constantes, autoloader, hook de activación y arranque |
| `plugin/includes/` | Clases PHP, una carpeta por módulo (subnamespace): cada una tiene su proveedor (`class-…-provider.php`), que declara sus servicios y registra sus hooks |
| `plugin/assets/` | CSS y JavaScript del formulario en el frontend. No se compilan: el script es un módulo sin dependencias |
| `src/` | Fuentes de lo que sí se compila: la aplicación de administración (`src/admin/`) y el bloque (`src/blocks/form/`) |
| `plugin/readme.txt` | Ficha del plugin, en inglés y con el formato de WordPress.org: de su registro de cambios salen las notas de cada release (ver «Distribución y actualizaciones») |
| `.github/workflows/release.yml`, `scripts/release.py` | Publicación de una release al subir un tag de versión |
| `PRODUCT.md`, `design/propuestas-backend/` | Para quién es el panel y las maquetas de las que salió su diseño (la D es la elegida). No forman parte del plugin |
| `plugin/build/` | Generado (ver «Qué no se edita a mano») |
| `plugin/languages/` | Traducciones: un `.po` por idioma, que es lo que se edita, y la plantilla y los compilados que genera `rt i18n` |
| `docs/` | Documentación para quien extiende el plugin: `hooks.md` y `extender.md`. Un hook, un comando o una ability nuevos se documentan ahí en la misma tarea |

El panel (`src/admin/`) tiene tres pantallas, y `app.js` decide cuál por la vista que indica el servidor: la bandeja de mensajes (`inbox.js`, con `message-reader.js` para el mensaje abierto), el listado de formularios (`forms-list.js`) y los ajustes (`settings-page.js`, con el resumen de `settings-status.js`). El constructor (`form-editor.js`) son cuatro pasos; «Al recibirlo» (`received-panel.js`) reúne en apartados plegables los paneles de guardado, notificaciones, tras el envío y webhooks, que no saben que están dentro de él. Todo el panel se usa también en tableta y móvil: los estilos (`admin.scss`) cortan a 1080 y 782 px, y cualquier pantalla o control nuevo se comprueba en esos tamaños (`tests/e2e/panel.spec.ts` lo hace con las que hay).

Un envío guardado es un «mensaje» en la interfaz y un `submission` en el código y en la REST API. Lleva estado de lectura (`read_at`; `Submission_Service::mark_read()`), y `Stored_Submission` deduce de sus datos quién escribe, su correo, su teléfono y el comienzo del texto para presentarlo. En qué páginas está cada formulario lo averigua `Form_Placements` (bloque, shortcode y widget de Elementor en el contenido publicado), que guarda el resultado en un transient y lo olvida al guardar o borrar cualquier contenido.

Las traducciones siguen el formato estándar de WordPress: `Translations` registra la carpeta y WordPress carga el `.l10n.php` o, si no está, el `.mo`; los textos del panel van en los `.json`. Para añadir un idioma se copia la plantilla `.pot` a `plugin/languages/formulario-mensaje-<locale>.po`, se rellena su cabecera (`Language`, `Plural-Forms`), se traduce y se ejecuta `rt i18n`. Como WordPress carga antes el `.l10n.php` que el `.mo`, un `.po` editado no se ve hasta ejecutar `rt i18n`; `tests/phpunit/TraduccionesTest.php` falla si los compilados no corresponden a su `.po`. Una variante sin traducción propia (`es_MX`, `es_AR`) usa la de su idioma según `Locale_Fallback`; para que otro idioma tenga reserva se añade una entrada a su lista. La traducción que un sitio instale en `wp-content/languages/plugins/` tiene prioridad sobre la del plugin y sobre la reserva.

Para añadir un tipo de acción (como el correo o el webhook): una clase que implemente `Action_Type` y su proveedor. `is_deferred()` decide si se ejecuta mientras el visitante espera o en segundo plano, por la cola (`plugin/includes/queue/`), con reintentos. La cola no es una tabla aparte: son las filas en estado pendiente del registro de entregas.

El texto sin formato (lo que escribe un visitante, una etiqueta, un asunto) se sanea con `Plain_Text::line()` o `Plain_Text::block()` (`plugin/includes/support/`), no con `sanitize_text_field()`: la de WordPress convierte en `&lt;` los «<» que no son HTML y quita todo «%» seguido de dos cifras hexadecimales (estropea las direcciones con `%20` y textos como «50%de»), y como aquí el texto se guarda tal cual y se escapa al mostrarlo, lo primero acababa viéndose como `&lt;` y lo segundo se guardaba roto. Son funciones estáticas y puras, sin estado: la excepción a «las dependencias se inyectan» es deliberada, porque sustituyen una a una a funciones globales de WordPress.

La papelera de los formularios es del plugin, no la de WordPress: `Post_Form_Repository::trash()` cambia el estado sin `wp_trash_post()`, para que WordPress no borre el formulario por su cuenta y deje sus envíos sin dueño. Una actualización por REST o por el servicio (`Form_Service::update()`, ajustes) cambia solo lo que se le indica: lo que no viene se queda como está.

Para añadir un comando de WP-CLI: un método público en la clase de su grupo (`plugin/includes/cli/`) o una clase nueva registrada en `Cli_Provider`. El docblock es la ayuda que muestra `wp help`, así que va en inglés y sin notas de diseño; esas van en el comentario que precede a la clase. Para añadir una ability: una clase que implemente `Ability` (`plugin/includes/abilities/`) y una línea en `Abilities_Provider`. Hoy todas son de solo lectura y el registro las anota así; una que escriba obliga a ampliar el contrato. Si su resultado lleva texto escrito por visitantes, `returns_visitor_content()` devuelve `true` (no se ofrece por MCP por defecto) y el resultado lo avisa; y ninguna devuelve ajustes de acciones, que es donde están las direcciones de los webhooks y los destinatarios. Comandos y abilities son adaptadores: llaman al servicio, no a una ruta REST.

Los ficheros de los envíos (`plugin/includes/uploads/`): un tipo de campo que los recibe implementa `Upload_Field_Type` y su dato son los ficheros, no un valor de la petición. `Upload_Store` los guarda en el almacén privado y los borra; `Download_Links` y `Download_Controller` son la única vía de descarga. Para admitir un tipo de fichero nuevo se añade a un grupo de `File_Types`, y nunca nada que un servidor o un navegador ejecute o interprete.

Un formulario por pasos no tiene una estructura propia: el tipo de campo «Paso» (`Section_Field_Type`) parte la lista y `Step_Layout` reparte los campos. En el navegador, `plugin/assets/js/steps.js` los muestra de uno en uno.

Para tocar el motor de reglas (un operador nuevo, un cambio de criterio): se cambia en PHP (`plugin/includes/rules/`) y en el navegador (`plugin/assets/js/rules.js`) a la vez, y se añade el caso a `tests/fixtures/reglas.json`, que es lo que comprueba que los dos lados coinciden.

Para añadir un ajuste de estilo: una entrada en `Form_Style`, su propiedad encadenada en `plugin/assets/css/form.css` (`--fm-x: var(--fm-embed-x, var(--fm-form-x, valor))`), su etiqueta en `src/admin/style-panel.js` y en el bloque, y su control en el widget de Elementor (`plugin/includes/elementor/`).

Para añadir una plantilla de partida: una entrada en `Form_Templates::built_in()`. Un ajuste nuevo de un tipo de acción que sea un secreto se quita en su `portable_settings()`, para que no viaje al exportar.

Para añadir un servicio de captcha: una clase en `plugin/includes/captcha/` que implemente `Captcha_Provider`, una línea en `Captcha_Module_Provider` y su adaptador, con el mismo identificador, en `plugin/assets/js/captcha.js`.

Para añadir un transporte de correo: una clase en `plugin/includes/mail/` que implemente `Mail_Transport` y una línea en `Mail_Provider`. Un secreto nuevo (clave de API, contraseña) se guarda con `Secret_Vault`, nunca en una opción en claro, y ninguna ruta lo devuelve.

Para añadir un tipo de campo: una clase en `plugin/includes/fields/types/` que implemente `Field_Type` (o `Input_Field_Type` si recoge datos) y una línea en `Fields_Provider`. El constructor pinta sus ajustes a partir del esquema que declara, sin tocar el JavaScript. Un tipo de acción se añade igual, con `Action_Type` y su proveedor.

## Tests

- PHPUnit usa `wp-phpunit` con su propia base de datos; `tests/phpunit/bootstrap.php` carga el plugin si existe su fichero principal. El correo se inspecciona con `tests_retrieve_phpmailer_instance()`.
- Playwright corre con un solo worker porque todos los tests comparten el mismo WordPress. La sesión de administrador la crea `tests/e2e/auth.setup.ts`; los helpers de correo están en `tests/e2e/support/mailpit.ts`.
- Los datos del entorno (URL, credenciales, rutas) se leen de `.runtime/conf/env.json`; no se escriben a mano en los tests.
- Los tests de PHPUnit del plugin extienden `Caso_Formulario` (`tests/phpunit/support/dobles.php`), que trae los dobles de prueba (reloj, variables del servidor, transporte de correo) y utilidades para crear formularios y enviarlos por REST.
- En Playwright, `tests/e2e/support/forms.ts` crea formularios y páginas por REST como administrador (`AdminApi`) y abre contextos de visitante anónimo, también sin JavaScript.
- El tiempo mínimo de relleno (3 s) es real en Playwright: cada envío tarda al menos eso. En PHPUnit lo anula `Caso_Formulario`. Para crear envíos rápido en los tests de administración, `submitWithoutJs()` (dos por formulario cada diez minutos, que es el límite de esa vía).
- Las tablas del plugin se crean una vez en `tests/phpunit/bootstrap.php`. Dentro de un test WordPress convierte `CREATE TABLE` y `DROP TABLE` en operaciones sobre tablas temporales, así que el borrado de tablas al desinstalar no se puede comprobar con PHPUnit.
- Los controladores y servicios viven todo el proceso de PHPUnit: no guardes en ellos nada que dependa de la petición (un recuento, el usuario), o un test verá datos de otro.
- La cola se procesa en PHPUnit lanzando su acción (`do_action( Queue_Schedule::HOOK )`, tras retirar el evento programado, como hace WP-Cron); para simular que pasa el tiempo se adelanta `next_attempt_at` en la tabla. En Playwright la procesa el WP-Cron real y los webhooks llegan al receptor del entorno, `rt/v1/sink` (`tests/e2e/support/sink.ts`), que guarda lo que recibe y responde con el código que se le pida. Está en este mismo WordPress porque la HTTP API segura rechaza cualquier otra dirección local.
- Elementor (4.3.4) está instalado e inactivo en el entorno. `tests/e2e/estilo.spec.ts` lo activa para su test y lo desactiva al terminar; la página de Elementor se monta con la ruta del entorno `rt/v1/elementor-page`. Para probar con Elementor 3: `rt wp plugin install elementor --version=3.35.9 --force`, `rt e2e estilo` y volver a instalar la 4.3.4.
- El captcha tiene un doble en los dos lados: en Playwright el script del servicio se sustituye con `context.route()` (`tests/e2e/captcha.spec.ts`), y en el servidor el entorno responde él mismo a la verificación de los testigos que empiezan por `rt-` (`rt-valido` es bueno). Los demás testigos van al servicio real, para poder probarlo a mano con sus claves públicas de prueba.
- Los servicios externos se sustituyen por dobles: las peticiones HTTP, con el filtro `pre_http_request`; el SMTP se prueba contra Mailpit (`127.0.0.1:1025`, sin cifrado ni credenciales). Los tests de Playwright que cambian los ajustes de correo los devuelven a los de fábrica al terminar (`AdminApi.saveMailSettings({})`).
- En PHPUnit no hay subidas HTTP, así que `wp_handle_upload()` rechazaría cualquier fichero: los tests de ficheros componen el servicio de envío con `Movedor_De_Pruebas` (`tests/phpunit/FicherosTest.php`). La subida de verdad la prueba Playwright con `setInputFiles()`.
- Dentro de un test de Playwright, `request.newContext()` hereda la sesión de administrador de la configuración: una petición anónima necesita `storageState: { cookies: [], origins: [] }`.
- `rt matrix` es una suite aparte de `rt e2e` (`playwright.matriz.config.ts`, `tests/matriz/`) que prueba combinaciones de opciones con cuatro navegadores en paralelo; no sustituye a `rt e2e`, la complementa, y conviene pasarla tras cualquier cambio que toque el envío, los campos o el constructor. Para poder correr a la vez, cada test usa el banco de pruebas `lab` (`tests/matriz/support/lab.ts`): crea sus propios formularios y páginas, reconoce sus correos por un destinatario propio y sus webhooks por un canal propio del receptor (`rt/v1/sink/<canal>`), y no vacía el buzón ni cambia ajustes del sitio. Tres clases de fichero: `*.spec.ts` (en paralelo), `*.admin.ts` (los que trabajan en el constructor, de dos en dos: el Chromium del entorno no puede con cuatro pantallas de administración a la vez) y `*.global.ts` (los que cambian ajustes de todo el sitio: captcha, correo, plugins activos; corren después, de uno en uno, y los restauran).
- El límite de envíos es de cinco por formulario e IP cada diez minutos (dos sin JavaScript): un test que envíe más veces al mismo formulario se topa con él.
- Los comandos de WP-CLI no se pueden cargar en PHPUnit (no hay WP-CLI): los prueba `tests/e2e/cli.spec.ts` lanzando el WP-CLI del entorno con `spawnSync`. Las rutas de fichero se pasan relativas a la raíz del proyecto: PowerShell parte un argumento como `--file=C:/…` por los dos puntos.
- Las abilities se prueban en PHPUnit con `wp_get_ability( … )->execute()`, que valida la entrada y comprueba el permiso como cualquier cliente, y por la REST API del núcleo (`/wp-abilities/v1/abilities/<nombre>/run`, con `GET` por ser de solo lectura).
- En el panel, un aviso de `@wordpress/components` se repite en una región oculta para lectores de pantalla: `getByText()` encuentra dos elementos. Se localiza por `.components-notice__content` (con `.is-success` si puede haber otro aviso a la vez).
- Los tests de tableta y móvil del panel fijan el tamaño con `test.use({ viewport })` y comprueban que ninguna pantalla se pueda desplazar de lado (`scrollWidth` frente a `innerWidth`) y que los botones de la barra miden al menos 44 px.
- Las actualizaciones se prueban sin salir del equipo. En PHPUnit, `ActualizacionesTest` lanza los dos filtros del núcleo con la respuesta de GitHub simulada por `pre_http_request`. En Playwright, `tests/e2e/support/releases.ts` le dice al entorno qué `release.json` responder (`rt/v1/release`) y el test mira el aviso en «Actualizaciones», en el listado de plugins y en «Ver detalles»; al terminar deja de simular, y sin simulación la consulta va a GitHub de verdad. El entorno tiene desactivadas las actualizaciones automáticas (`AUTOMATIC_UPDATER_DISABLED`), así que su conmutador no aparece y no se comprueba.
- `tests/phpunit/EntornoTest.php` y `tests/e2e/entorno.spec.ts` solo comprueban el entorno; sirven de plantilla.

## Distribución y actualizaciones

El plugin se distribuye por las releases de GitHub y las webs lo actualizan desde el escritorio de WordPress, por la vía nativa: el aviso en el listado de plugins y en «Actualizaciones», «Ver detalles» y las actualizaciones automáticas. No se envía al directorio de WordPress.org, que prohíbe la cabecera `Update URI` y cualquier actualizador propio (decisión del usuario del 2026-10-12, en `PLAN.md`).

- **Cómo llega una actualización.** El plugin declara `Update URI` y el módulo `plugin/includes/updates/` responde a dos filtros del núcleo: `update_plugins_github.com` (`Update_Checker`) y `plugins_api` (`Plugin_Information`). `Release_Feed` lee `release.json` de la última release estable (`…/releases/latest/download/release.json`, no la API de GitHub, que tiene límite por IP) y lo recuerda en un transient. Todo lo demás lo hace WordPress: no se añaden pantallas, botones ni tareas programadas propias.
- **El paquete no sale de la respuesta.** `Github_Project` compone todas las direcciones con el repositorio fijado en el código y una versión que `Release_Manifest` solo admite como `X.Y.Z`. No se añade a `release.json` ninguna dirección que el plugin vaya a usar, ni un filtro que cambie el origen.
- **Publicar una versión.** Subir la versión (cabecera, `FORMULARIO_MENSAJE_VERSION` y `Stable tag`), añadir su entrada a `== Changelog ==`, verificar según la regla 3, confirmar en `main` y, cuando el usuario lo pida, `git tag <versión>` y `git push origin main <versión>`. El flujo `release.yml` llama a `scripts/release.py`, que rechaza el tag si no es SemVer, si no coincide con esos tres sitios o si falta la entrada, y publica la release con el zip y `release.json`. No compila ni pasa tests: empaqueta `plugin/` tal como está. Un tag con sufijo se publica como «pre-release» y las webs no lo reciben. Crear un tag publica código en todas las webs: es un push, y sigue la regla 6.
- **`release.json` tiene dos lados.** Lo escribe `scripts/release.py` y lo lee `Release_Manifest`: un campo nuevo se añade en los dos, con su validación y su test en `tests/phpunit/ActualizacionesTest.php`.

Los requisitos del directorio se mantienen como listón de calidad. Los comprueba `rt check`, pero Plugin Check no ve todo lo que mira una revisión manual: al tocar algo de esta lista se carga además la skill `wp-plugin-directory-guidelines`.

- **`plugin/readme.txt`** es la ficha del plugin, con el formato del directorio, y va en inglés. Su `Stable tag`, sus requisitos y su licencia tienen que coincidir con la cabecera del plugin, y cada versión lleva su entrada en `== Changelog ==`: subir la versión es cambiarla en la cabecera, en `FORMULARIO_MENSAJE_VERSION`, en `Stable tag` y añadir esa entrada. `tests/phpunit/DirectorioTest.php` falla si no coinciden.
- **Servicios externos.** Salvo la consulta de actualizaciones a GitHub, que no lleva datos del sitio, el plugin no se conecta a nada que el administrador no haya configurado, y cada servicio con el que puede hablar (GitHub, captchas, Resend) tiene su apartado en `== External services ==`, con qué datos se envían, cuándo y los enlaces a sus condiciones y a su política de privacidad. Un dominio nuevo en el código sin su apartado hace fallar `DirectorioTest`. Nada de telemetría, de avisos fuera de las pantallas del plugin ni de enlaces de crédito en el formulario.
- **Código legible.** Lo que va compilado en `build/` tiene que ir acompañado de sus fuentes: por eso el zip lleva `src/` y `webpack.config.js`, y el readme dice cómo compilarlas. Lo que se añada a `src/` viaja solo; una herramienta de compilación nueva hay que añadirla a `rt package` y a ese apartado.
- **Falsos positivos de Plugin Check.** Se anotan en la línea con `// phpcs:ignore <código> -- motivo`, como los demás avisos de PHPCS, y solo cuando de verdad lo son. Hoy hay dos: la dirección de verificación de Turnstile (la regla toma por un recurso externo cualquier texto con «cloudflare.com») y `load_plugin_textdomain()`, que hace falta porque el plugin trae traducciones propias.
- **Rutas.** Las del plugin salen de `plugin_dir_path()`, `plugins_url()` y `plugin_basename()` sobre `FORMULARIO_MENSAJE_FILE`, nunca de `WP_PLUGIN_DIR` ni de `WP_CONTENT_DIR`. Lo que el plugin escribe va a la carpeta de subidas (`Upload_Directory`), no a la suya.
- **Consultas.** `get_results()`, `get_row()` y compañía reciben el `prepare()` directamente, no una variable con la consulta ya preparada: Plugin Check no puede seguirla y la da por insegura.
- `Contributors` lleva el marcador `tu-usuario`: es un campo del directorio, donde iría el usuario de WordPress.org, y sin él no significa nada.

## A tener en cuenta

- La máquina es Windows ARM64 y los binarios son x64 emulados: la primera petición tras arrancar tarda un par de segundos, y la primera ejecución de `rt lint` unos 20.
- Ningún correo sale del equipo: un mu-plugin (`scripts/templates/rt-environment.php`) fuerza el envío a Mailpit y además impide borrar, actualizar o reinstalar desde WordPress el plugin enlazado, porque WordPress seguiría el enlace y vaciaría `plugin/`. Por eso en el entorno no se puede pulsar «Actualizar» sobre el plugin: una actualización real se prueba con una copia (una carpeta de verdad en lugar del enlace), y después se restaura el enlace con `rt setup`.
- WP-CLI no puede descomprimir paquetes con rutas muy largas (límite de 260 caracteres de Windows); por eso WordPress se descarga con `rt setup` y no con `wp core download`.
- Windows Defender pone en cuarentena los ficheros que parecen una webshell (un `eval` sobre `$_POST` lo detecta como `Backdoor:PHP/Remoteshell.B`): un caso de prueba escrito así en disco desaparece o da «permiso denegado». Para probar código vulnerable, usa otro tipo de fallo (SQL sin preparar, salida sin escapar).
- PHPCompatibility está fijado a la versión 10 en fase alfa: la estable (9.3) no reconoce la sintaxis de PHP 8.2 y 8.3, que es justo lo que hay que detectar.
