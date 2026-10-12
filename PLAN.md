# Plan funcional de formulario-mensaje

Fichero de contexto con las decisiones de producto y técnicas tomadas con el usuario el 2026-10-08 (tres tandas de preguntas) y el reparto en fases. Las reglas de trabajo (tests, lint, seguridad, idioma, git, SOLID y buenas prácticas de WordPress) están en `CLAUDE.md`; aquí solo se desarrolla la última, por su peso en la arquitectura.

**Estado: las 11 fases están hechas** (2026-10-09), cada una en su commit local y sin push. El usuario pidió desarrollarlas de principio a fin y de forma desatendida, para afinar después el resultado; lo que queda por comprobar fuera del entorno local está en «Pendiente de verificar fuera del entorno». El avance y lo decidido en cada fase está en «Estado del desarrollo», al final.

## Qué es

Un constructor de formularios para WordPress, ligero y auditable, para webs propias y de clientes. Desde el 2026-10-12 se prepara además para publicarse en el directorio de WordPress.org. Motivos para hacerlo propio, por orden de peso en las decisiones:

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
| Idiomas | La interfaz del plugin es traducible con los ficheros estándar de WordPress (`.po` y `.mo`). El idioma de origen es el inglés de EE. UU. y la primera traducción, `es_ES`, que también ven las demás variantes del español. En webs multilingües, un formulario por idioma. Sin integración con WPML ni Polylang |
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

1. **Actualizaciones** en las webs instaladas: estuvo aplazado (preguntado dos veces el 2026-10-08, con un servidor propio o releases de un GitHub privado como opciones). El 2026-10-12 el usuario decidió preparar el plugin para el directorio de WordPress.org, que las resuelve cuando esté aprobado; el plugin no lleva ni debe llevar actualizador propio (el directorio lo prohíbe).
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
- Elementor 4.3.4 (gratuito) está instalado e inactivo desde el 2026-10-08, y la instalación con `rt wp plugin install` funciona pese al límite de rutas de Windows. No se ha comprobado si sobrevive a `rt reset` o a `rt setup`: si falta, `tests/e2e/estilo.spec.ts` falla al activarlo y hay que reinstalarlo con `rt wp plugin install elementor --version=4.3.4`.
- El constructor en React se compila con `@wordpress/scripts` (`rt build`), añadido en la fase 1.
- Para ver el plugin en el navegador se cuenta con los tests de Playwright (`rt e2e`) y con la extensión de Chrome de Claude. El usuario mencionó un MCP de Playwright: en la sesión del 2026-10-08 no había ninguno conectado.

## Estado del desarrollo

Decisiones del 2026-10-09 para el desarrollo desatendido: un commit local por fase (sin push); verificación en navegador con el Playwright del proyecto y Claude for Chrome; servicios externos con dobles locales; `plugin/build/` versionado; estilo base neutro que hereda del tema; si una pieza se resiste, se acota, se anota aquí y se sigue. Las actualizaciones del plugin siguen aplazadas.

| Fase | Estado |
| --- | --- |
| 1. Núcleo | Hecha |
| 2. Envíos y privacidad | Hecha |
| 3. Correo avanzado | Hecha |
| 4. Webhooks | Hecha |
| 5. Captchas | Hecha |
| 6. Constructor avanzado | Hecha |
| 7. Estilo, bloque y Elementor | Hecha |
| 8. Lógica condicional en campos | Hecha |
| 9. Formularios por pasos | Hecha |
| 10. Ficheros | Hecha |
| 11. Extensión | Hecha |

### Pendiente de verificar fuera del entorno

Todo lo siguiente está probado contra dobles locales o no se ha podido probar aquí. Conviene comprobarlo en una web real antes de dar el plugin por estable:

- **Correo**: Resend con una clave real, y SMTP con cifrado y autenticación (aquí solo contra Mailpit, sin cifrar).
- **Captchas**: reCAPTCHA v3 de principio a fin, y los scripts reales de los tres servicios en el navegador (la verificación en servidor de Turnstile y hCaptcha sí se probó con sus claves públicas de prueba).
- **Webhooks**: contra n8n o un CRM de verdad.
- **Ficheros**: en nginx (donde el `.htaccess` del almacén no se aplica y conviene una regla `deny`), y con documentos de oficina reales.
- **Elementor Pro** y sus contenedores con rejilla.
- **Abilities** con un cliente MCP real y el adaptador de MCP de WordPress.
- **Otros temas y plugins**: todo se ha probado con el tema por defecto y, salvo Elementor, sin más plugins activos.

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

### Correcciones de la auditoría de seguridad (2026-10-12)

La auditoría con la skill `security-audit` (informes en `~/security-audit-skill/formulario-mensaje/run-1/`) dejó ocho pistas pendientes de validar. Se corrigieron las ocho, con sus tests:

- **Retención.** Fijar o cambiar `retention_days` es mandar borrar envíos: exige `formulario_mensaje_view_submissions`, igual que el borrado explícito. Sin ella la petición que lo cambia se rechaza entera (`Form_Service`), el plazo de un formulario importado llega sin poner y el constructor desactiva el campo.
- **Credenciales.** La contraseña SMTP guardada se descarta antes de guardar un destino distinto, y si no se puede descartar el destino no cambia; lo mismo con la clave del captcha y su proveedor. `Secret_Vault::set()` dice si el secreto ha quedado como se pedía y no acepta más de 1000 bytes.
- **Webhooks.** El nombre del destino se resuelve una sola vez, se comprueba esa dirección y la conexión se obliga a ir a ella (`Destination_Guard`, con `CURLOPT_CONNECT_TO`, que no depende de cómo esté escrito el nombre). Sin cURL no se puede, y un webhook a un destino con nombre no se envía; si después no consta que la conexión fue a esa dirección, la respuesta se descarta con un error. Con un proxy de salida (`WP_PROXY_HOST`) la conexión se obliga igual: cURL le pide al proxy un túnel hacia la dirección comprobada, no hacia el nombre. Lo que no se puede confirmar es adónde se conectó el proxy, así que ahí de la respuesta solo se cuenta el código, nunca el cuerpo. Un servidor interno se sigue pudiendo permitir por su nombre con `http_request_host_is_external`.
- **Límite de envíos.** Cuenta por red (el /64 de una IPv6) y, si el servidor no entrega la IP del visitante, con un cupo común por formulario en vez de sin límite.
- **Almacén de ficheros.** Cada subcarpeta lleva su `index.html` vacío, no solo la raíz.
- **CSV.** Se neutraliza el signo de fórmula en cualquier sitio donde otra hoja de cálculo empezaría una celda: detrás de un punto y coma, una barra vertical, un tabulador o un salto de línea, aunque haya comillas o espacios entre medias. Una lista escrita con guiones en un texto de varias líneas sale por eso con un apóstrofo en cada línea.
- **Ubicaciones.** La búsqueda de dónde está cada formulario recorre el contenido una sola vez, sin expresiones regulares, y lee las entradas por tandas.

Quedan sin revisar las seis unidades que esa auditoría aplazó (validación con campos condicionales, coste del saneado en la ruta de validación, trabajo previo a la devolución del cupo, multisitio, envíos de formularios borrados y prellenado frente a cachés de página).

### Preparación para el directorio de WordPress.org (2026-10-12, versión 0.14.0)

El usuario pidió pasar Plugin Check y dejar el plugin listo para el directorio oficial, lo que cambia la decisión inicial de distribuirlo solo por zip. De paso resuelve el punto abierto de las actualizaciones: las webs instaladas se actualizarán desde el directorio.

Plugin Check (2.1.0, con las comprobaciones en ejecución y las experimentales) encontró cinco cosas:

- **Faltaba `readme.txt`.** Escrito en inglés, con los servicios externos documentados (Turnstile, hCaptcha, reCAPTCHA v3 y Resend: qué datos reciben, cuándo y sus condiciones), preguntas frecuentes y cómo compilar las fuentes.
- **Dos consultas llegaban a `get_results()` en una variable.** Estaban preparadas, pero por quien llamaba; ahora cada una se ejecuta donde se prepara.
- **Dos falsos positivos**, anotados con su motivo: la dirección de verificación de Turnstile, que la regla toma por un recurso servido desde fuera, y `load_plugin_textdomain()`, que se desaconseja en plugins del directorio pero aquí hace falta para que funcionen las traducciones que trae el plugin mientras no haya paquete de idioma en translate.wordpress.org.

Y lo que Plugin Check no mira pero la revisión manual sí:

- **Fuentes de lo compilado** (directriz 4): `rt package` añade `src/` y `webpack.config.js` al zip. Compilar desde lo que lleva el zip da ficheros idénticos a los de `build/`.
- **Rutas**: la carpeta de traducciones ya no se calcula con `WP_PLUGIN_DIR`.
- Repasado sin hallazgos: prefijos de funciones, opciones, hooks, tablas y scripts; ni telemetría ni conexiones que el administrador no haya configurado; sin enlaces de crédito ni avisos fuera de las pantallas del plugin; sin librerías duplicadas del núcleo (el SMTP usa el PHPMailer de WordPress); lo que se escribe en disco va a la carpeta de subidas. El slug `formulario-mensaje` estaba libre ese día.

`rt check` deja la comprobación repetible y `tests/phpunit/DirectorioTest.php` vigila que el readme coincida con la cabecera y documente cada servicio externo del código.

Pendiente, fuera del repositorio: el usuario de WordPress.org en `Contributors` (hay un marcador), el icono, la cabecera y las capturas para el SVN, y el propio envío. La revisión la hacen personas: Plugin Check limpio no garantiza que no pidan cambios.

### Traducciones en ficheros .po (2026-10-12, versión 0.14.0)

Las traducciones pasan al flujo estándar de WordPress. Decidido con el usuario ese día:

- **Los `.po` son la fuente.** Antes se escribían en `scripts/i18n/es_ES.json` y el `.po` era un fichero generado que no se podía tocar; ahora se edita el `.po`, a mano o con Poedit, y `rt i18n` hace lo demás: regenera la plantilla, la fusiona en cada `.po` (`wp i18n update-po`) y compila `.mo`, `.l10n.php` y `.json`. El JSON y su generador se han eliminado; la traducción de `es_ES` es la misma, texto a texto.
- **El inglés de EE. UU. es el idioma de origen**, no una traducción: son los textos del código y un sitio en inglés no carga ningún fichero. Se revisaron las 717 cadenas y se corrigieron 47: dos grafías británicas y frases calcadas del español («the one of the site»). También una referencia a una pestaña del constructor que ya no existe, en los dos idiomas.
- **El español de España sirve de reserva** a las variantes sin traducción propia (`es_MX`, `es_AR`…), que sin esto verían el plugin en inglés (`Locale_Fallback`, filtro `formulario_mensaje_locale_fallbacks`). Una traducción propia de la variante, la traiga el plugin o esté instalada en el sitio, tiene prioridad.
- Añadir un idioma es añadir su `.po`; no hay que tocar código.

Comprobado en el entorno con el sitio en `es_MX` (con su paquete de idioma de WordPress instalado para la prueba) y en `en_US`: el panel y los textos del servidor salen en español de España y en inglés. Sin verificar: la edición de los `.po` con Poedit o Loco Translate (el formato es el que escribe WP-CLI, pero no se ha abierto con esas herramientas).

### Rediseño del panel (2026-10-09, versión 0.13.0)

El panel se rehízo pensando en quien lo usa en el día a día: sobre todo clientes, no técnicos, que crean sus formularios y leen lo que les escriben, también desde el móvil (`PRODUCT.md`). Se compararon tres propuestas maquetadas (`design/propuestas-backend/`) y se eligió una cuarta, la D: la estructura de la «Bandeja» con el aspecto del propio WordPress.

- **Se entra por los mensajes.** El menú es Mensajes, Formularios y Ajustes, y su aviso cuenta los mensajes sin leer (antes, las entregas fallidas, que siguen viéndose en la bandeja y avisando por correo). La pantalla de envíos es ahora una bandeja: la lista a la izquierda y el mensaje abierto al lado, con «Responder» (abre el programa de correo del usuario; el plugin no envía nada) y «Llamar».
- **Leído y sin leer.** Columna `read_at` en los envíos (esquema 4). Un mensaje se marca al abrirlo y se puede volver a dejar pendiente. Al actualizar, los que ya había se dan por leídos.
- **Dónde está cada formulario.** `Form_Placements` busca el bloque, el shortcode y el widget de Elementor en el contenido publicado. Solo ve entradas y páginas: un formulario puesto en una plantilla del tema o en un widget no aparece, y por eso la interfaz dice «no se ha encontrado», no «no está».
- **Constructor en cuatro pasos**: Preguntas, Al recibirlo (guardado, notificaciones, lo que ve quien envía y webhooks, en apartados plegables con su resumen), Aspecto y Publicar (las tres formas de ponerlo en una página, sin la palabra «shortcode»). No obligan a ningún orden y el paso va en la URL.
- **Ajustes con un resumen de estado** encima de las pestañas de siempre, que quedan presentadas como «para quien mantiene la web».
- **Tableta y móvil.** Mismos puntos de corte que wp-admin. En el móvil, las secciones van en una barra inferior, la bandeja enseña la lista o el mensaje, la vista previa del constructor se abre con un botón y los controles miden 44 px.

Decisiones tomadas al implementarlo, distintas de la maqueta o no previstas en ella:

- El guardado del constructor sigue siendo con botón, como en el resto de WordPress, y no automático.
- Los tipos de campo conservan sus nombres («Texto», «Desplegable»): la maqueta los rebautizaba («Respuesta corta», «Elegir de una lista»), pero eso obligaba a cambiar también lo que ve quien extiende el plugin.
- La selección múltiple de mensajes (marcar como leídos, borrar) solo está en pantallas anchas; en el móvil se borra desde el mensaje abierto.
- Las entregas fallidas de formularios que no guardan sus envíos no tienen mensaje al que colgarse: siguen en su tabla, tras el botón «Entregas fallidas» de la bandeja, que solo aparece si hay alguna.
- «Quién escribe» es el primer dato de un campo de texto, su correo el primero de tipo correo y su teléfono el primero de tipo teléfono. Es una convención de presentación: un formulario sin campo de texto encabeza el mensaje con el correo o con la referencia.

Sin verificar: el comportamiento en un móvil o una tableta reales (se ha probado con Chromium a 390 y 820 px, sin gestos táctiles) y la detección de páginas con una instalación real de Elementor (se prueba con su meta, no editando una página en su editor).

### Revisión de todo el código (2026-10-09, versión 0.12.0)

Una revisión de todo `plugin/`, no solo de los últimos cambios, dejó diez hallazgos. Todos corregidos, cada uno con su test:

- **Ficheros que solo se adjuntan.** Se borraban al terminar el envío aunque la notificación que debía llevarlos hubiera fallado, y el reintento salía sin adjunto. Ahora esperan a que el envío no tenga entregas fallidas (acción nueva `formulario_mensaje_submission_settled`).
- **Direcciones y porcentajes.** El saneado de WordPress quita todo «%» seguido de dos cifras hexadecimales: una dirección con `%20` se guardaba rota, y «un 50%de descuento» perdía el «%de». `Plain_Text` los conserva, y la página de procedencia se lee con él.
- **Papelera de formularios.** Se usaba `wp_trash_post()`, así que WordPress borraba el formulario por su cuenta a los 30 días (o en el acto, con su papelera desactivada) y dejaba sus envíos sin dueño. La papelera es ahora del plugin: un formulario sale de ella solo cuando alguien lo borra.
- **Opciones guardadas con el saneado anterior.** Las que tuvieran un «<» quedaron como `&lt;` y dejaban de coincidir con lo que envía el navegador; se siguen aceptando.
- **Actualizaciones parciales por REST.** Cambiar solo el nombre de un formulario vaciaba sus campos, y cambiar un ajuste devolvía los demás a su valor de fábrica. Lo que no viene en la petición se queda como está.
- **Campo oculto de valor fijo en una condición.** El servidor lo veía y el navegador no: un campo obligatorio podía quedar oculto y el formulario, imposible de enviar. Los números con espacios («1 000») se comparan ahora igual en los dos lados.
- **Contadores del límite de envíos.** Nunca se borraban; ahora los caducados se limpian en el mantenimiento diario.
- **Widget de Elementor.** El nombre del formulario llegaba sin escapar a un desplegable que Elementor pinta como HTML.
- **Desinstalación en una red.** Solo limpiaba el sitio principal; ahora recorre todos. El multisitio sigue sin estar soportado ni probado: esto no se ha podido verificar en una red real.
- **Limpieza.** Una sola forma de buscar una acción en un formulario (`Form_Definition::get_action()`), y los listados leen cada formulario una vez por petición.

### Matriz de combinaciones (2026-10-09, tras las 11 fases)

A petición del usuario se añadió una segunda suite de navegador, `rt matrix` (`tests/matriz/`), que prueba combinaciones de opciones en muchos formularios a la vez: todos los tipos de campo con y sin JavaScript, validación, valores difíciles, cada operador de la lógica condicional sobre cada tipo de campo, pasos con condiciones y ficheros, grupos de tipos de fichero y destinos de los ficheros, notificaciones y webhooks combinados, lo que pasa tras el envío, estilo en sus dos niveles, antispam, las plantillas y el constructor de punta a punta, y los ajustes de todo el sitio (captcha, envío sin JavaScript, SMTP, Elementor). Son 63 tests, de los que 59 corren en paralelo.

Lo que encontró y se corrigió:

- **Un «<» que no es HTML se estropeaba.** «Me encanta <3» o «a<b» se guardaban como `&lt;` (lo hace `sanitize_text_field()`), y como el plugin escapa al mostrar, se veían así, literales, en correos, administración y webhooks. Pasaba también con las etiquetas, las opciones y los asuntos que escribe quien configura el formulario («Edad <18»). Ahora el texto sin formato pasa por `Plain_Text`, que quita las etiquetas HTML igual que antes y conserva esos signos.
- **Los saltos de línea de un área de texto se guardaban como `\r\n`**, que es como los envía el navegador, y así llegaban a los webhooks y a la exportación. Ahora se guardan como `\n`.
- **El campo oculto «título de la página» daba un valor con JavaScript y otro sin él** (el título del documento, con el nombre del sitio, frente al título de la página). Ahora da siempre el de la página; el del documento solo se usa si el servidor no lo sabe.
- **El registro de entregas no decía de qué acción era cada una**: con dos notificaciones o dos webhooks en un formulario, una entrega fallida solo mostraba «Notificación por correo». Ahora muestra además el nombre que se le dio a la acción.
- **Un formulario que ni guarda los envíos ni tiene ninguna acción activa** acepta el envío, da las gracias y no hace nada con los datos. El comportamiento se mantiene (es lo que se ha configurado), pero el constructor lo avisa ahora de forma visible.

Lo que se observó y se deja como está, para decidir:

- Las etiquetas HTML que escribe un visitante se eliminan al recibir el envío (`<script>…</script>`, con su contenido; de `<b>texto</b>` queda «texto»). Es el saneado de WordPress y evita sorpresas, pero quien pegue un fragmento de código en un mensaje lo verá recortado.
- El token antispam no es de un solo uso: vale mientras no caduca, y lo que limita su reutilización es el límite de envíos por IP.
- Un fichero `.docx`, `.xlsx` u `.odt` es por dentro un zip, y WordPress no puede distinguirlo de cualquier otro zip con ese nombre: se acepta por la extensión. No se ejecuta ni se sirve en línea.
- El formato de fecha de los correos es el de los ajustes de WordPress.

### Fase 11: lo que se decidió al hacerla

- **Comandos de WP-CLI** bajo `wp formulario-mensaje`: `form list`, `form export`, `form import`, `submission export` y `queue process`. Cada grupo es una clase que recibe sus servicios por el constructor y se registra en `cli_init`, que solo se dispara bajo WP-CLI: en una petición web no se carga nada de esto. Los mensajes son traducibles; la ayuda (`wp help …`) sale de los docblocks y va en inglés, como el resto de la interfaz.
- **Los comandos no comprueban capabilities**: quien ejecuta WP-CLI ya tiene acceso completo al servidor. Leen y escriben los ficheros que esa persona indica (`File_Access`, que solo usan ellos y solo admite rutas locales). Lo que se importa pasa por el mismo saneado que en la administración, y la exportación de envíos, por el mismo exportador, con las fórmulas neutralizadas.
- **El mantenimiento diario no tiene comando propio**: `wp cron event run formulario_mensaje_daily_maintenance` ya lo lanza. La cola sí, porque es lo que hay que poder empujar cuando el cron del sitio está parado.
- **Cuatro abilities, todas de solo lectura**: `list-forms`, `get-form`, `get-submissions` y `get-submission-counts`, en la categoría `formulario-mensaje`. Se agrupan por la pregunta que responden, no por ruta REST: una sola para los envíos, con filtros. Cada una comprueba la capability de lo que lee en cada ejecución y llama al mismo servicio que la administración, no a la ruta REST.
- **Lo que escribe o borra no se ofrece como ability**, a propósito: crear o cambiar formularios, borrar envíos y los ajustes se hacen en la administración. Es la decisión conservadora para un plugin que guarda datos personales; ampliar es añadir clases que implementen `Ability`.
- **`get-form` no devuelve los ajustes de las acciones**, solo cuáles son y si están activas, y `get-submissions` no devuelve ni la IP ni el navegador del visitante.
- **Visibles por la REST API de abilities** (`show_in_rest`) **y, salvo `get-submissions`, para el adaptador de MCP** (`mcp.public`). El permiso se comprueba igual por cualquier vía.
- **Documentación para desarrolladores** en `docs/`: `hooks.md` (acciones, filtros, tareas programadas y lo que ofrece el script del navegador) y `extender.md` (tipos de campo, de acción, servicios de captcha, plantillas, WP-CLI y abilities). La referencia exacta de cada hook sigue siendo su docblock.

Cambios a raíz de la revisión de seguridad del commit de esta fase, corregidos en un commit aparte:

- `get-form` devolvía el documento de exportación, que quita los secretos pero conserva la dirección de los webhooks (que suele ser una credencial), el nombre de sus cabeceras y los destinatarios de los correos. Para copiar un formulario a otra web eso es lo que se quiere; para un agente, no. Ahora las acciones van resumidas, sin ajustes.
- `get-submissions` entrega a un agente texto escrito por visitantes anónimos, que puede contener instrucciones dirigidas a él (inyección de instrucciones indirecta). Ahora el resultado lleva un aviso junto a los datos, la descripción lo advierte, y la ability no se ofrece a los clientes MCP salvo que el sitio la active con el filtro `formulario_mensaje_ability_mcp_public`. El contrato `Ability` dice qué abilities devuelven texto de visitantes (`returns_visitor_content()`), y el registro decide con eso. El riesgo no desaparece para quien la active o la use por REST con un agente: es inherente a dar a leer contenido ajeno.

Límite conocido: el constructor solo tiene editor para las notificaciones y los webhooks. Un tipo de acción de terceros se ejecuta y queda en el registro de entregas, pero hay que añadirlo a la definición por una plantilla o una importación.

No se ha probado con un cliente MCP real ni con el adaptador de MCP de WordPress instalado: las abilities se han probado ejecutándolas desde PHP y por la REST API de abilities del núcleo. Los comandos se han probado con el WP-CLI del entorno.

### Fase 10: lo que se decidió al hacerla

- **Tipo de campo «Subida de ficheros»**, con sus ajustes: tipos permitidos (casillas de los tres grupos cerrados: imágenes, PDF y documentos de oficina), número máximo de ficheros (1, 2, 3, 5 o 10), tamaño máximo de todos juntos (1, 2, 5, 10 o 20 MB), adjuntar al correo, conservar con el envío guardado e incluir un enlace de descarga en las notificaciones, con su caducidad (1, 7 o 30 días). Sin extensiones libres.
- **Lo que cuenta es el contenido del fichero.** Se valida con `wp_check_filetype_and_ext()` contra los tipos del campo, y `wp_handle_upload()` lo vuelve a comprobar al moverlo. El nombre y el tipo que declara el navegador no cuentan, y el tamaño se mide en el servidor. Un fichero rechazado rechaza el envío, con su mensaje junto al campo.
- **Almacén privado**: `wp-content/uploads/formulario-mensaje-private/`, con un `.htaccess` que prohíbe servirlo. Cada fichero se guarda con un nombre aleatorio de 32 caracteres hexadecimales y extensión `.dat`, distinto de su identificador público (el que va en los enlaces de descarga): nada del nombre original llega al disco, que solo se guarda en la base de datos (tabla nueva `formulario_mensaje_files`, esquema versión 3) para mostrarlo y para la descarga.
- **Descarga solo a través del plugin** (`admin-post.php?action=formulario_mensaje_file`), siempre como descarga y con un tipo genérico, para que el navegador no interprete lo que subió un desconocido. Se entra con sesión y la capability de ver envíos (más un nonce), desde el detalle del envío, o con el enlace firmado de una notificación: una firma HMAC del identificador del fichero y de su caducidad, con una clave derivada de las salts del sitio. Todos los rechazos responden lo mismo.
- **El enlace firmado no se guarda con el envío**: va solo en la copia que reciben las acciones (correos y webhooks). Solo se genera si el fichero se va a conservar: el campo lo pide y el formulario guarda sus envíos.
- **Todos los ficheros aceptados pasan por el almacén**, también los que solo se adjuntan: las acciones se ejecutan después y necesitan el fichero. Al terminar el envío se borran los que no hay que conservar (el campo no lo pide, o el formulario no guarda sus envíos). Si el envío fracasa, se borran todos.
- **Se borran con su envío**, por la vía que sea (a mano, retención, al vaciar la papelera de formularios), enganchados a la acción `formulario_mensaje_submissions_deleted`. Los restos de envíos que no llegaron a completarse se limpian en el mantenimiento diario, pasadas 24 horas. Al desinstalar con borrado de datos se elimina el almacén entero.
- **Adjuntos**: los tres transportes los envían (`wp_mail`, SMTP y Resend). Un correo lleva como mucho 20 MB de adjuntos (filtro `formulario_mensaje_max_attachment_bytes`); el que no cabe se queda fuera él solo, el correo dice cuáles faltan y siguen en el envío guardado si el campo los conserva.
- **En los formularios por pasos** los ficheros viajan una sola vez, con el envío final: la validación de un paso no los sube, y el navegador solo comprueba que los obligatorios estén elegidos. El navegador avisa también del exceso de número o de tamaño antes de subir nada; el servidor lo comprueba todo otra vez.
- **Sin JavaScript** el formulario se envía como `multipart/form-data` y funciona igual.
- **Si la petición supera `post_max_size`**, PHP descarta su contenido entero; el plugin lo detecta y lo explica al visitante, en vez de responder con un error del token antispam.
- **Un campo de ficheros oculto por su condición** no forma parte del envío, como cualquier otro: lo que llegue para él se descarta sin guardarlo.

Límites conocidos:

- Donde el servidor no aplica `.htaccess` (nginx), el almacén no está prohibido por configuración: la protección es que el nombre del fichero no se puede adivinar ni sale en ninguna página, y que su extensión no se ejecuta. Conviene añadir en nginx una regla `deny` para la carpeta.
- Sin la extensión `fileinfo` de PHP, WordPress solo puede comprobar el contenido de las imágenes; del resto se fía de la extensión. Aun así los ficheros ni se ejecutan ni se sirven en línea.
- Si una notificación falla, los ficheros que solo existían para adjuntarla se quedan con el envío hasta que la entrega se reintenta con éxito o se descarta; entonces se borran. El reintento, manual o de la cola, lleva el enlace firmado igual que la notificación original (desde la revisión del 2026-10-12).
- El límite de subida de PHP (`upload_max_filesize`) es por fichero y puede ser menor que el del campo: en ese caso el visitante recibe un mensaje con el límite del servidor.

Cambio a raíz de la revisión de seguridad del commit de esta fase: el nombre del fichero en disco salía del mismo identificador que viaja en los enlaces de descarga, así que quien tuviera un enlace, aunque estuviese caducado, sabía dónde estaba el fichero; en un servidor que no aplique el `.htaccess` habría podido descargarlo sin pasar por la caducidad ni por los permisos. Ahora son dos valores aleatorios independientes, y la ruta en disco no sale nunca del servidor. Corregido en un commit aparte, justo después.

Probado con subidas reales en Playwright (envío normal, por pasos y sin JavaScript), con Apache. No se ha probado en nginx, ni con Resend real, ni con ficheros de oficina de verdad (los tests usan PDF e imágenes).

### Fase 9: lo que se decidió al hacerla

- **Un paso es un elemento más de la lista de campos** (tipo «Paso», en el grupo de maquetación): los campos que le siguen, hasta el siguiente paso, son los suyos. No hay una estructura aparte que mantener, y ordenar, duplicar o borrar funciona igual. Los campos anteriores al primer paso forman un paso inicial sin título.
- **Con JavaScript** los pasos se muestran de uno en uno, con una barra de progreso («Paso 2 de 3: título»), botones Anterior y Siguiente, y el de enviar solo en el último. Al cambiar de paso el foco va a su título. Pulsar Intro en un paso intermedio avanza; no envía.
- **Cada paso se valida en el servidor antes de avanzar** (`POST …/forms/<id>/validate`): es la misma validación del envío, limitada a los campos de ese paso, y no acepta ni guarda nada. Los datos se guardan y las acciones se disparan solo al enviar el último paso, que vuelve a validar el formulario entero; si entonces hay un error en otro paso, se vuelve a él.
- **Pasos condicionales**: la condición de un paso decide si se muestra. Un paso que no se muestra se salta, no cuenta en la barra de progreso y sus campos no forman parte del envío.
- **Sin JavaScript** los pasos se ven todos seguidos, como secciones con su título, y el formulario se envía de una vez, como estaba previsto. El servidor aplica igualmente las condiciones.
- **Sin estado en el servidor entre pasos**: no hay borradores ni sesiones. Si el visitante recarga la página, empieza de nuevo.

Cambio sobre la fase 8, a raíz de la revisión de seguridad de su commit: la visibilidad se calculaba por pasadas hasta estabilizarse, con un tope que no era el mismo en servidor y navegador; con campos que dependían unos de otros en círculo podían no coincidir, y un campo obligatorio a la vista quedar sin validar. Ahora **un campo solo puede depender de los que van antes que él** (el saneado descarta las demás reglas y el constructor solo ofrece los anteriores) y la visibilidad se resuelve en un único recorrido, en el orden del formulario, igual en los dos lados. Un campo posterior, o uno oculto, cuenta como vacío.

Un campo oculto de valor fijo no se imprime en la página salvo que la condición de otro campo dependa de él: entonces el navegador necesita su valor para mostrar ese campo igual que lo exigirá el servidor (corregido tras la revisión de código; antes no se imprimía nunca y el formulario podía quedar imposible de enviar).

### Fase 8: lo que se decidió al hacerla

- **Cada campo puede llevar una condición para mostrarse** («Mostrar solo si…»), con el mismo editor de reglas que las notificaciones y los webhooks. Vale también para los elementos de presentación (un párrafo, un título). Como el ancho, es una propiedad del campo en la definición (`condition`), no una opción de cada tipo.
- **Un campo oculto no forma parte del envío**: no se valida aunque sea obligatorio, su valor se descarta aunque la petición lo traiga, y no aparece en los correos, en los webhooks ni en el envío guardado. Quien depende de un campo oculto lo ve vacío.
- **Decide el servidor.** El navegador muestra y oculta para que el formulario sea cómodo, pero al recibir el envío el servidor vuelve a calcular qué campos correspondían, con sus propias reglas y sobre los valores ya saneados.
- **El mismo motor en los dos lados**: `Rule_Evaluator` y `Visibility_Resolver` en PHP y `assets/js/rules.js` en el navegador, comprobados contra los mismos casos (`tests/fixtures/reglas.json`), que ejecutan PHPUnit y Playwright. Un cambio en las reglas se hace en los dos y se añade su caso al fichero.
- **Dependencias en cadena**: un campo solo puede depender de los que van antes que él, y la visibilidad se resuelve en un único recorrido en el orden del formulario (ver el cambio anotado en la fase 9).
- **Sin JavaScript se ven todos los campos** y el servidor aplica las condiciones al recibir el envío, como estaba previsto. Con JavaScript, los controles de un campo oculto se desactivan, así que ni viajan ni los valida el navegador.
- **El motor de reglas del navegador es un módulo aparte**, declarado como dependencia del script del formulario: WordPress lo resuelve con su mapa de importación y lo precarga. Se carga en toda página con formulario, tenga o no condiciones; ocupa poco y evita que los campos condicionales parpadeen al abrir la página.

### Fase 7: lo que se decidió al hacerla

- **El estilo es una lista cerrada de colores y medidas**, no CSS libre (`Form_Style`): color de etiquetas, de texto y fondo de los campos, de borde, de foco y del botón (fondo y texto); separación entre campos, relleno, grosor y radio del borde. Lo que no se ajusta se hereda del tema, como hasta ahora. La tipografía no está entre los ajustes del formulario: la da el tema, y el bloque y Elementor tienen sus propios controles.
- **Dos niveles con prioridad clara**, resueltos con propiedades CSS encadenadas y sin depender de la especificidad: lo que diga el sitio donde se inserta (`--fm-embed-*`: el bloque y Elementor) gana a lo que diga el diseñador del formulario (`--fm-form-*`), y este al valor por defecto. Un tema puede seguir fijando directamente `--fm-gap` y compañía.
- **El estilo del formulario viaja en su atributo `style`**, como propiedades CSS con valores ya validados (hexadecimales y píxeles), no en una hoja de estilos generada. Forma parte de la definición (`style`), así que se duplica, se exporta y se importa con el formulario.
- **El botón conserva el aspecto del tema** mientras nadie le ponga color; al ponérselo, el formulario lleva la clase `fm-button-styled`.
- **Bloque**: además de sus ajustes nativos (color de texto y fondo, tamaño de letra, interlineado, márgenes), tiene en su pestaña de estilos los mismos colores y medidas del formulario, que se aplican solo a esa inserción (atributo `formStyle`, saneado al pintar).
- **Widget de Elementor**: clásico (`Widget_Base`), registrado en `elementor/widgets/register`; si Elementor no está activo, su clase ni se carga. Elige el formulario y da estilo con controles de Elementor (separación y bordes, etiquetas, campos y botón, con tipografía en los tres últimos y alineación del botón) que generan CSS por selectores sobre las propiedades `--fm-embed-*`. El HTML es el mismo que el del bloque y el shortcode, y el formulario se envía igual.
- **Acceso estático al contenedor** (`Plugin::service()`): la única excepción prevista al «sin estado estático», usada solo por el widget, que Elementor instancia por su cuenta.
- **En el editor de Elementor** el widget se pinta en el servidor, sin plantilla de JavaScript: lo que se ve es el formulario real.

Probado con Elementor 4.3.4 y con Elementor 3.35.9 (instalando cada versión en el entorno): el widget en la web con sus estilos, el envío del formulario y su aparición en la vista previa del editor. El entorno queda con la 4.3.4, inactiva; el test de Playwright la activa y la desactiva. No se han probado Elementor Pro ni sus contenedores con rejilla, ni los controles del widget uno a uno desde el panel de Elementor (se han aplicado guardando sus valores en la página).

### Fase 6: lo que se decidió al hacerla

- **Columnas**: cada campo tiene un ancho (todo, tres cuartos, dos tercios, la mitad, un tercio o un cuarto) y los campos seguidos que suman el ancho completo comparten fila. El formulario es una rejilla de doce columnas; por debajo de 480 píxeles de pantalla cada campo ocupa su fila. No hay «filas» ni «columnas» como elementos del constructor: el orden de los campos sigue siendo una sola lista, que es también el orden de lectura y de tabulación.
- **El ancho es una propiedad del campo en la definición** (`{ id, type, settings, width }`), no una opción de cada tipo: vale para cualquier tipo, también los de terceros, sin que tengan que declararlo.
- **Plantillas de partida**: al añadir un formulario se elige entre contacto, solicitud de presupuesto (en dos columnas), «te llamamos», alta en el boletín y en blanco. Una plantilla es solo el punto de partida; el formulario no guarda relación con ella. Todas llevan una notificación al administrador del sitio. Filtro `formulario_mensaje_form_templates` para añadir las propias, que pasan por el mismo saneado que un formulario.
- **Duplicar** crea una copia con todo, también el secreto de firma de sus webhooks, y con las acciones como estuvieran: es la misma web y las mismas personas.
- **Exportar** descarga un JSON con todo menos los secretos: el secreto de firma de los webhooks y el valor de todas sus cabeceras, que es donde van las credenciales del destino (viajan los nombres, para saber cuáles rellenar). Cada tipo de acción decide qué no sale de la instalación (`Action_Type::portable_settings()`). La dirección del webhook sí viaja: si lleva un identificador secreto en la ruta, el fichero exportado hay que tratarlo como tal.

Cambio a raíz de la revisión de seguridad de su commit: la exportación decidía por el nombre qué cabeceras llevaban credenciales, y una con otro nombre se habría escapado. Ahora no viaja el valor de ninguna.
- **Importar** crea siempre un formulario nuevo. El fichero es entrada no fiable: pasa por el mismo saneado que el constructor, sus acciones quedan en pausa hasta que alguien las revise, no trae secretos aunque el fichero los lleve, y una redirección a un dominio que esta web no permite se desactiva en vez de impedir la importación. Un fichero de una versión posterior del formato se rechaza.
- **Reorganizado**: `Default_Definition` desaparece; la plantilla de contacto es ahora la primera de `Form_Templates`.

### Fase 5: lo que se decidió al hacerla

- **El captcha se configura una vez para todo el sitio** (Ajustes → Captcha: servicio, clave del sitio, clave secreta y, para reCAPTCHA, puntuación mínima) y protege todos los formularios en cuanto están las dos claves. Cada formulario puede renunciar a él (ajuste `captcha_enabled`, en su pestaña «Tras el envío»). A medio configurar no se activa.
- **Tres servicios, un contrato** (`Captcha_Provider`): Cloudflare Turnstile, Google reCAPTCHA v3 y hCaptcha. Comparten el cliente de verificación (`Siteverify_Client`); reCAPTCHA comprueba además la puntuación mínima (0,5 por defecto, de 0,1 a 0,9) y que el testigo se pidió para la acción del formulario. Se registran en `formulario_mensaje_register_captcha_providers`; un servicio de terceros necesita además su adaptador en el navegador (`window.formularioMensajeCaptchaAdapters`).
- **Nada de terceros al abrir la página**: el formulario solo lleva unos atributos `data-`. Cuando el visitante lo enfoca, el script del formulario importa `assets/js/captcha.js`, y este carga el script del servicio. Las páginas sin formulario, y los formularios sin captcha, no cargan nada.
- **El captcha va detrás del límite por IP y los intentos que rechaza gastan ese límite**: es la única defensa que consulta a otro servicio, y sin tope serviría para hacerle peticiones a costa del sitio. Para ello cambió una regla de la fase 1: lo que una defensa rechaza ya no devuelve el hueco reservado.
- **Si el servicio de captcha no responde, el envío se rechaza** con un aviso de que se intente más tarde. Aceptar sin comprobar dejaría el captcha sin efecto.
- **Sin JavaScript no hay captcha**: un formulario con captcha rechaza el POST normal, aunque los envíos sin JavaScript estén permitidos en los ajustes.
- **Casilla sin resolver**: si el servicio espera a que el visitante pulse su casilla, el formulario avisa al momento en vez de enviar; si su comprobación es automática y sigue en curso, espera hasta 10 segundos. Tras cada intento se pide un testigo nuevo, porque solo valen una vez.
- **Clave secreta** en el almacén de secretos (`captcha_secret`, constante `FORMULARIO_MENSAJE_CAPTCHA_SECRET`). Como la contraseña de SMTP, va ligada a su servicio: al cambiar de servicio sin escribir la nueva, la guardada se descarta.
- **Privacidad**: los tres servicios reciben la IP del visitante y lo que su script recoja en el navegador. La pantalla de ajustes lo advierte y recomienda usar captcha solo si sigue llegando spam; informar de ello en la política de privacidad del sitio queda en manos de quien lo activa.

Cambio a raíz de la revisión de seguridad de su commit: el script del formulario importaba el módulo del captcha desde una dirección escrita en un atributo del HTML. Quien lograse insertar HTML en una página (sin poder insertar scripts) habría podido hacer que se cargase su código. Ahora la dirección sale de la del propio script.

Verificado contra los servicios reales con sus claves públicas de prueba: la verificación en servidor de Turnstile y de hCaptcha (aprobado y rechazado). No verificado: reCAPTCHA v3, que no tiene claves de prueba, ni los scripts reales de los tres servicios en el navegador, que en los tests se sustituyen por dobles.

### Fase 4: lo que se decidió al hacerla

- **La cola es el propio registro de entregas**, no una tabla aparte: una entrega en estado «en cola» lleva la fecha de su siguiente intento (esquema versión 2: columnas `next_attempt_at` y `payload`). Así no hay dos sitios que mantener de acuerdo, y el detalle de un envío muestra el webhook desde que entra en la cola.
- **Cada tipo de acción dice si va en segundo plano** (`Action_Type::is_deferred()`): el correo no, porque si falla y el envío no se guarda el visitante tiene que saberlo; el webhook sí. Una acción de terceros elige.
- **Primer intento al terminar la petición del visitante**: se programa un evento único de WP-Cron para ya y se lanza WP-Cron al cerrar la petición, sin hacerle esperar. Con `DISABLE_WP_CRON`, lo recoge el cron del sistema. El mantenimiento diario y su botón de los ajustes también procesan la cola, como red de seguridad.
- **Reintentos**: tras cada fallo se espera 1 minuto, 5 minutos, 30 minutos, 2 horas y 8 horas (seis intentos en total; filtro `formulario_mensaje_retry_delays`). Mientras se reintenta, el webhook no cuenta como fallo. Agotados, pasa a «fallido», aparece en la lista de fallos y se puede reintentar a mano, que lo ejecuta en el momento.
- **Varios procesos a la vez no duplican un webhook**: cada entrega se reserva con una sola sentencia SQL antes de ejecutarla; si el proceso muere, vuelve a la cola a los cinco minutos. El destino recibe la misma cabecera `X-Formulario-Mensaje-Reference` en todos los intentos de un envío, por si quiere descartar repeticiones.
- **Formularios que no guardan sus envíos**: la cola lleva su propia copia del envío (sin IP ni navegador) hasta que la entrega termina, bien o mal; entonces se borra. Es lo mínimo para poder reintentar. Agotados los reintentos, ese webhook ya no se puede reintentar a mano.
- **Un webhook que se desactiva o se borra mientras espera en la cola no se envía**; se usa siempre la configuración actual, así que corregir la dirección arregla los intentos siguientes.
- **Webhook**: dirección `http(s)`, método POST, PUT o PATCH, cuerpo siempre en JSON. Datos: el envío completo (`form`, `reference`, `date`, `page_url`, `fields` por identificador de campo y `labels`) o solo las claves que se elijan, cada una con un texto con etiquetas (filtro `formulario_mensaje_webhook_payload`). Cabeceras adicionales configurables, salvo las que fija la petición y las del plugin. Espera máxima de 10 segundos (filtro `formulario_mensaje_webhook_timeout`), sin seguir redirecciones. Se da por entregado con cualquier respuesta 2xx.
- **Firma**: con un secreto, cada petición lleva `X-Formulario-Mensaje-Timestamp` y `X-Formulario-Mensaje-Signature: sha256=<HMAC-SHA256 de «marca de tiempo.cuerpo»>`.
- **El secreto de firma se guarda cifrado dentro de la definición del formulario**, no en el almacén de secretos: viaja con el formulario al duplicarlo y no hay nada que limpiar al borrarlo. El navegador solo recibe el valor cifrado, que devuelve tal cual al guardar; no admite constante en `wp-config.php`. Las cabeceras adicionales, en cambio, se guardan en claro y las ve quien puede editar el formulario: el constructor lo avisa.
- **Destinos internos**: la petición va por `wp_safe_remote_request()`, que rechaza `localhost`, las redes privadas y los puertos que no sean 80, 443 u 8080. Para un n8n en la misma máquina o en la red interna hay que permitirlo con los filtros del núcleo `http_request_host_is_external` y `http_allowed_safe_ports`; el plugin no ofrece un ajuste para ello, a propósito.
- **Prueba de un webhook** desde el constructor: en el momento, con datos de ejemplo y la cabecera `X-Formulario-Mensaje-Test`, sin pasar por la cola; muestra el código y el principio de la respuesta. En el registro de entregas nunca se guarda el cuerpo de la respuesta, solo el código.
- **Aviso por correo de los fallos**: cuando una notificación no sale o un webhook agota sus reintentos, se avisa al administrador (o a la dirección que se indique en los ajustes) con el número de fallos y el enlace a la pantalla de envíos, sin datos de ningún envío. Como mucho un aviso cada 12 horas (filtro `formulario_mensaje_failure_alert_interval`); se puede desactivar. Se simplificó «fallan repetidamente»: avisa el primer fallo y agrupa los siguientes. Si falla el transporte del plugin, el aviso sale por el correo de WordPress.
- **Purga del registro de entregas**: las entregas terminadas se borran a los 30 días (ajuste, de 1 a 365), también las fallidas que nadie revisó; las que están en la cola no. Sustituye a la purga fija de la fase 2.
- **Reorganizado**: la lógica común de «ejecutar la acción de una entrega con su configuración actual» pasó a `Delivery_Executor`, que comparten el reintento manual y la cola; las rutas de prueba (`/forms/test-email`, `/forms/test-webhook`) tienen su propio controlador; y el constructor edita notificaciones y webhooks con el mismo componente de lista.
- **Hooks nuevos**: acción `formulario_mensaje_delivery_failed` (una entrega que ya no se reintenta sola) y `formulario_mensaje_process_queue`; filtros `formulario_mensaje_retry_delays`, `formulario_mensaje_webhook_payload`, `formulario_mensaje_webhook_timeout` y `formulario_mensaje_failure_alert_interval`.

No verificado contra servicios reales: los webhooks se han probado con dobles de la HTTP API y, en el navegador, contra un receptor montado en el propio WordPress local, por WP-Cron real. No se ha probado contra n8n ni contra un CRM.

Cambios sobre la fase 3, a raíz de la revisión de seguridad de su commit:

- **La contraseña de SMTP va ligada a su destino** (servidor, puerto y cifrado): si se cambia alguno sin escribir la contraseña de nuevo, la guardada se descarta y la pantalla lo avisa. Antes, apuntar los ajustes a otro servidor le habría entregado una contraseña que nadie puede leer. Una contraseña definida como constante no se puede descartar, así que lo que se fija es el servidor: mientras exista la constante, ni el servidor, ni el puerto ni el cifrado se cambian desde el panel, tampoco si aún no hay servidor: para configurarlos hay que retirarla antes (revisión de seguridad del 2026-10-12; antes se aceptaba, con el argumento de que quien la pone en `wp-config.php` decide a quién da el permiso, pero ese permiso lo recibe todo administrador al activar el plugin).
- **Las pruebas de correo tienen un límite por usuario** (10 cada 10 minutos, común a las dos pruebas): no dejan rastro en el registro, así que sin límite servirían para enviar correo en cantidad.
- **El servidor SMTP puede ser interno a propósito** (`localhost`, un relé de la red privada), así que no se le aplica la lista de destinos seguros de la HTTP API. Se justifica en el código: solo lo configura quien gestiona los ajustes, la conexión solo habla SMTP y del fallo se devuelve el mensaje de PHPMailer recortado.

Cambios sobre la fase 2, a raíz de la revisión de seguridad de su commit:

- **Borrar los envíos junto con un formulario** exige también el permiso de ver envíos; sin él la petición se rechaza entera, y los envíos se borran antes que el formulario.
- **Reintentar una acción desactivada** se rechaza: desactivarla es la forma de parar una notificación.
- **Límite por IP con la base de datos caída**: en vez de dejar pasar, cuenta con un transient de respaldo.
- **Borrado de los envíos de un formulario** sin tope de lotes, para que no queden restos.

Cambios sobre la fase 1, a raíz de la revisión de seguridad de su commit:

- **Límite por IP atómico.** Antes se comprobaba al principio y se contaba al final, con un transient: envíos simultáneos podían pasar todos la comprobación. Ahora el hueco se reserva al comprobar con una sola sentencia SQL y se devuelve si el envío no se acepta (errores de validación, fallo total).
- **Envío sin JavaScript.** Sigue sin token, como se decidió, pero con un límite propio más estricto (2 cada 10 minutos frente a 5) y un ajuste para desactivarlo («Aceptar envíos sin JavaScript»).
