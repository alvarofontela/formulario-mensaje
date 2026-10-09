<?php
/**
 * Enlaces de descarga.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Compone y comprueba los enlaces con los que se descarga un fichero de un envío.
 *
 * Hay dos: el de la administración, que exige sesión con el permiso de ver envíos, y el firmado, que
 * va en una notificación y lleva su propia prueba: una firma que solo este sitio puede generar y una
 * fecha de caducidad que forma parte de lo firmado.
 */
final class Download_Links {

	/**
	 * Acción de `admin-post.php` que sirve las descargas.
	 */
	public const ACTION = 'formulario_mensaje_file';

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Reloj.
	 */
	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Enlace firmado y con caducidad a un fichero, para una notificación.
	 *
	 * @param Stored_File $file Fichero.
	 * @param int         $days Días durante los que vale el enlace.
	 */
	public function signed( Stored_File $file, int $days ): string {
		$expires = $this->clock->now() + max( 1, $days ) * DAY_IN_SECONDS;

		return add_query_arg(
			array(
				'action'  => self::ACTION,
				'file'    => $file->token,
				'expires' => $expires,
				'sig'     => $this->signature( $file->token, $expires ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Enlace a un fichero para quien tiene sesión en la administración.
	 *
	 * @param Stored_File $file Fichero.
	 */
	public function admin( Stored_File $file ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'file'     => $file->token,
				'_wpnonce' => wp_create_nonce( $this->nonce_action( $file->token ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Indica si la firma de un enlace es la de ese fichero y esa caducidad, y si aún no ha caducado.
	 *
	 * @param string $token     Identificador aleatorio del fichero.
	 * @param int    $expires   Marca de tiempo Unix de la caducidad que trae el enlace.
	 * @param string $signature Firma que trae el enlace.
	 */
	public function is_valid_signature( string $token, int $expires, string $signature ): bool {
		return $expires >= $this->clock->now() && hash_equals( $this->signature( $token, $expires ), $signature );
	}

	/**
	 * Acción del nonce del enlace de administración de un fichero.
	 *
	 * @param string $token Identificador aleatorio del fichero.
	 */
	public function nonce_action( string $token ): string {
		return self::ACTION . '_' . $token;
	}

	/**
	 * Firma de un fichero y una caducidad, con una clave derivada de las salts de WordPress.
	 *
	 * @param string $token   Identificador aleatorio del fichero.
	 * @param int    $expires Marca de tiempo Unix de la caducidad.
	 */
	private function signature( string $token, int $expires ): string {
		return hash_hmac( 'sha256', 'formulario-mensaje-file|' . $token . '|' . $expires, wp_salt( 'auth' ) );
	}
}
