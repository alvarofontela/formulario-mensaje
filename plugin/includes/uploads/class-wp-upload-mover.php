<?php
/**
 * Movimiento de ficheros subidos con la API de WordPress.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Mueve un fichero subido con `wp_handle_upload()`, que comprueba que llegó por una subida de verdad
 * y que su contenido real corresponde a uno de los tipos permitidos.
 */
final class Wp_Upload_Mover implements Upload_Mover {

	/**
	 * Comprueba y mueve un fichero.
	 *
	 * @param Uploaded_File         $file      Fichero recibido.
	 * @param string                $directory Directorio de destino, que ya existe.
	 * @param string                $filename  Nombre con el que se guarda.
	 * @param array<string, string> $mimes     Tipos permitidos, por extensión.
	 * @return array{path: string, type: string}|WP_Error
	 */
	public function move( Uploaded_File $file, string $directory, string $filename, array $mimes ): array|WP_Error {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// WordPress guardaría el fichero en la carpeta pública de subidas del mes: se le indica la del plugin.
		$destination = static function ( array $uploads ) use ( $directory ): array {
			$uploads['path']   = $directory;
			$uploads['subdir'] = '';

			return $uploads;
		};

		add_filter( 'upload_dir', $destination );

		$data   = $file->to_files_array();
		$result = wp_handle_upload(
			$data,
			array(
				// La petición no viene de un formulario de la administración: quién puede enviar lo deciden las defensas del envío.
				'test_form'                => false,
				'test_type'                => true,
				'mimes'                    => $mimes,
				// El nombre lo pone el plugin: uno aleatorio, sin nada del original.
				'unique_filename_callback' => static fn (): string => $filename,
			)
		);

		remove_filter( 'upload_dir', $destination );

		if ( ! is_array( $result ) || isset( $result['error'] ) || ! is_string( $result['file'] ?? null ) ) {
			return new WP_Error( 'formulario_mensaje_upload_failed', is_array( $result ) && is_string( $result['error'] ?? null ) ? $result['error'] : __( 'The file could not be stored.', 'formulario-mensaje' ) );
		}

		return array(
			'path' => $result['file'],
			'type' => is_string( $result['type'] ?? null ) ? $result['type'] : '',
		);
	}
}
