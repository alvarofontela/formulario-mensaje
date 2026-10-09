<?php
/**
 * Comandos de WP-CLI para los envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Cli;

use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Storage\Csv_Exporter;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/*
 * El docblock de la clase y los de sus métodos son la ayuda que muestra WP-CLI (`wp help …`): por eso
 * van en inglés, como el resto de la interfaz, y las notas de diseño van en este comentario.
 *
 * Adaptador fino sobre el mismo exportador que la descarga de la administración.
 */

/**
 * Exports the stored submissions of Formulario Mensaje.
 */
final class Submission_Command {

	/**
	 * Constructor.
	 *
	 * @param Form_Service $forms    Servicio de formularios.
	 * @param Csv_Exporter $exporter Exportación de envíos.
	 * @param File_Access  $files    Escritura de los ficheros que indica quien ejecuta el comando.
	 */
	public function __construct(
		private readonly Form_Service $forms,
		private readonly Csv_Exporter $exporter,
		private readonly File_Access $files
	) {}

	/**
	 * Exports the stored submissions of a form as CSV.
	 *
	 * The file contains personal data. Cells that a spreadsheet would read as a formula are neutralized, as in the export of the admin screen.
	 *
	 * ## OPTIONS
	 *
	 * <form-id>
	 * : Identifier of the form.
	 *
	 * [--from=<date>]
	 * : Only the submissions received on or after this day (YYYY-MM-DD, in the site time zone).
	 *
	 * [--to=<date>]
	 * : Only the submissions received on or before this day (YYYY-MM-DD, in the site time zone).
	 *
	 * [--file=<file>]
	 * : Write the CSV to this file instead of printing it.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp formulario-mensaje submission export 12 --from=2026-01-01 --file=contact.csv
	 *     Success: 48 submissions exported to contact.csv.
	 *
	 * @param string[]              $args       Argumentos posicionales: el identificador del formulario.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function export( array $args, array $assoc_args ): void {
		$form = $this->forms->get( absint( $args[0] ?? 0 ) );

		if ( null === $form ) {
			WP_CLI::error( __( 'The form does not exist.', 'formulario-mensaje' ) );
		}

		$from = $this->day( $assoc_args, 'from', '00:00:00' );
		$to   = $this->day( $assoc_args, 'to', '23:59:59' );
		$file = (string) ( $assoc_args['file'] ?? '' );

		$output = $this->files->open_for_writing( $file );

		if ( null === $output ) {
			/* translators: %s: path of a file. */
			WP_CLI::error( sprintf( __( 'The file %s could not be written.', 'formulario-mensaje' ), $file ) );
		}

		$rows = 0;

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Se escribe fila a fila, para no tener el fichero entero en memoria.
		if ( '' !== $file ) {
			// Marca de orden de bytes: sin ella, Excel no reconoce el fichero como UTF-8.
			fwrite( $output, "\xEF\xBB\xBF" );
		}

		// Las celdas llegan ya neutralizadas contra la inyección de fórmulas (ver Csv_Exporter).
		foreach ( $this->exporter->rows( $form->id, $from, $to ) as $row ) {
			fputcsv( $output, $row, ',', '"', '' );
			++$rows;
		}

		fclose( $output );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Con el CSV en la salida, cualquier otro texto lo estropearía.
		if ( '' !== $file ) {
			$submissions = max( 0, $rows - 1 );

			WP_CLI::success(
				sprintf(
					/* translators: 1: number of submissions, 2: path of a file. */
					_n( '%1$d submission exported to %2$s.', '%1$d submissions exported to %2$s.', $submissions, 'formulario-mensaje' ),
					$submissions,
					$file
				)
			);
		}
	}

	/**
	 * Convierte un día, en la zona horaria del sitio, a una fecha GMT en formato MySQL.
	 *
	 * @param array<string, string> $assoc_args Opciones del comando.
	 * @param string                $name       Nombre de la opción.
	 * @param string                $time       Hora del día que se le añade.
	 * @return string Cadena vacía si la opción no viene.
	 */
	private function day( array $assoc_args, string $name, string $time ): string {
		$date = (string) ( $assoc_args[ $name ] ?? '' );

		if ( '' === $date ) {
			return '';
		}

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			/* translators: %s: name of a command option. */
			WP_CLI::error( sprintf( __( 'The value of --%s is not a date in the YYYY-MM-DD format.', 'formulario-mensaje' ), $name ) );
		}

		return get_gmt_from_date( $date . ' ' . $time );
	}
}
