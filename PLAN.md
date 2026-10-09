# Plan funcional de formulario-mensaje

Fichero de contexto con las decisiones de producto y técnicas tomadas con el usuario el 2026-10-08 (tres tandas de preguntas) y el reparto en fases. Las reglas de trabajo (tests, lint, seguridad, idioma, git, SOLID y buenas prácticas de WordPress) están en `CLAUDE.md`; aquí solo se desarrolla la última, por su peso en la arquitectura.

**Estado: en desarrollo.** El 2026-10-09 el usuario pidió desarrollar las 11 fases de principio a fin y de forma desatendida, con un commit local por fase. El avance y lo decidido en cada fase está en «Estado del desarrollo», al final.

## Qué es

Un constructor de formularios para WordPress, ligero y auditable, para webs propias y de clientes. Motivos para hacerlo propio, por orden de peso en las decisiones:

1. Ligereza y rendimiento: nada de CSS ni JS en páginas sin formulario, sin dependencias pesadas, sin avisos ni upsells.
2. Seguridad y antispam bajo control.
3. Integraciones propias (webhooks hacia n8n, CRM, etc.).

## Cómo se desarrolla: SOLID y buenas prácticas de WordPress

**Condición de todo el plan, marcada por el usuario como muy importante.** Todas las fases se desarrollan siguiendo los principios SOLID y las buenas prácticas de desarrollo de plugins para WordPress con PHP. La regla completa está en `CLAUDE.md` (regla 8) y forma parte de lo que hace falta para dar una fase por terminada. Aquí se recoge cómo se traduce en la arquitectura del plan.

| Principio | Cómo se concreta en este plugin |
| --- | --- |
| Responsabilidad única | Piezas separadas para definición del formulario, render, validación, antispam, almacenamiento, correo, webhooks y cola. Los controladores REST, los callbacks de hooks, el shortcode, el bloque y el widget de Elementor solo adaptan la entrada y delegan en esos servicios |
| Abierto/cerrado | Tipos de campo, acciones, proveedores de captcha y transportes de correo son clases que se registran. El plugin crece añadiendo clases, no editando las existentes; los registros son los mismos que usarán terceros |
| Sustitución de Liskov | `wp_mail`, SMTP y Resend cumplen un mismo contrato de transporte; Turnstile, reCAPTCHA y hCaptcha, uno de verificación. Quien los usa no distingue cuál tiene delante |
| Segregación de interfaces | Contratos pequeños: un tipo de campo que no guarda datos (título, separador) no está obligado a implementar validación ni almacenamiento |
| Inversión de dependencias | Dependencias inyectadas por constructor y un único punto de composición que construye los objetos y registra los hooks. Base de datos, correo y peticiones salientes van detrás de interfaces propias, lo que permite probar la lógica sin WordPress de por medio |

Buenas prácticas de WordPress que condicionan el diseño desde la fase 1: uso de las APIs del núcleo antes que soluciones propias, integración solo mediante hooks, nada ejecutado al cargar un fichero, scripts y estilos encolados solo donde se usan, activación, desactivación y desinstalación bien separadas, esquema de base de datos versionado con migraciones repetibles, prefijos y namespace en todo, y código PHP tipado.

Las abstracciones se crean donde el plan ya prevé variación (las de la tabla), no por si acaso: la ligereza es el primer motivo del plugin.

## Decisiones de producto

### Formularios y constructor

| Tema | Decisión |
| --- | --- |
| Alcance | Varios formularios, cada uno con sus campos, acciones, mensajes y estilo |
| Dónde se construyen | Pantalla propia del plugin: app en React con `@wordpress/components` y vista previa en vivo |
| Funciones del constructor | Ordenar arrastrando, columnas y anchos, lógica condicional, formularios por pasos, plantillas de partida, duplicar, exportar e importar en JSON |
| Cómo se insertan | Bloque de Gutenberg, shortcode y widget de Elementor (versión gratuita); los tres eligen un formulario ya creado |
| Estilo | Diseñador visual por formulario (colores, bordes, espaciados, anchos de columna) |
| Estilo en el bloque y en Elementor | Ambos añaden sus controles nativos (colores, tipografía, espaciado, bordes), que sobrescriben el estilo del formulario solo en esa página |
| Idiomas | La interfaz del plugin es traducible (incluye `es_ES`); en webs multilingües, un formulario por idioma. Sin integración con WPML ni Polylang |
| Permisos | Capabilities propias y separadas: gestionar formularios, ver envíos, cambiar ajustes. Por defecto las tiene el administrador |
| Acceso al formulario | Sin restricciones: un formulario publicado acepta envíos siempre y de cualquiera. Sin fechas de apertura, cupo ni restricción a usuarios conectados |
| Borrado de un formulario | Va a la papelera y deja de aceptar envíos; sus envíos se conservan. Al vaciar la papelera se pregunta si borrar también envíos y ficheros |
| Exportación a JSON | Viaja todo (campos, estilo, mensajes, notificaciones con destinatarios, webhooks con URL y mapeo) menos los secretos. Al importar, las acciones quedan desactivadas hasta revisarlas |
| Multisitio | No soportado. El plugin impide activarse para toda la red; dentro de una red se puede activar sitio a sitio, pero ese uso no se prueba ni se garantiza |

### Campos

- Básicos: texto, email, teléfono, área de texto, desplegable, radio, casillas.
- Número, fecha y URL.
- Casilla de consentimiento con texto y enlace a la política de privacidad; se registra cuándo se aceptó.
- Campos ocultos y de contexto: página de origen, parámetros UTM, valor fijo.
- Elementos de presentación, que no recogen datos: título de sección, párrafo con formato básico y separador.
- Subida de ficheros (detalle más abajo).

Opciones comunes de cada campo: etiqueta, obligatorio, placeholder, texto de ayuda, clase CSS propia, atributo `autocomplete` y prellenado (valor fijo, parámetro de la URL o dato del usuario conectado).

Validación: la básica de cada tipo (obligatorio y formato) y siempre en servidor. Quedan fuera las reglas avanzadas configurables (patrones, longitudes y rangos a medida, mensajes de error por campo); el servidor aplica igualmente topes de longitud por seguridad.

### Subida de ficheros

- Cada campo elige los tipos permitidos entre grupos cerrados (imágenes, PDF, documentos de oficina). Sin extensiones libres, y nunca ejecutables, SVG ni HTML. Se comprueba el contenido real, no solo la extensión.
- Varios ficheros por campo, con máximo de unidades y de tamaño total.
- Cada campo decide el destino: adjunto al correo, guardado, o ambas cosas.
- Los ficheros guardados no son accesibles por URL y se descargan desde la administración con la capability de ver envíos. Cada campo puede activar además un enlace firmado y con caducidad en la notificación.

### Envío y antispam

| Tema | Decisión |
| --- | --- |
| Envío en el frontend | Por REST sin recargar, con errores junto a cada campo; si no hay JavaScript, funciona como POST normal |
| Antispam de serie | Honeypot, tiempo mínimo de relleno y límite de envíos por IP. Sin listas de palabras, límite de enlaces ni contador de bloqueos |
| Captcha opcional | Cloudflare Turnstile, reCAPTCHA v3 (umbral configurable) y hCaptcha; el script se carga solo donde se usa |
| Envíos rechazados | Se descartan; no hay carpeta de spam |

### Tras un envío correcto

Configurable por formulario:

- Mensaje de éxito, que puede incluir etiquetas con valores del envío y un número de referencia.
- Ocultar el formulario dejando solo el mensaje, o vaciarlo para permitir otro envío.
- Redirección a una URL, con la posibilidad de añadirle valores de campos elegidos expresamente. El destino sale siempre de la configuración guardada, nunca de la petición. Las URL del propio sitio valen siempre; un dominio externo solo si está en la lista de dominios permitidos de los ajustes, que gestiona quien tiene la capability de ajustes.
- Evento de JavaScript y push a `dataLayer` para medir conversiones, sin datos personales.

### Acciones de cada envío

Cada formulario activa las que quiera: notificaciones por correo, guardado en base de datos y webhooks. Las notificaciones y los webhooks son listas, y cada elemento puede tener su condición de disparo.

**Correo**

- Varias notificaciones independientes por formulario, cada una con sus destinatarios, asunto, cuerpo con etiquetas de los campos y condición. El «destinatario según respuesta» se resuelve con esas condiciones.
- Las direcciones de destino salen siempre de la configuración; el campo de email del visitante solo va en `Reply-To`. No hay autorespuesta al visitante.
- Plantilla HTML con marca (logotipo, color, pie) y versión en texto plano.
- Transporte opcional por SMTP o por la API de Resend, **solo para los correos del plugin**; el resto del correo del sitio no se toca.

**Guardado**

- Lista de envíos con búsqueda y filtros por formulario y «con errores», y vista de detalle.
- Cada envío guarda las etiquetas de los campos tal como eran, para seguir siendo legible aunque el formulario cambie.
- Metadatos: siempre fecha, formulario y URL de la página. La IP y el navegador solo si el formulario lo activa, con opción de IP anonimizada.
- Exportación a CSV con filtro de fechas.
- Borrado manual de uno o varios envíos, con sus ficheros y su registro de entregas.
- Retención automática: borrado pasados N días, configurable por formulario.
- Reintentar desde el detalle el correo o el webhook que falló.

**Webhook**

- Varios por formulario.
- Método y cabeceras configurables, y mapeo de campos a las claves que espera el destino.
- Firma HMAC con secreto por webhook.
- Envío en segundo plano, reintentos con espera creciente y registro de entregas consultable, que se purga pasados los días que se configuren.

### Diagnóstico y avisos

- Botón de prueba en el constructor: correo de prueba y webhook con datos de ejemplo, mostrando la respuesta del destino. Sirve también para comprobar SMTP o Resend.
- Contador de acciones fallidas en el menú del plugin.
- Correo al administrador del sitio cuando un webhook agota sus reintentos o las notificaciones fallan repetidamente, agrupado para no inundar el buzón.

### Privacidad y datos

- Borrado manual y retención automática (ver «Guardado»).
- Ajuste, desactivado por defecto, para borrar formularios, envíos, ficheros y opciones al desinstalar.
- Los envíos parciales de formularios por pasos no se guardan.

### Extensión

- Hooks (acciones y filtros) documentados en los puntos clave: validación, antes y después de guardar, contenido del correo, carga útil del webhook.
- Registro de tipos de campo y de acciones, para que otro plugin añada los suyos.
- Comandos de WP-CLI: listar, exportar e importar formularios, exportar envíos y procesar la cola.
- Abilities API: operaciones como listar formularios o leer envíos, con sus permisos, para agentes y clientes MCP. Cada ability llama al mismo servicio que su ruta REST, y una misma operación no se expone a un cliente MCP por las dos vías.

## Decisiones técnicas

| Tema | Decisión |
| --- | --- |
| JavaScript del frontend | Módulo propio sin dependencias, cargado solo en páginas con formulario e igual en bloque, shortcode y Elementor. No se usa la Interactivity API |
| Caché de página completa | El HTML no lleva nada que caduque. El token antispam (marca de tiempo firmada) lo pide el script por REST cuando el visitante enfoca el primer campo. El envío público no depende de un nonce |
| Respaldo sin JavaScript | Sin token: se aplican honeypot y límite por IP |
| IP del cliente | `REMOTE_ADDR` por defecto. Un ajuste de proxy de confianza permite «Cloudflare» (su cabecera solo se acepta si la petición llega desde sus rangos) o las IP de un proxy propio |
| Secretos | Clave de Resend, contraseña SMTP, claves de captcha y secretos de webhook se guardan cifrados con una clave derivada de las salts de `wp-config.php`, no se devuelven al navegador una vez guardados y pueden definirse como constantes, que tienen prioridad |
| Segundo plano | Cola propia en una tabla, procesada por WP-Cron, con un primer intento al terminar la petición del visitante. Sin Action Scheduler. Se recomienda un cron real del sistema; `wp` puede procesar la cola |
| Lógica condicional | Un único motor de reglas para campos, pasos y acciones, con la misma evaluación en servidor y en el navegador |
| Arquitectura del núcleo | Tipos de campo y acciones como registros extensibles desde la fase 1; los propios del plugin se registran por la misma vía que los de terceros |
| Pasos | Cada paso se valida antes de avanzar (en el navegador y contra el servidor); los datos se guardan y las acciones se disparan solo al completar el último |
| Carga de clases | Convenciones del estándar de WordPress al completo, sin excepciones en `phpcs.xml.dist`: namespace `Formulario_Mensaje`, nombres con guiones bajos (`Smtp_Transport`), un tipo por fichero y ficheros `class-`, `interface-`, `trait-` y `enum-`. Un autoloader propio y pequeño traduce el namespace a carpetas y el nombre al fichero. Sin PSR-4 y sin Composer en producción |
| Composición | Contenedor propio, mínimo y perezoso, con un proveedor por módulo que declara servicios y registra hooks; los servicios se construyen cuando un hook los necesita. No es un localizador de servicios: solo lo conocen el arranque y los proveedores. Sin librerías de inyección ni `psr/container` |
| Clases que no construimos | Un único acceso estático al contenedor, reservado a los adaptadores que instancia otro (el widget de Elementor). Es la única excepción al «sin estado estático» |
| Adaptadores | REST, shortcode, bloque, widget, WP-CLI y abilities son adaptadores finos sobre los mismos servicios. Una ability llama al servicio, no a la ruta REST |
| Ciclo de vida | Hooks de activación y desactivación registrados en el nivel superior del fichero principal; `uninstall.php` con su propia composición mínima |
| Widget de Elementor | Widget clásico (`Widget_Base`, registrado en `elementor/widgets/register`), con controles de estilo que generan CSS por selectores y el mismo render en PHP que el bloque y el shortcode. Compatible con Elementor 3 reciente y 4. El widget atómico del editor V4 queda descartado: solo existe en Elementor 4 y obligaría a duplicar el render en Twig |

### Carga de clases y composición: en qué se apoyan

Las dos decisiones se revisaron el 2026-10-08 contra las skills de WordPress del proyecto, Context7 y una prueba con PHPCS. Se mantienen las dos, con ajustes:

- **Nombres de WordPress, confirmado.** El manual de estándares de código pide `class-` en los ficheros de clase, nombres con guiones bajos, un tipo por fichero y namespaces con palabras separadas por guiones bajos. PSR-4 exigiría desactivar parte del estándar que la regla 4 de `CLAUDE.md` manda cumplir.
- **Cambio derivado: el namespace pasa de `FormularioMensaje` a `Formulario_Mensaje`**, que es la forma que pide el manual. Actualizado en `CLAUDE.md` y en `phpcs.xml.dist`, que ahora rechaza la forma antigua.
- **Los prefijos `interface-`, `trait-` y `enum-` son convención de este proyecto**: el estándar solo fija `class-` y el formato en minúsculas con guiones. Comprobado que `rt lint` los acepta.
- **Contenedor propio, confirmado y precisado.** La skill `wp-plugin-development` pide un arranque único, nada pesado al cargar ficheros, una clase dedicada a registrar hooks y el código de administración tras hooks de administración: de ahí que el contenedor sea perezoso y los hooks los registren los proveedores. También pide registrar activación y desactivación en el nivel superior.
- **Excepción estática, añadida.** Elementor crea las instancias del widget por su cuenta (comprobado en el código de la versión 4.3.4 instalada), así que ese adaptador no puede recibir dependencias por constructor.
- **Adaptadores sobre servicios, añadido.** La skill `wp-abilities-api` establece como forma por defecto un servicio compartido que consumen la ability, el controlador REST y la interfaz, para que no diverjan.

### Supuestos que he tomado sin preguntar

Corrígelos si no encajan:

1. **Almacenamiento**: formularios como tipo de contenido privado con su definición en JSON (da gratis papelera, duplicado y exportación); envíos, cola y registro de entregas en tablas propias.
2. **Accesibilidad**: objetivo WCAG 2.2 AA en el formulario del frontend (etiquetas asociadas, errores anunciados, foco gestionado, uso completo con teclado).
3. **Sin JavaScript**, los formularios condicionales muestran todos los campos y el servidor aplica las reglas; los formularios por pasos se muestran en una sola página con los pasos como secciones.
4. **Consentimiento**: junto a la fecha se guarda el texto que se aceptó.
5. **Retención**: borra también los ficheros del envío.

## Fuera del alcance

- Autorespuesta al visitante.
- Estados de los envíos (leído / no leído) y carpeta de spam.
- Integración con las herramientas de privacidad de WordPress (exportar y borrar datos personales por email).
- Captura de envíos parciales en formularios por pasos.
- Restricciones de acceso al formulario (fechas, cupo, solo usuarios conectados).
- Validación avanzada configurable por campo.
- reCAPTCHA v2.
- Integración con WPML y Polylang.
- Compatibilidad con WordPress multisitio.
- Widget atómico de Elementor (editor V4).

## Puntos abiertos

1. **Actualizaciones** en las webs instaladas: el usuario lo mantiene aplazado (preguntado dos veces el 2026-10-08). Opciones vistas: servidor de actualizaciones propio, releases de un GitHub privado o ningún actualizador en el plugin. Mientras tanto, el plugin solo cuida las migraciones de datos entre versiones.
2. **Versión mínima de Elementor 3**: se fija en la fase 7, según la API que use el widget.

## Fases

Cada fase deja el plugin instalable y termina según la regla 3 de `CLAUDE.md`: `rt lint`, `rt phpunit`, `rt e2e` y `rt logs wp` limpios, sin hallazgos de seguridad pendientes y con el código conforme a SOLID y a las buenas prácticas de WordPress (regla 8). Cuando una fase abre una superficie nueva, actualiza `.claude/claude-security-guidance.md` (regla 7). Los hooks de cada pieza se añaden y documentan en la fase que la construye.

| Fase | Contenido | Superficie nueva |
| --- | --- | --- |
| 1. Núcleo | Esqueleto del plugin con autoloader y contenedor propios, capabilities, bloqueo de la activación en red, modelo de formulario y registros de campos y acciones. Campos básicos, número, fecha, URL, consentimiento, ocultos y elementos de presentación, con sus opciones comunes y prellenado. Render en servidor accesible, shortcode y bloque selector. Envío por REST con respaldo sin JS, token antispam al interactuar, honeypot, límite por IP y ajuste de proxy de confianza. Lista de dominios permitidos para la redirección. Una notificación en texto plano. Todo lo de «tras un envío correcto». Constructor con ordenación, ajustes por campo y vista previa en vivo; papelera | Ruta REST pública de envío y de token; rutas REST de administración; redirección con destino variable |
| 2. Envíos y privacidad | Guardado en base de datos con metadatos configurables, lista, detalle, filtro «con errores», exportación a CSV, borrado manual, retención automática, borrado al desinstalar, registro de fallos de correo, contador de fallos y reintento de acciones | Lectura de datos personales; exportación |
| 3. Correo avanzado | Varias notificaciones con condición (motor de reglas en servidor), plantilla HTML con marca, cifrado de secretos, transporte por SMTP o Resend, correo de prueba | Credenciales guardadas; peticiones salientes a Resend |
| 4. Webhooks | Cola en segundo plano, varios webhooks con condición, método, cabeceras, mapeo, firma HMAC, reintentos, registro de entregas con purga, webhook de prueba y aviso por correo al administrador | Peticiones salientes a URL configurable (SSRF) |
| 5. Captchas | Turnstile, reCAPTCHA v3 y hCaptcha | Scripts de terceros; verificación saliente |
| 6. Constructor avanzado | Columnas y anchos, plantillas de partida, duplicar, exportar e importar en JSON sin secretos | Importación de JSON (entrada no fiable) |
| 7. Estilo, bloque y Elementor | Diseñador de estilos por formulario; controles de estilo en el bloque; widget clásico de Elementor con sus controles, probado en Elementor 3 y 4 | Integración con Elementor |
| 8. Lógica condicional en campos | Interfaz de reglas en el constructor y evaluación en el navegador, con el mismo motor que ya usa el servidor | — |
| 9. Formularios por pasos | Pasos con barra de progreso, validación por paso y pasos condicionales | — |
| 10. Ficheros | Campo de subida con tipos cerrados y varios ficheros, adjunto al correo, almacenamiento protegido, descarga con sesión y enlace firmado opcional | Subida de ficheros; descarga por enlace firmado |
| 11. Extensión | Comandos de WP-CLI, Abilities API y documentación de hooks y de los registros de campos y acciones | Abilities expuestas por REST |

El orden pone antes lo que da valor en una web real (núcleo, envíos, correo, webhooks) y deja para el final lo más delicado (lógica condicional en el navegador, pasos y ficheros), cuando el modelo de datos ya esté asentado. Se puede reordenar.

## Entorno de pruebas

- El usuario ha dado libertad total para adaptar el WordPress de `.runtime` e instalar en él los plugins que hagan falta.
- Elementor 4.3.4 (gratuito) está instalado e inactivo desde el 2026-10-08, y la instalación con `rt wp plugin install` funciona pese al límite de rutas de Windows. No se ha comprobado si sobrevive a `rt reset` o a `rt setup`; si no, se incorporará a la instalación del entorno en la fase 7.
- El constructor en React se compila con `@wordpress/scripts` (`rt build`), añadido en la fase 1.
- Para ver el plugin en el navegador se cuenta con los tests de Playwright (`rt e2e`) y con la extensión de Chrome de Claude. El usuario mencionó un MCP de Playwright: en la sesión del 2026-10-08 no había ninguno conectado.

## Estado del desarrollo

Decisiones del 2026-10-09 para el desarrollo desatendido: un commit local por fase (sin push); verificación en navegador con el Playwright del proyecto y Claude for Chrome; servicios externos con dobles locales; `plugin/build/` versionado; estilo base neutro que hereda del tema; si una pieza se resiste, se acota, se anota aquí y se sigue. Las actualizaciones del plugin siguen aplazadas.

| Fase | Estado |
| --- | --- |
| 1. Núcleo | Hecha |
| 2. Envíos y privacidad | Hecha |
| 3. Correo avanzado | Hecha |
| 4. Webhooks | Pendiente |
| 5. Captchas | Pendiente |
| 6. Constructor avanzado | Pendiente |
| 7. Estilo, bloque y Elementor | Pendiente |
| 8. Lógica condicional en campos | Pendiente |
| 9. Formularios por pasos | Pendiente |
| 10. Ficheros | Pendiente |
| 11. Extensión | Pendiente |

### Fase 1: lo que se decidió al hacerla

- **Definición del formulario**: JSON en el metadato `_formulario_mensaje_definition` del tipo de contenido `formulario_mensaje` (el nombre admite 20 caracteres como máximo). No en el contenido, que WordPress filtra con kses según quién guarde. Forma: `{ version, fields: [{ id, type, settings }], settings, actions: [{ id, type, enabled, settings }] }`. Las acciones son una lista desde el principio; el constructor edita de momento la primera notificación.
- **Opciones de los campos por esquema**: cada tipo declara sus opciones (`get_settings_schema()`); con ese esquema se sanean al guardar y se pinta el panel del constructor. Un tipo de campo nuevo, propio o de terceros, no necesita JavaScript.
- **Etiquetas** en asunto, cuerpo y mensaje de éxito: `{field:ID}`, `{all_fields}`, `{reference}`, `{form_title}`, `{site_name}`, `{page_url}` y `{date}`. Se sustituyen en una sola pasada.
- **Número de referencia**: aleatorio, con el formato `XXXX-XXXX`. Se genera ya en esta fase y va en la respuesta y en el correo; la fase 2 lo guarda con el envío.
- **Sin JavaScript**: el POST va a `admin-post.php`. Si es correcto, se redirige de vuelta a la página con el identificador del formulario y la referencia en la URL (nunca datos del envío, así que las etiquetas de campos quedan vacías en ese mensaje). Si no, una página de error con los fallos y un enlace para volver, que conserva lo escrito en el navegador. No se guarda nada en sesión ni en transients.
- **Con JavaScript** el formulario lleva `novalidate`: valida el servidor y los errores se muestran junto a cada campo, con el foco en el primero. Sin JavaScript actúa la validación nativa del navegador.
- **Si todas las acciones de un formulario fallan**, el visitante recibe un error: mientras no se guarden los envíos (fase 2), aceptar el envío sería perderlo.
- **Límite por IP**: 5 envíos aceptados cada 10 minutos por formulario e IP (filtro `formulario_mensaje_rate_limit`). **Tiempo mínimo de relleno**: 3 segundos desde la primera interacción (filtro `formulario_mensaje_min_fill_seconds`); el script espera lo que falte antes de enviar.
- **Bloque**: quien edita contenidos sin la capability de gestionar formularios puede listar los publicados (solo nombre e identificador) para elegirlos.
- **Papelera**: WordPress vacía la papelera de cualquier tipo de contenido a los 30 días (`EMPTY_TRASH_DAYS`); los formularios siguen esa regla.
- **Desinstalar** retira las capabilities y la marca de versión; formularios y ajustes se conservan hasta que la fase 2 añada el ajuste de borrado.
- **Hooks disponibles**: acciones `formulario_mensaje_register_field_types`, `formulario_mensaje_register_action_types`, `formulario_mensaje_before_actions` y `formulario_mensaje_submission_completed`; filtros `formulario_mensaje_validation_errors`, `formulario_mensaje_email_message`, `formulario_mensaje_rate_limit`, `formulario_mensaje_min_fill_seconds`, `formulario_mensaje_token_max_age` y `formulario_mensaje_cloudflare_ip_ranges`.

Excepciones a la regla 8 que conviene conocer: el saneado por esquema y el panel del constructor distinguen los tipos de control (`text`, `toggle`, `select`…) con un `switch`. Es el vocabulario cerrado del propio esquema, no un punto de extensión; lo que sí crece (tipos de campo y de acción) va por registro.

- **Estilos**: si el contenido de la entrada o página lleva el shortcode o el bloque, el CSS se encola en la cabecera; si el formulario llega por otra vía (una plantilla, un widget), se encola al pintarlo.

### Fase 2: lo que se decidió al hacerla

- **El guardado no es una acción más, sino un paso propio del envío** (`Submission_Journal`). Se guarda antes de ejecutar las acciones, y el identificador del envío guardado es lo que permite anotar el resultado de cada acción y reintentarla. Se configura por formulario en la pestaña «Guardado» y está activado por defecto.
- **Tres tablas propias**, con esquema versionado (`Schema`, opción `formulario_mensaje_db_version`): `…_submissions` (envíos, con sus datos en JSON y un texto de búsqueda), `…_deliveries` (registro de entregas: una fila por acción ejecutada sobre un envío, con estado, intentos y error) y `…_rate_limits` (contadores del límite por IP).
- **Si el envío se guarda, el fallo de una notificación ya no es un error para el visitante**: queda como entrega fallida, se cuenta en el menú y se puede reintentar. Solo recibe un error si ni se guardó ni se entregó nada.
- **Entregas fallidas de formularios que no guardan sus envíos**: se anotan (sin datos del envío) para que el aviso del menú funcione; no se pueden reintentar y se purgan a los 30 días.
- **Reintentar usa la configuración actual de la acción**, no la que había cuando falló: corregir un destinatario y reintentar entrega el envío.
- **Exportación a CSV**: de un formulario cada vez, con rango de fechas opcional. Las columnas son la unión de los campos de los envíos exportados. Se descarga por `admin-post.php` con capability y nonce, escribiendo fila a fila.
- **Retención**: tarea diaria de WP-Cron (`formulario_mensaje_daily_maintenance`), con botón «Ejecutar la limpieza ahora» en los ajustes. Un formulario en la papelera mantiene su retención.
- **Borrar un formulario definitivamente** conserva sus envíos salvo que se marque la casilla para borrarlos; quedan en la pantalla de envíos como «(formulario borrado)».
- **Desinstalar** borra formularios, tablas y opciones solo con el ajuste activado; si no, retira capabilities y la tarea programada.
- **Pantallas y permisos**: «Envíos» exige `formulario_mensaje_view_submissions`, que es independiente de gestionar formularios. El menú aparece para quien tenga cualquiera de las tres capabilities.
- **Hooks nuevos**: acción `formulario_mensaje_submissions_deleted` (identificadores borrados) y `formulario_mensaje_daily_maintenance`; el filtro `formulario_mensaje_rate_limit` recibe además la vía del envío.

### Fase 3: lo que se decidió al hacerla

- **Varias notificaciones por formulario.** La pestaña pasa a llamarse «Notificaciones»; cada una tiene nombre, interruptor, destinatarios, formato y condición, y se puede duplicar. Así se resuelve «a quién se avisa según lo que se responda»: una notificación por destinatario, cada una con su condición.
- **Motor de reglas** (`Rules`): una condición es `{ match: all|any, rules: [{ field, operator, value }] }`. Operadores: es, no es, contiene, no contiene, está vacío, no está vacío, mayor que y menor que. Compara sin distinguir mayúsculas; en los campos con varios valores, «es» significa «alguno es». Una condición sin reglas se cumple siempre y un campo que ya no existe cuenta como vacío. Lo usan las acciones; las fases 8 y 9 lo reutilizan para campos y pasos, con el mismo editor (`src/admin/condition-editor.js`).
- **Una acción cuya condición no se cumple no se ejecuta ni se anota** en el registro de entregas: no es un fallo.
- **Formato del correo**: HTML con la marca del sitio (por defecto en las notificaciones nuevas) o texto plano. El HTML siempre lleva su versión en texto. El cuerpo sigue escribiéndose como texto con etiquetas: en HTML se escapa, se respetan los saltos de línea y `{all_fields}` se pinta como una tabla. No hay editor visual del cuerpo.
- **Marca**: logotipo (URL o biblioteca de medios), color y texto del pie, en Ajustes → Correo electrónico. Sin logotipo se muestra el nombre del sitio.
- **Transportes**: `wp_mail()` (por defecto; respeta a cualquier plugin de SMTP del sitio), servidor SMTP propio y Resend. El SMTP usa una instancia propia del PHPMailer de WordPress, así que no cambia el correo del resto del sitio ni otro plugin cambia el suyo. Añadir un transporte es una clase y una línea en `Mail_Provider`.
- **Remitente**: dirección y nombre opcionales. Sin dirección, `wp_mail` usa la del sitio y el SMTP la misma que usaría WordPress (filtro `wp_mail_from`); Resend exige indicarla, porque solo envía desde dominios verificados.
- **Secretos** (`Secrets`): la contraseña de SMTP y la clave de Resend se cifran con `sodium_crypto_secretbox` y una clave derivada de las salts de WordPress, en la opción `formulario_mensaje_secrets` (sin carga automática). Una constante `FORMULARIO_MENSAJE_SMTP_PASSWORD` o `FORMULARIO_MENSAJE_RESEND_API_KEY` en `wp-config.php` tiene prioridad y bloquea el campo. La REST API nunca devuelve un secreto: solo si está definido y de dónde sale. Si cambian las salts, los secretos guardados dejan de poder leerse y hay que volver a escribirlos.
- **Las credenciales se borran siempre al desinstalar**, aunque el ajuste de borrar datos esté desactivado.
- **Correos de prueba**: uno genérico desde los ajustes, con los ajustes guardados, que muestra el error del transporte si falla; y el de cada notificación desde el constructor, con datos de ejemplo que aporta cada tipo de campo (`sample_value()`), sin necesidad de guardar el formulario y solo a la dirección que se indique.
- **Hooks**: el filtro `formulario_mensaje_email_message` recibe ahora un mensaje que puede llevar HTML.

No verificado contra servicios reales: Resend se ha probado con un doble de la HTTP API (petición, cabecera de autorización, errores) y el SMTP contra Mailpit, que no cifra ni pide credenciales. Quedan sin probar STARTTLS, SSL y la autenticación contra un servidor real.

Cambios sobre la fase 2, a raíz de la revisión de seguridad de su commit:

- **Borrar los envíos junto con un formulario** exige también el permiso de ver envíos; sin él la petición se rechaza entera, y los envíos se borran antes que el formulario.
- **Reintentar una acción desactivada** se rechaza: desactivarla es la forma de parar una notificación.
- **Límite por IP con la base de datos caída**: en vez de dejar pasar, cuenta con un transient de respaldo.
- **Borrado de los envíos de un formulario** sin tope de lotes, para que no queden restos.

Cambios sobre la fase 1, a raíz de la revisión de seguridad de su commit:

- **Límite por IP atómico.** Antes se comprobaba al principio y se contaba al final, con un transient: envíos simultáneos podían pasar todos la comprobación. Ahora el hueco se reserva al comprobar con una sola sentencia SQL y se devuelve si el envío no se acepta (errores de validación, fallo total).
- **Envío sin JavaScript.** Sigue sin token, como se decidió, pero con un límite propio más estricto (2 cada 10 minutos frente a 5) y un ajuste para desactivarlo («Aceptar envíos sin JavaScript»).
