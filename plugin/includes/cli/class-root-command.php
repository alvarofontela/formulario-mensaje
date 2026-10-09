<?php
/**
 * Comando raíz de WP-CLI.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Cli;

use WP_CLI\Dispatcher\CommandNamespace;

defined( 'ABSPATH' ) || exit;

/*
 * El docblock de la clase y los de sus métodos son la ayuda que muestra WP-CLI (`wp help …`): por eso
 * van en inglés, como el resto de la interfaz, y las notas de diseño van en este comentario.
 *
 * No hace nada por sí misma: es el nombre del que cuelgan los comandos del plugin, y lo que WP-CLI
 * muestra al pedir ayuda sobre él.
 */

/**
 * Manages the forms, submissions and queue of Formulario Mensaje.
 *
 * ## EXAMPLES
 *
 *     # List the published forms.
 *     $ wp formulario-mensaje form list
 *
 *     # Export the submissions of a form as CSV.
 *     $ wp formulario-mensaje submission export 12 --file=contact.csv
 *
 *     # Process the pending webhooks now.
 *     $ wp formulario-mensaje queue process
 */
final class Root_Command extends CommandNamespace {
}
