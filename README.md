# Formulario Mensaje

Constructor de formularios para WordPress, ligero y auditable: campos propios, notificaciones por correo, webhooks, una bandeja para leer los mensajes y protección antispam integrada.

Nace de tres ideas:

- **No pesa.** No carga CSS ni JavaScript en las páginas que no tienen un formulario, y el script del formulario es un módulo propio sin dependencias.
- **No vende nada.** No hay versión de pago, ni avisos, ni funciones bloqueadas.
- **No pierde nada sin avisar.** Cada mensaje deja constancia de qué pasó con él: si se guardó, qué correos y webhooks salieron, cuáles fallaron y cuándo se reintentan.

| | |
| --- | --- |
| Versión | 0.14.0 |
| WordPress | 7.0 o posterior |
| PHP | 8.1 o posterior |
| Licencia | GPL-2.0-or-later |
| Idiomas | Inglés (EE. UU.) y español (España) |

> **Estado.** El plugin está completo y pasa todas sus pruebas en el entorno local, pero es una versión 0.x: falta comprobarlo en webs reales (ver [Estado del proyecto](#estado-del-proyecto)). Está preparado para enviarse al directorio de WordPress.org.

## Qué hace

**Construir el formulario**

- Un constructor en cuatro pasos: las preguntas, qué pasa al recibir un mensaje, el aspecto y dónde publicarlo.
- Campos de texto, correo, teléfono, área de texto, número, fecha, dirección web, desplegable, botones de opción, casillas, consentimiento de privacidad, ocultos y subida de ficheros, además de títulos, párrafos y separadores.
- Columnas, plantillas de partida, duplicado, y exportación e importación en JSON sin secretos.
- Lógica condicional: un campo, un paso o una notificación solo se aplican si las respuestas cumplen unas reglas. El mismo motor evalúa en el servidor y en el navegador.
- Formularios por pasos, con barra de progreso y validación en cada uno.
- Un panel de estilo por formulario. Sin tocar nada, el formulario hereda la tipografía y los colores del tema.

**Publicarlo**

- Bloque para el editor de WordPress, shortcode y widget de Elementor.
- El HTML se pinta en el servidor, es accesible y sigue funcionando en navegadores sin JavaScript.
- Compatible con caché de página completa: el HTML no lleva nada que caduque.

**Recibir los mensajes**

- Una bandeja en el escritorio de WordPress con leídos y sin leer, búsqueda, filtros, respuesta por correo y exportación a CSV. También se usa desde la tableta y el móvil.
- Varias notificaciones por formulario, cada una con sus destinatarios y su condición, en texto plano o en HTML con el logotipo y el color del sitio.
- Envío con WordPress (`wp_mail`), con un servidor SMTP propio o con Resend.
- Webhooks en segundo plano, con reintentos, cabeceras propias y firma HMAC.
- Registro de entregas: lo que falla se ve, se avisa por correo y se puede reintentar.

**Antispam y privacidad**

- Todos los formularios van protegidos sin captcha: campo trampa, tiempo mínimo de relleno y límite de envíos por IP.
- Si aun así llega spam, se puede añadir Cloudflare Turnstile, hCaptcha o Google reCAPTCHA v3.
- Cada formulario decide si guarda sus mensajes, si guarda la IP completa, anonimizada o no la guarda, y a los cuántos días se borran.
- Los ficheros subidos van a una carpeta privada y solo se descargan con sesión y permiso, o con un enlace firmado que caduca.
- Las contraseñas y claves de API se guardan cifradas y no se vuelven a mostrar.
- El plugin no se conecta a ningún servicio que el administrador no haya configurado.

**Para quien desarrolla**

- Comandos de WP-CLI (`wp formulario-mensaje form|submission|queue`).
- Abilities de solo lectura para la Abilities API de WordPress.
- Acciones y filtros para añadir tipos de campo, acciones y servicios de captcha propios.

## Instalación

El código del plugin está en la carpeta [`plugin/`](plugin/), que es instalable tal cual.

- **Desde este repositorio**: copia `plugin/` a `wp-content/plugins/formulario-mensaje` y actívalo en la pantalla de plugins.
- **Con un zip**: `./rt.sh package` crea `dist/formulario-mensaje-<versión>.zip`, que se sube desde *Plugins → Añadir nuevo → Subir plugin*.

Después, en el menú **Formularios**, crea el primero a partir de una plantilla y ponlo en una página con el bloque «Formulario», con el widget de Elementor o con el código que da el paso «Publicar».

No se puede activar en toda una red multisitio: se activa en cada sitio.

## Documentación

| Documento | Para qué |
| --- | --- |
| [`docs/hooks.md`](docs/hooks.md) | Acciones y filtros del plugin, con sus parámetros y ejemplos |
| [`docs/extender.md`](docs/extender.md) | Añadir tipos de campo, acciones y captchas; traducciones, WP-CLI y abilities |
| [`plugin/readme.txt`](plugin/readme.txt) | Ficha del plugin para el directorio de WordPress.org (en inglés), con los servicios externos |
| [`PLAN.md`](PLAN.md) | Decisiones de producto y técnicas, y el porqué de cada una |
| [`PRODUCT.md`](PRODUCT.md) | Para quién es el panel de administración |
| [`CLAUDE.md`](CLAUDE.md) | Reglas de trabajo del proyecto y guía del entorno |

## Desarrollo

Este repositorio es el plugin y, alrededor, el entorno para desarrollarlo y probarlo. El entorno es un WordPress autocontenido con binarios portables (Apache con PHP 8.3, MariaDB, Mailpit, WP-CLI, Composer y el Chromium de Playwright) que se descarga solo en `.runtime/`.

**Requisitos**: Windows con PowerShell 7, Node.js, git y Python 3.10 o posterior.

```sh
git clone https://github.com/alvarofontela/formulario-mensaje.git
cd formulario-mensaje
./rt.sh setup      # instala y arranca todo, con las dependencias de Composer y npm
./rt.sh phpunit    # deben pasar
./rt.sh e2e
```

El WordPress local queda en `http://localhost:8088` y el correo que envía se captura en Mailpit (`http://127.0.0.1:8025`); ningún correo sale del equipo. La carpeta `plugin/` está enlazada dentro de ese WordPress, así que un cambio se ve en la siguiente petición.

Desde PowerShell, el mismo comando es `.\scripts\rt.ps1 <comando>`.

| Comando | Qué hace |
| --- | --- |
| `rt setup` · `start` · `stop` · `status` | Instala o repara el entorno y maneja sus servicios |
| `rt reset` | Devuelve WordPress al estado recién instalado |
| `rt lint` | PHPCS: estándares de WordPress y compatibilidad con PHP 8.1 |
| `rt phpunit` | Tests de integración |
| `rt e2e` | Tests de Playwright en un navegador real |
| `rt matrix` | Matriz de combinaciones de opciones, con varios navegadores a la vez |
| `rt check` | Plugin Check de WordPress.org sobre el plugin instalado y sobre el zip |
| `rt build` | Compila `src/` a `plugin/build/` con `@wordpress/scripts` |
| `rt i18n` | Regenera el `.pot`, lo fusiona en los `.po` y compila las traducciones |
| `rt package` | Crea el zip instalable en `dist/` |
| `rt wp <args>` | WP-CLI sobre el WordPress local |

La lista completa y los detalles están en [`CLAUDE.md`](CLAUDE.md).

### Estructura

| Ruta | Qué hay |
| --- | --- |
| `plugin/` | El plugin. `includes/` tiene una carpeta por módulo, cada una con su proveedor |
| `plugin/assets/` | CSS y JavaScript del formulario público, sin compilar |
| `plugin/build/` | Panel de administración y bloque ya compilados (se versionan) |
| `plugin/languages/` | Traducciones: un `.po` por idioma y sus compilados |
| `src/` | Fuentes en React del panel de administración y del bloque |
| `tests/` | PHPUnit (`phpunit/`), Playwright (`e2e/`) y la matriz de combinaciones (`matriz/`) |
| `scripts/` | El comando `rt` y sus herramientas |
| `docs/` | Documentación para quien extiende el plugin |
| `design/` | Maquetas de las que salió el diseño del panel |

### Cómo está hecho

- **SOLID y las prácticas de WordPress** como regla, no como preferencia: clases con una sola responsabilidad, dependencias por constructor y contra interfaces, y un contenedor propio y perezoso con un proveedor por módulo. Sin librerías de terceros en producción.
- **Extensible por registro**: un tipo de campo, una acción, un transporte de correo o un captcha nuevo es una clase más y una línea, no un cambio en las existentes.
- **Las API de WordPress antes que una solución propia**: REST API, Options API, HTTP API, `wp_mail`, `$wpdb` y WP-Cron.
- **Una tarea no está terminada** sin sus tests y sin pasar `rt lint`, `rt phpunit`, `rt e2e` y `rt check` con el log de WordPress limpio.

El proyecto se desarrolla con [Claude Code](https://claude.com/claude-code); `CLAUDE.md` y la carpeta `.claude/` recogen las reglas y las herramientas con las que trabaja.

### Traducciones

Los textos del código están en inglés de EE. UU., que es el idioma de origen. Las traducciones siguen el formato estándar de WordPress: se editan los `.po` de `plugin/languages/` (a mano o con Poedit) y `rt i18n` compila lo demás. Añadir un idioma es añadir su `.po`. Las variantes del español sin traducción propia (`es_MX`, `es_AR`…) usan la de `es_ES`.

## Estado del proyecto

Todo lo que el plugin hace está probado en el entorno local, con dobles para los servicios externos. Antes de darlo por estable conviene comprobar en una web real:

- El correo con Resend y con un servidor SMTP con cifrado y autenticación.
- Los tres captchas con sus scripts reales.
- Los webhooks contra un servicio de verdad (n8n, un CRM).
- La subida de ficheros en nginx, donde hace falta una regla propia para proteger la carpeta privada.
- Elementor Pro, otros temas y la convivencia con otros plugins.

El detalle está en [`PLAN.md`](PLAN.md). Fuera del alcance, por decisión: multisitio, integración con WPML o Polylang, el widget atómico de Elementor, reCAPTCHA v2, la autorespuesta al visitante y las restricciones de acceso al formulario (fechas, cupo o solo usuarios conectados).

## Licencia

[GPL-2.0-or-later](LICENSE), como WordPress.
