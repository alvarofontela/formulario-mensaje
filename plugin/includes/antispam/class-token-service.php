<?php
/**
 * Token antispam.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Support\Clock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Emite y verifica el token antispam: una marca de tiempo firmada.
 *
 * El script del formulario lo pide cuando el visitante empieza a interactuar y lo devuelve con el
 * envío. Demuestra que se ejecutó JavaScript y permite exigir un tiempo mínimo de relleno. No se
 * guarda nada en el servidor ni en el HTML, así que es compatible con la caché de página completa.
 */
final class Token_Service {

	/**
	 * Segundos que deben pasar, por defecto, entre la emisión del token y el envío.
	 */
	private const DEFAULT_MIN_SECONDS = 3;

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Reloj.
	 */
	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Emite un token para un formulario.
	 *
	 * @param int $form_id Identificador del formulario.
	 */
	public function issue( int $form_id ): string {
		$issued_at = $this->clock->now();

		return $issued_at . '.' . $this->sign( $form_id, $issued_at );
	}

	/**
	 * Verifica un token.
	 *
	 * @param string $token   Token recibido con el envío.
	 * @param int    $form_id Identificador del formulario al que se envía.
	 * @return WP_Error|null Null si es válido; un error con código `formulario_mensaje_token_invalid`,
	 *                       `formulario_mensaje_token_expired` o `formulario_mensaje_too_fast` si no.
	 */
	public function verify( string $token, int $form_id ): ?WP_Error {
		if ( 1 !== preg_match( '/^(\d{1,12})\.([a-f0-9]{64})$/', $token, $parts ) ) {
			return new WP_Error( 'formulario_mensaje_token_invalid', 'Invalid token.' );
		}

		$issued_at = (int) $parts[1];

		if ( ! hash_equals( $this->sign( $form_id, $issued_at ), $parts[2] ) ) {
			return new WP_Error( 'formulario_mensaje_token_invalid', 'Invalid token.' );
		}

		$age = $this->clock->now() - $issued_at;

		if ( $age > $this->max_age() ) {
			return new WP_Error( 'formulario_mensaje_token_expired', 'Expired token.' );
		}

		if ( $age < $this->min_seconds() ) {
			return new WP_Error( 'formulario_mensaje_too_fast', 'Submitted too fast.' );
		}

		return null;
	}

	/**
	 * Segundos que deben pasar entre la emisión del token y el envío.
	 */
	public function min_seconds(): int {
		/**
		 * Filtra el tiempo mínimo de relleno de un formulario, en segundos.
		 *
		 * @since 0.1.0
		 *
		 * @param int $seconds Segundos entre la primera interacción y el envío. 0 lo desactiva.
		 */
		return max( 0, (int) apply_filters( 'formulario_mensaje_min_fill_seconds', self::DEFAULT_MIN_SECONDS ) );
	}

	/**
	 * Segundos durante los que un token es válido.
	 */
	private function max_age(): int {
		/**
		 * Filtra la validez del token antispam, en segundos.
		 *
		 * @since 0.1.0
		 *
		 * @param int $seconds Segundos de validez desde la primera interacción con el formulario.
		 */
		return max( 60, (int) apply_filters( 'formulario_mensaje_token_max_age', DAY_IN_SECONDS ) );
	}

	/**
	 * Firma de un token.
	 *
	 * @param int $form_id   Identificador del formulario.
	 * @param int $issued_at Marca de tiempo de emisión.
	 */
	private function sign( int $form_id, int $issued_at ): string {
		return hash_hmac( 'sha256', 'formulario-mensaje-token|' . $form_id . '|' . $issued_at, wp_salt( 'nonce' ) );
	}
}
