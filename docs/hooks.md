# Hooks de Formulario Mensaje

Acciones y filtros que el plugin ofrece para intervenir en su funcionamiento desde otro plugin o desde el tema. Todos llevan el prefijo `formulario_mensaje_`. La referencia exacta de cada uno es el docblock que tiene en el código, junto a la llamada; esta página los reúne.

Las clases que reciben viven en el namespace `Formulario_Mensaje` (por ejemplo, `Formulario_Mensaje\Forms\Form`). Para añadir tipos de campo, de acción o servicios de captcha, ver [extender.md](extender.md).

## El envío de un formulario

Un envío pasa, por este orden, por el antispam, la validación, el guardado y las acciones (notificaciones y webhooks).

### `formulario_mensaje_validation_errors` (filtro)

Filtra los errores de validación de un envío. Sirve para añadir validaciones propias, por ejemplo entre varios campos, o para retirar un error.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$errors` | `array<string, string>` | Mensajes de error, por identificador de campo. Un array vacío significa que el envío es válido |
| `$entries` | `array<string, Submission_Entry>` | Datos del envío ya saneados, solo de los campos que le corresponden (los ocultos por su condición no están) |
| `$form` | `Form` | Formulario |

```php
add_filter(
	'formulario_mensaje_validation_errors',
	static function ( array $errors, array $entries, $form ): array {
		if ( isset( $entries['telefono'], $entries['correo'] ) && $entries['telefono']->is_empty() && $entries['correo']->is_empty() ) {
			$errors['telefono'] = __( 'Indica un teléfono o un correo.', 'mi-plugin' );
		}

		return $errors;
	},
	10,
	3
);
```

### `formulario_mensaje_before_actions` (acción)

Se dispara cuando un envío ha superado el antispam y la validación, antes de guardarlo y de ejecutar sus acciones.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$submission` | `Submission` | Envío validado |
| `$form` | `Form` | Formulario |

### `formulario_mensaje_submission_completed` (acción)

Se dispara cuando un envío se ha completado: ya está guardado (si el formulario guarda sus envíos), sus acciones inmediatas se han ejecutado y las de segundo plano están en la cola.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$submission` | `Submission` | Envío |
| `$form` | `Form` | Formulario |
| `$results` | `Action_Result[]` | Resultado de cada acción ejecutada |

Es el sitio para integrar el envío con otro sistema sin escribir un tipo de acción. Si esa integración puede fallar o tardar, es mejor un tipo de acción en segundo plano, que tiene reintentos y queda en el registro de entregas.

### `formulario_mensaje_delivery_failed` (acción)

Se dispara cuando una acción de un envío falla y ya no se va a reintentar sola: una notificación que no ha salido o un webhook que ha agotado sus reintentos. El plugin la usa para su aviso de entregas fallidas.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$delivery` | `Delivery` | Entrega fallida. No contiene datos del envío |

### `formulario_mensaje_submission_settled` (acción)

Se dispara cuando, tras un cambio en sus entregas, un envío guardado no tiene ninguna fallida: porque el reintento ha salido bien, porque se ha descartado, porque la cola ha terminado con ellas o porque han caducado en el registro de entregas sin que nadie las resolviera (pasado ese plazo ya no se pueden reintentar). El plugin la usa para borrar los ficheros que guardaba solo para poder reintentar una notificación.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$submission_id` | `int` | Identificador del envío guardado |

### `formulario_mensaje_submissions_deleted` (acción)

Se dispara tras borrar envíos, por la vía que sea (a mano, por la retención o al borrar un formulario con sus envíos), para que quien guarde algo ligado a ellos lo borre también. El plugin la usa para borrar los ficheros subidos.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$ids` | `int[]` | Identificadores de los envíos borrados |

## Antispam

### `formulario_mensaje_rate_limit` (filtro)

Filtra el límite de envíos por IP de un formulario.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$limit` | `array{max: int, window: int}` | Envíos permitidos (`max`; 0 desactiva el límite) en cada ventana de `window` segundos |
| `$form` | `Form` | Formulario |
| `$channel` | `string` | Vía del envío: `rest` o `fallback` (sin JavaScript) |

### `formulario_mensaje_min_fill_seconds` (filtro)

Filtra el tiempo mínimo, en segundos, entre la primera interacción con el formulario y su envío. Por debajo, el envío se rechaza. `0` lo desactiva.

### `formulario_mensaje_token_max_age` (filtro)

Filtra la validez del token antispam, en segundos, desde la primera interacción con el formulario.

### `formulario_mensaje_cloudflare_ip_ranges` (filtro)

Filtra los rangos de IP (CIDR, IPv4 e IPv6) que se consideran de Cloudflare cuando el sitio está configurado para tomar de él la IP del visitante. Sirve para actualizarlos sin esperar a una versión nueva del plugin.

## Correo

### `formulario_mensaje_email_message` (filtro)

Filtra el correo de una notificación antes de enviarlo.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$message` | `Email_Message` | Correo compuesto: destinatarios, asunto, cuerpo en texto y en HTML, dirección de respuesta y adjuntos. Es inmutable: para cambiarlo se devuelve otro `Email_Message` |
| `$submission` | `Submission` | Envío |
| `$form` | `Form` | Formulario |

```php
use Formulario_Mensaje\Mail\Email_Message;

add_filter(
	'formulario_mensaje_email_message',
	static fn ( Email_Message $message ): Email_Message => new Email_Message(
		$message->to,
		'[Web] ' . $message->subject,
		$message->body,
		$message->reply_to,
		$message->html,
		$message->attachments
	)
);
```

### `formulario_mensaje_max_attachment_bytes` (filtro)

Filtra el tamaño máximo, en bytes, de los adjuntos de un correo de notificación (por defecto, 20 MB). Sirve para ajustarlo a lo que admite el servidor de correo del sitio. Un fichero que no cabe se queda fuera él solo, y el correo dice cuáles no se han adjuntado: siguen en el envío guardado si su campo los conserva.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$bytes` | `int` | Tamaño máximo de todos los adjuntos juntos |
| `$form` | `Form` | Formulario |

### `formulario_mensaje_failure_alert_interval` (filtro)

Filtra los segundos mínimos entre dos avisos de entregas fallidas al administrador (por defecto, doce horas).

## Webhooks y cola

Un webhook solo se envía a direcciones externas: el plugin resuelve el nombre del destino una vez, comprueba que no apunta a la red interna y fija la conexión a esa dirección. Para enviar a un servidor de la propia red (un n8n interno, por ejemplo) se permite su nombre con el filtro del núcleo `http_request_host_is_external`, que el plugin respeta:

```php
add_filter(
	'http_request_host_is_external',
	static fn ( bool $external, string $host ): bool => $external || 'n8n.interno.example' === $host,
	10,
	2
);
```

### `formulario_mensaje_webhook_payload` (filtro)

Filtra los datos que un webhook envía a su destino, antes de convertirlos a JSON y de firmarlos.

| Parámetro | Tipo | |
| --- | --- | --- |
| `$payload` | `array<string, mixed>` | Datos que se envían |
| `$submission` | `Submission` | Envío |
| `$form` | `Form` | Formulario |
| `$settings` | `array<string, mixed>` | Ajustes del webhook, sin su secreto |

### `formulario_mensaje_webhook_timeout` (filtro)

Filtra los segundos de espera de la petición de un webhook.

### `formulario_mensaje_retry_delays` (filtro)

Filtra las esperas entre los reintentos de una acción en segundo plano. Cada elemento es la espera, en segundos, tras un intento fallido; el número de elementos es el número de reintentos. Un array vacío desactiva los reintentos.

## Constructor

### `formulario_mensaje_form_templates` (filtro)

Filtra las plantillas entre las que se elige al crear un formulario. Cada plantilla es un array con `id`, `label`, `description` y `definition`, esta última con la misma forma que la definición de un formulario (`fields`, `settings`, `actions`). Lo que se añada pasa por el mismo saneado que cualquier formulario.

## Idiomas

### `formulario_mensaje_locale_fallbacks` (filtro)

Filtra los locales de reserva: qué traducción del plugin se usa cuando el idioma del sitio no tiene la suya. Por defecto, cualquier variante del español usa `es_ES`. Cada elemento es el código de un idioma (lo que va antes del primer guion bajo del locale) y el locale que le sirve de reserva. Un valor que no tenga forma de locale se ignora, y un array vacío desactiva la reserva. Cómo se añade un idioma está en [extender.md](extender.md).

| Parámetro | Tipo | |
| --- | --- | --- |
| `$fallbacks` | `array<string, string>` | Locale de reserva de cada idioma: `array( 'es' => 'es_ES' )` |

```php
// Un sitio en catalán o en gallego ve el plugin en español en vez de en inglés.
add_filter(
	'formulario_mensaje_locale_fallbacks',
	static fn ( array $fallbacks ): array => $fallbacks + array(
		'ca' => 'es_ES',
		'gl' => 'es_ES',
	)
);
```

## Abilities

### `formulario_mensaje_ability_mcp_public` (filtro)

Filtra si una ability del plugin se ofrece a los clientes MCP. Por defecto se ofrecen todas menos las que devuelven texto escrito por visitantes anónimos (hoy, `formulario-mensaje/get-submissions`). El motivo y un ejemplo están en [extender.md](extender.md).

| Parámetro | Tipo | |
| --- | --- | --- |
| `$public` | `bool` | Si la ability se ofrece a los clientes MCP |
| `$name` | `string` | Nombre de la ability |

## Registros

Estas tres acciones entregan un registro al que añadir clases propias. Se explican en [extender.md](extender.md).

| Acción | Recibe | Para |
| --- | --- | --- |
| `formulario_mensaje_register_field_types` | `Field_Type_Registry` | Tipos de campo |
| `formulario_mensaje_register_action_types` | `Action_Type_Registry` | Tipos de acción |
| `formulario_mensaje_register_captcha_providers` | `Captcha_Provider_Registry` | Servicios de captcha |

Los tipos del plugin se registran en esas mismas acciones con prioridad 5: lo que se registre después con el mismo identificador sustituye al del plugin.

## Tareas programadas

Son los hooks de WP-Cron del plugin. Se pueden lanzar a mano con `wp cron event run <hook>`; la cola tiene además su comando, `wp formulario-mensaje queue process`.

| Hook | Cuándo | Qué hace |
| --- | --- | --- |
| `formulario_mensaje_daily_maintenance` | Una vez al día | Borra los envíos que superan la retención, el registro de entregas antiguo y los ficheros que se han quedado sin envío |
| `formulario_mensaje_process_queue` | Cuando hay entregas pendientes | Envía los webhooks en cola y programa sus reintentos |

Las dos se pueden ejecutar tarde o más de una vez sin daño.

## En el navegador

El script del formulario no usa la librería de hooks de WordPress; ofrece esto:

- **Evento `formulario-mensaje:submitted`**, que se dispara en el formulario (y burbujea) cuando un envío se acepta. Su `detail` lleva `formId` y `reference`, y ningún dato del envío.

  ```js
  document.addEventListener( 'formulario-mensaje:submitted', ( event ) => {
  	console.log( event.detail.formId, event.detail.reference );
  } );
  ```

- **`window.formularioMensaje.init( nodo )`** activa los formularios que haya dentro de un nodo insertado después de cargar la página (una ventana emergente, un constructor visual). Repetir la llamada no los activa dos veces.
- **Evento `formulario_mensaje_submit` en `window.dataLayer`**, si el formulario tiene activada la medición: lleva `form_id` y `form_name`, sin datos del envío.
- **`window.formularioMensajeCaptchaAdapters`**: adaptadores de servicios de captcha de terceros (ver [extender.md](extender.md)).

## Propiedades CSS

El aspecto del formulario se ajusta con propiedades CSS (`--fm-gap`, `--fm-border-color`…), que un tema puede fijar directamente sobre `.formulario-mensaje`. Están declaradas al principio de `plugin/assets/css/form.css`.
