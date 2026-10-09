<?php
/**
 * Descarga de la exportación de envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Admin;

use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Storage\Csv_Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador de la descarga en CSV de los envíos de un formulario, por `admin-post.php`.
 */
final class Export_Controller {

	/**
	 * Valor del parámetro `action` y acción del nonce.
	 */
	public const ACTION = 'formulario_mensaje_export';

	/**
	 * Constructor.
	 *
	 * @param Csv_Exporter    $exporter Exportación de envíos.
	 * @param Form_Repository $forms    Almacén de formularios.
	 */
	public function __construct(
		private readonly Csv_Exporter $exporter,
		private readonly Form_Repository $forms
	) {}

	/**
	 * Envía el fichero CSV y termina la petición.
	 */
	public function handle(): void {
		// La exportación saca datos personales: capability antes que nada, y nonce contra el CSRF.
		if ( ! current_user_can( Capabilities::VIEW_SUBMISSIONS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to view submissions.', 'formulario-mensaje' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$form = $this->forms->find( isset( $_GET['form'] ) ? absint( wp_unslash( $_GET['form'] ) ) : 0 );

		if ( null === $form ) {
			wp_die( esc_html__( 'The form does not exist.', 'formulario-mensaje' ), '', array( 'response' => 404 ) );
		}

		$from = $this->date_param( 'from', '00:00:00' );
		$to   = $this->date_param( 'to', '23:59:59' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		// El nombre del fichero sale del nombre del formulario: se limpia antes de ir a la cabecera.
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $form->title . '-' . wp_date( 'Y-m-d' ) ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		/*
		 * Se escribe directamente en la salida, fila a fila, para no tener el fichero entero en memoria.
		 * WP_Filesystem no sirve para un flujo de salida.
		 */
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$output = fopen( 'php://output', 'w' );

		if ( false !== $output ) {
			// Marca de orden de bytes: sin ella, Excel no reconoce el fichero como UTF-8.
			fwrite( $output, "\xEF\xBB\xBF" );

			// Las celdas llegan ya neutralizadas contra la inyección de fórmulas (ver Csv_Exporter).
			foreach ( $this->exporter->rows( $form->id, $from, $to ) as $row ) {
				fputcsv( $output, $row, ',', '"', '' );
			}

			fclose( $output );
		}
		// phpcs:enable

		exit;
	}

	/**
	 * Lee una fecha de la petición (`AAAA-MM-DD`, en la zona horaria del sitio) y la convierte a GMT.
	 *
	 * @param string $name Nombre del parámetro.
	 * @param string $time Hora del día que se le añade.
	 * @return string Fecha y hora en GMT (formato MySQL), o cadena vacía si no hay fecha válida.
	 */
	private function date_param( string $name, string $time ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El nonce se verifica en handle() antes de llamar aquí.
		$date = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return '';
		}

		return get_gmt_from_date( $date . ' ' . $time );
	}
}
