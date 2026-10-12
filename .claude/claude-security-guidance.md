# Reglas de seguridad de formulario-mensaje

Recibe datos de visitantes anónimos: trata toda entrada del formulario como controlada por un atacante, también cuando después se muestra en la administración o se envía por correo.

## Qué es producción y qué es entorno

- `plugin/` es el código que llega a producción.
- `scripts/`, `tests/` y los ficheros de la raíz son el entorno local de desarrollo. Sus credenciales, su `WP_DEBUG` y las rutas públicas `rt/v1` del mu-plugin son de un WordPress desechable que solo escucha en `127.0.0.1`: están ahí a propósito. Señala esas mismas cosas si aparecen dentro de `plugin/`.

## Superficies que ya existen

- **Envío público.** `POST formulario-mensaje/v1/forms/<id>/token`, `.../validate` (valida un paso; no acepta ni guarda nada) y `.../submissions`, y el POST sin JavaScript `admin_post(_nopriv)_formulario_mensaje_submit`, son públicos y sin nonce a propósito (caché de página). Los defiende `Spam_Guard`: campo trampa, token firmado con tiempo mínimo, límite por IP reservado de forma atómica (`Rate_Limiter::hit()`) y captcha opcional. Un envío que una defensa rechaza sigue contando para el límite. El POST sin JavaScript no lleva token por decisión de producto: límite más estricto, ajuste para desactivarlo y rechazo si el formulario lleva captcha. Otra ruta pública, salvo la descarga firmada, es un hallazgo.
- **Captcha.** Verificación saliente a direcciones fijas de Cloudflare, Google y hCaptcha; si el servicio no responde, el envío se rechaza. La clave secreta va en `Secret_Vault` y se descarta al cambiar de servicio. Su módulo se importa de una dirección fija, no del HTML.
- **Envíos guardados (datos personales).** `/submissions`, `/deliveries` y la exportación `admin_post_formulario_mensaje_export` exigen `formulario_mensaje_view_submissions`; la exportación, además, nonce. El registro de entregas guarda códigos y mensajes de error, nunca datos del envío. Las tablas propias solo se tocan desde las clases `Wpdb_*`, con `prepare()` y `%i`.
- **Gestión.** `/forms*` exige `formulario_mensaje_manage_forms` y `/settings*`, `formulario_mensaje_manage_settings`. El listado de formularios admite además `edit_posts`, pero entonces solo devuelve id y título de los publicados.
- **Correo y credenciales.** `/forms/test-email` tiene límite por usuario y solo escribe a la dirección indicada. La contraseña SMTP y la clave de Resend se guardan cifradas (`Secret_Vault`) y ninguna respuesta las devuelve. El servidor SMTP puede ser interno a propósito; al cambiarlo se descarta la contraseña guardada. En el correo HTML se escapan los datos del envío y el texto configurado.
- **Webhooks y cola.** El destino lo escribe quien tiene `formulario_mensaje_manage_forms`: solo `http(s)`, siempre por `wp_safe_remote_request()` y sin seguir redirecciones (SSRF). El registro guarda el código de respuesta, nunca el cuerpo. El secreto de firma va cifrado en la definición: el navegador solo recibe el valor cifrado. La cola lleva una copia del envío solo si el formulario no lo guarda, y la borra al terminar la entrega.
- **Importación.** `/forms/import` recibe un JSON no fiable: pasa por el mismo saneado que el constructor y sus acciones quedan desactivadas. Ni la exportación ni la importación llevan secretos (`Action_Type::portable_settings()`).
- **Estilo.** `Form_Style` solo admite colores hexadecimales y píxeles, y vuelve a sanear lo que imprime en `style`.
- **Valores de los campos.** Llegan sin sanear al servicio de envío: solo se leen las claves de los campos del formulario y cada valor lo sanea su `Input_Field_Type`. Los campos cuya condición no se cumple se descartan en el servidor. Las etiquetas (`{field:…}`) se sustituyen en una sola pasada y, en HTML, escapadas.
- **Ficheros.** Solo los campos `Upload_Field_Type` los reciben: tipos de la lista cerrada `File_Types` (sin scripts, HTML ni SVG), comprobados por contenido y movidos con `wp_handle_upload()` a una carpeta con `.htaccess`, con nombre aleatorio `.dat`. Solo los sirve `admin_post(_nopriv)_formulario_mensaje_file`, siempre como descarga: capability de ver envíos y nonce, o firma HMAC con caducidad (`Download_Links`). Servirlos de otro modo es un hallazgo.
- **Abilities y WP-CLI.** Las abilities `formulario-mensaje/*` solo leen, comprueban la capability de lo que leen y no devuelven secretos, ajustes de acciones ni IP; la que devuelve texto de visitantes lo avisa y no va a MCP por defecto. Una que escriba es un hallazgo. Los comandos de WP-CLI usan los ficheros que indica quien los ejecuta (`File_Access`).
- **Redirección tras el envío.** El destino sale de la definición guardada y pasa por `Redirect_Policy` (host del sitio o lista de los ajustes) al guardar y en cada envío. Un destino tomado de la petición es un hallazgo.
- **Mensaje de éxito.** Lo compone el servidor (plantilla por `Inline_Html::filter()`, datos por `esc_html()`) y el script lo inserta con `innerHTML`. Otro uso de `innerHTML` con datos es un hallazgo.

## Reglas del plugin

1. **Autorización además de nonce.** Un nonce solo protege de CSRF. Toda acción que no sea el envío público del formulario (ajustes, `admin_post_*`, `wp_ajax_*`, rutas REST, envíos) comprueba una capability con `current_user_can()`. `is_admin()` no es una comprobación de permisos.
2. **Rutas REST.** Toda ruta declara `permission_callback`. `__return_true` solo es aceptable en la ruta pública de envío. Los argumentos se validan con `args` o con un schema.
3. **Acceso por identificador.** Si una petición recibe el id de un formulario o de un envío, se comprueba que el usuario puede acceder a ese objeto concreto, no solo que existe.
4. **Entrada.** Se leen claves concretas, nunca `$_POST`, `$_GET` o `$_REQUEST` enteros; primero `wp_unslash()` y después la función del tipo de dato (`Plain_Text`, que envuelve a `sanitize_text_field`; `sanitize_email` con `is_email`, `absint`, `esc_url_raw`).
5. **Salida.** Escapado tardío y según el contexto: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`; los datos para JavaScript, con `wp_json_encode`. Los datos de un envío nunca son fiables, tampoco en la administración ni en correos HTML.
6. **SQL.** Toda consulta con datos variables pasa por `$wpdb->prepare()`, con `%i` para tablas y columnas y `$wpdb->esc_like()` en `LIKE`. Nada se interpola ni se concatena, tampoco en `ORDER BY` o `LIMIT`.
7. **Correo.** Nada que venga del formulario llega a las cabeceras (`From`, `Reply-To`, `Cc`, `Bcc`, asunto) sin validarse con `is_email()` y sin eliminar los saltos de línea. El destinatario sale de la configuración, no de la petición. El remitente no se toma del visitante; su dirección va, como mucho, en `Reply-To`.
8. **Abuso del envío público.** La IP del cliente no se toma de cabeceras falsificables (`X-Forwarded-For`, `Client-IP`) salvo configuración explícita.
9. **Ficheros.** Nunca `move_uploaded_file()` ni el nombre original como ruta. Las rutas con datos externos se validan con `realpath()` dentro del directorio esperado.
10. **Peticiones salientes.** Siempre con `wp_safe_remote_*`, no `wp_remote_*` (SSRF), y sin desactivar `sslverify`.
11. **Redirecciones.** Con destino variable, `wp_safe_redirect()` seguido de `exit`; `wp_redirect()` solo con destinos fijos.
12. **Deserialización y ejecución.** Sin `unserialize()` ni `maybe_unserialize()` sobre datos que vengan de una petición o de un envío. Sin `eval`, `extract`, shell ni `include`/`require` con rutas variables.
13. **Exportación.** En un CSV, las celdas que empiezan por `=`, `+`, `-`, `@`, tabulador o retorno se neutralizan.
14. **Secretos y datos personales.** Sin claves, tokens ni contraseñas en el código: van en opciones o en constantes de `wp-config.php`, y no se exponen por REST, `wp_localize_script` ni HTML. Los envíos contienen datos personales: no se escriben en `error_log` ni en `debug.log`, ni en URLs, ni en respuestas a usuarios sin permiso.
15. **Tokens.** Lo impredecible se genera con `wp_generate_password()` o `random_bytes()`, nunca con `md5`, `uniqid` o `rand`, y se compara con `hash_equals()`.
16. **Acceso directo.** Todo fichero PHP empieza con `defined( 'ABSPATH' ) || exit;`.
