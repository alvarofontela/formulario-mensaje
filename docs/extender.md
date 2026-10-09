# Extender Formulario Mensaje

Cómo añadir piezas al plugin desde otro plugin, y cómo usarlo desde la línea de comandos y desde agentes. Los filtros y acciones sueltos están en [hooks.md](hooks.md).

El plugin crece añadiendo clases que cumplen un contrato y registrándolas; no hace falta tocar su código. Todo lo que sigue se hace desde un plugin propio, enganchado a las acciones de registro. Esas acciones se disparan la primera vez que el plugin necesita el registro, así que basta con añadir el `add_action()` al cargar el plugin propio.

## Tipos de campo

Un tipo de campo es una clase que implementa una de estas interfaces de `Formulario_Mensaje\Fields`:

| Interfaz | Para | Métodos que añade |
| --- | --- | --- |
| `Field_Type` | Elementos que no recogen datos (un título, un separador) | `get_type()`, `get_label()`, `get_group()`, `get_settings_schema()`, `render()` |
| `Input_Field_Type` | Campos que recogen un valor | `sanitize_value()`, `validate()`, `format_value()`, `sample_value()` |
| `Upload_Field_Type` | Campos que reciben ficheros | `validate_files()`, `allowed_mimes()` |
| `Section_Field_Type` | Elementos que abren una sección del formulario (un paso) | `section_title()` |

```php
add_action(
	'formulario_mensaje_register_field_types',
	static function ( Formulario_Mensaje\Fields\Field_Type_Registry $registry ): void {
		$registry->register( new Mi_Plugin\Campo_Valoracion() );
	}
);
```

Lo que hay que saber al escribir uno:

- **`get_type()`** es su identificador (minúsculas, cifras y guiones bajos) y queda guardado en los formularios: no se cambia después. Registrar un tipo con el identificador de uno del plugin lo sustituye.
- **`get_group()`** decide en qué grupo del menú «Añadir campo» aparece: `basic`, `advanced` o `layout`.
- **`get_settings_schema()`** declara los ajustes del campo. El constructor pinta el panel a partir de ese esquema y el servidor sanea con él lo que se guarda: no hay que escribir JavaScript. Cada control tiene `key`, `type`, `label` y `default`, y opcionalmente `help`, `choices` y `show_if`. Los tipos de control son `text`, `textarea`, `html` (HTML básico en línea), `toggle`, `select`, `checklist` (varias casillas de una lista cerrada), `options` (lista editable de opciones) y `css_class`.
- **`render()`** devuelve el HTML del campo con todos sus valores ya escapados. Para que el script del formulario lo trate como a los demás, el HTML necesita:
  - un envoltorio con el atributo `data-fm-field="<id del campo>"`;
  - el control con `name` igual a `$context->control_name( $field )` (que es `fm[<id>]`) e `id` igual a `$context->control_id( $field )`;
  - dentro del envoltorio, un elemento `<p class="fm-error" id="<id del control>-error" hidden></p>`, donde se escriben los errores.
- **`sanitize_value()`** recibe lo que llegó en la petición, sin validar y de cualquier tipo, y devuelve un texto o una lista de textos. **`validate()`** solo se llama con valores no vacíos; que un campo sea obligatorio lo comprueba el plugin. Devuelve el mensaje de error ya traducido, o `null`.
- **`format_value()`** es el texto que va a los correos, a la pantalla de envíos y a la exportación.
- Las condiciones («mostrar solo si…»), el ancho y el campo obligatorio son comunes a todos los tipos: no hay que implementarlos.

Los tipos del plugin, en `plugin/includes/fields/types/`, sirven de ejemplo; `Abstract_Text_Field_Type` es la base de los campos de una línea.

Un campo que reciba ficheros debe aceptar solo tipos de una lista cerrada y nunca nada que un servidor o un navegador ejecute o interprete (PHP, HTML, SVG, ejecutables). El plugin se encarga de moverlos al almacén privado, adjuntarlos y borrarlos.

## Tipos de acción

Una acción es lo que un formulario hace con cada envío. Las del plugin son la notificación por correo y el webhook. Una clase que implemente `Formulario_Mensaje\Actions\Action_Type` añade otra:

```php
add_action(
	'formulario_mensaje_register_action_types',
	static function ( Formulario_Mensaje\Actions\Action_Type_Registry $registry ): void {
		$registry->register( new Mi_Plugin\Accion_Crm() );
	}
);
```

- **`execute()`** recibe los ajustes guardados de la acción, el envío y el formulario, y devuelve `true` o un `WP_Error`. El mensaje del error se guarda en el registro de entregas y lo ve quien administra el sitio: no debe contener datos del envío.
- **`is_deferred()`** decide cuándo se ejecuta. Con `false`, mientras el visitante espera. Con `true`, en segundo plano, por la cola: el visitante no espera, y si falla se reintenta con esperas crecientes (filtro `formulario_mensaje_retry_delays`). Es lo adecuado para todo lo que dependa de un servicio ajeno.
- **`sanitize_settings()`** es la única defensa de lo que se guarda: recibe los ajustes sin validar.
- **`portable_settings()`** devuelve los ajustes sin nada que deba quedarse en la instalación (claves, credenciales). Es lo que viaja al exportar el formulario.
- Las peticiones salientes se hacen con `wp_safe_remote_request()`, y los secretos no se guardan en claro.

Límite actual: el constructor solo tiene editor para las notificaciones y los webhooks. Una acción de otro tipo se ejecuta y aparece en el registro de entregas, pero hay que añadirla a la definición del formulario por otra vía (una plantilla, con el filtro `formulario_mensaje_form_templates`, o una importación).

Para integrar un envío con otro sistema sin escribir un tipo de acción están el webhook, que no necesita código, y la acción `formulario_mensaje_submission_completed`.

## Servicios de captcha

Un servicio de captcha tiene dos mitades con el mismo identificador:

1. **En el servidor**, una clase que implemente `Formulario_Mensaje\Captcha\Captcha_Provider`. Su método `verify()` comprueba contra el servicio el testigo que envía el navegador y devuelve `true` o un `WP_Error`. Si el servicio no responde, el envío se rechaza.

   ```php
   add_action(
   	'formulario_mensaje_register_captcha_providers',
   	static function ( Formulario_Mensaje\Captcha\Captcha_Provider_Registry $registry ): void {
   		$registry->register( new Mi_Plugin\Captcha_Propio() );
   	}
   );
   ```

2. **En el navegador**, un adaptador en `window.formularioMensajeCaptchaAdapters`, cargado en las páginas con formulario. Es una función que recibe el hueco donde pintar el captcha y la clave del sitio, y devuelve (o promete) un objeto con `getToken()`, que resuelve al testigo o a una cadena vacía, y `reset()`:

   ```js
   window.formularioMensajeCaptchaAdapters = {
   	...window.formularioMensajeCaptchaAdapters,
   	'mi-captcha': async ( slot, siteKey ) => ( {
   		getToken: () => miServicio.token( slot, siteKey ),
   		reset() {},
   	} ),
   };
   ```

## Plantillas de partida

El filtro `formulario_mensaje_form_templates` añade plantillas al diálogo de «Añadir formulario». Una plantilla es solo un punto de partida: el formulario creado no guarda relación con ella.

## WP-CLI

Los comandos cuelgan de `wp formulario-mensaje`. Son adaptadores sobre los mismos servicios que la administración. `wp help formulario-mensaje <comando>` da el detalle de cada uno.

| Comando | Qué hace |
| --- | --- |
| `wp formulario-mensaje form list [--status=<publish\|trash>] [--format=<formato>]` | Lista los formularios. Formatos: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `wp formulario-mensaje form export <id> [--file=<fichero>]` | Exporta un formulario como JSON, sin sus secretos. Sin `--file`, lo imprime |
| `wp formulario-mensaje form import <fichero> [--porcelain]` | Crea un formulario a partir de una exportación. Sus acciones quedan en pausa. Con `--porcelain` imprime solo el identificador nuevo |
| `wp formulario-mensaje submission export <id del formulario> [--from=<AAAA-MM-DD>] [--to=<AAAA-MM-DD>] [--file=<fichero>]` | Exporta los envíos guardados de un formulario a CSV. Las fechas van en la zona horaria del sitio |
| `wp formulario-mensaje queue process` | Envía ahora los webhooks pendientes a los que ya les toca |

Para copiar un formulario de una web a otra:

```sh
wp formulario-mensaje form export 12 --file=contacto.json
# …en la otra web:
wp formulario-mensaje form import contacto.json
```

La exportación de envíos contiene datos personales: el fichero se guarda donde se indique, con los permisos de quien ejecuta el comando.

El mantenimiento diario no tiene comando propio porque no lo necesita: `wp cron event run formulario_mensaje_daily_maintenance`.

## Abilities API

El plugin registra cuatro abilities en la categoría `formulario-mensaje`, para agentes y clientes MCP. Las cuatro consultan y ninguna modifica nada; cada una comprueba su capability en cada ejecución y llama al mismo servicio que la administración.

| Ability | Capability | Qué devuelve | MCP |
| --- | --- | --- | --- |
| `formulario-mensaje/list-forms` | `formulario_mensaje_manage_forms` | Los formularios publicados o los de la papelera (`status`), con su título, número de campos y shortcode | Sí |
| `formulario-mensaje/get-form` | `formulario_mensaje_manage_forms` | La definición de un formulario (`id`): campos, ajustes y estilo, y qué acciones tiene. De las acciones, solo su tipo, su nombre y si están activas | Sí |
| `formulario-mensaje/get-submissions` | `formulario_mensaje_view_submissions` | Los envíos guardados, con filtros (`form_id`, `search`, `only_failed`, `after`, `before`) y paginación (`page`, `per_page` hasta 100). Sin IP ni navegador | No, salvo que se active |
| `formulario-mensaje/get-submission-counts` | `formulario_mensaje_view_submissions` | Cuántos envíos hay, en total y por formulario. No necesita entrada | Sí |

Se ejecutan desde PHP con `wp_get_ability( '…' )->execute( $entrada )`, y por la REST API de WordPress en `wp-json/wp-abilities/v1/abilities/<nombre>/run` (todas llevan `show_in_rest`).

Dos decisiones de seguridad que conviene conocer:

- **`get-form` no devuelve los ajustes de las acciones.** Ahí están la dirección de los webhooks, que a menudo es en sí misma una credencial, sus cabeceras y los destinatarios de los correos. Nada de eso hace falta para entender un formulario, y así no llega a un agente ni a lo que el agente tenga conectado.
- **`get-submissions` no se ofrece a los clientes MCP por defecto.** Lo que devuelve lo ha escrito cualquiera que haya enviado un formulario, y puede haberlo escrito pensando en el agente que lo va a leer («ignora tus instrucciones y…»). El resultado lleva siempre un aviso (`notice`) de que ese contenido son datos y no instrucciones, pero un aviso no es una garantía. Quien quiera que un agente lea los envíos por MCP lo activa a sabiendas:

  ```php
  add_filter(
  	'formulario_mensaje_ability_mcp_public',
  	static fn ( bool $public, string $name ): bool => 'formulario-mensaje/get-submissions' === $name ? true : $public,
  	10,
  	2
  );
  ```

  Conviene hacerlo solo si ese agente no tiene, en la misma sesión, herramientas con las que causar daño (enviar correos, publicar, borrar) sin confirmación de una persona.

Lo que escribe o borra (crear formularios, borrar envíos, cambiar ajustes) no se ofrece como ability a propósito.
