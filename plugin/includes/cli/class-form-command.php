<?php
/**
 * Comandos de WP-CLI para los formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Cli;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Porter;
use Formulario_Mensaje\Forms\Form_Service;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/*
 * El docblock de la clase y los de sus métodos son la ayuda que muestra WP-CLI (`wp help …`): por eso
 * van en inglés, como el resto de la interfaz, y las notas de diseño van en este comentario.
 *
 * Adaptador fino sobre los mismos servicios que la administración. Quien ejecuta WP-CLI ya tiene
 * acceso completo al servidor: aquí no hay capabilities que comprobar, y los ficheros que se leen
 * y se escriben son los que esa persona indica.
 */

/**
 * Lists, exports and imports the forms of Formulario Mensaje.
 */
final class Form_Command {

	/**
	 * Constructor.
	 *
	 * @param Form_Service $forms  Servicio de formularios.
	 * @param Form_Porter  $porter Exportación e importación de formularios.
	 * @param File_Access  $files  Lectura y escritura de los ficheros que indica quien ejecuta el comando.
	 */
	public function __construct(
		private readonly Form_Service $forms,
		private readonly Form_Porter $porter,
		private readonly File_Access $files
	) {}

	/**
	 * Lists the forms.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Which forms to list.
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - trash
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List the published forms.
	 *     $ wp formulario-mensaje form list
	 *
	 *     # Identifiers of the forms in the trash.
	 *     $ wp formulario-mensaje form list --status=trash --format=ids
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Argumentos posicionales; no hay ninguno.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function list_forms( array $args, array $assoc_args ): void {
		$status = Form::STATUS_TRASHED === ( $assoc_args['status'] ?? '' ) ? Form::STATUS_TRASHED : Form::STATUS_PUBLISHED;
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$forms  = array_values( $this->forms->list( $status ) );

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', array_map( static fn ( Form $form ): int => $form->id, $forms ) ) );

			return;
		}

		WP_CLI\Utils\format_items(
			$format,
			array_map(
				static fn ( Form $form ): array => array(
					'id'       => $form->id,
					'title'    => $form->title,
					'status'   => $form->status,
					'fields'   => count( $form->definition->fields ),
					'actions'  => count( $form->definition->actions ),
					'modified' => $form->modified,
				),
				$forms
			),
			array( 'id', 'title', 'status', 'fields', 'actions', 'modified' )
		);
	}

	/**
	 * Exports a form as JSON, without its secrets.
	 *
	 * The result is the same document as the "Export" button of the admin screen: webhook signing secrets and header values are left out.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Identifier of the form.
	 *
	 * [--file=<file>]
	 * : Write the JSON to this file instead of printing it.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp formulario-mensaje form export 12 --file=contact.json
	 *     Success: Form exported to contact.json.
	 *
	 * @param string[]              $args       Argumentos posicionales: el identificador.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function export( array $args, array $assoc_args ): void {
		$form = $this->forms->get( absint( $args[0] ?? 0 ) );

		if ( null === $form ) {
			WP_CLI::error( __( 'The form does not exist.', 'formulario-mensaje' ) );
		}

		$json = (string) wp_json_encode( $this->porter->export( $form ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$file = (string) ( $assoc_args['file'] ?? '' );

		if ( '' === $file ) {
			WP_CLI::line( $json );

			return;
		}

		if ( ! $this->files->write( $file, $json . "\n" ) ) {
			/* translators: %s: path of a file. */
			WP_CLI::error( sprintf( __( 'The file %s could not be written.', 'formulario-mensaje' ), $file ) );
		}

		/* translators: %s: path of a file. */
		WP_CLI::success( sprintf( __( 'Form exported to %s.', 'formulario-mensaje' ), $file ) );
	}

	/**
	 * Imports a form from a JSON file exported by Formulario Mensaje.
	 *
	 * It always creates a new form. Its notifications and webhooks are left paused until someone reviews them, and it brings no secrets.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path of the JSON file.
	 *
	 * [--porcelain]
	 * : Print only the identifier of the new form.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp formulario-mensaje form import contact.json
	 *     Success: Form imported with the identifier 15. Its actions are paused until you review them.
	 *
	 * @param string[]              $args       Argumentos posicionales: la ruta del fichero.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function import( array $args, array $assoc_args ): void {
		$file     = (string) ( $args[0] ?? '' );
		$contents = $this->files->read( $file );

		if ( null === $contents ) {
			/* translators: %s: path of a file. */
			WP_CLI::error( sprintf( __( 'The file %s could not be read.', 'formulario-mensaje' ), $file ) );
		}

		// El fichero es entrada no fiable aunque venga del disco: lo valida y lo sanea el mismo servicio que la importación de la administración.
		$form = $this->porter->import( json_decode( (string) $contents, true ) );

		if ( is_wp_error( $form ) ) {
			WP_CLI::error( $form->get_error_message() );
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $form->id );

			return;
		}

		/* translators: %d: identifier of a form. */
		WP_CLI::success( sprintf( __( 'Form imported with the identifier %d. Its actions are paused until you review them.', 'formulario-mensaje' ), $form->id ) );
	}
}
