<?php
/**
 * Comprobación antispam: límite de envíos por IP.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Channel;
use Formulario_Mensaje\Submissions\Submission_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Limita cuántos envíos aceptados puede hacer una misma IP a un formulario en un periodo.
 *
 * El hueco se reserva al comprobar, con un contador atómico, y se devuelve si el envío no se acepta:
 * así el límite no se puede saltar con envíos simultáneos, y corregir un error de validación no gasta.
 */
final class Rate_Limit_Check implements Reserving_Check {

	/**
	 * Envíos permitidos por ventana, por defecto.
	 */
	private const DEFAULT_MAX = 5;

	/**
	 * Envíos permitidos por ventana sin JavaScript, por defecto.
	 *
	 * Esa vía no puede traer token ni exigir un tiempo mínimo de relleno (decisión de producto: el
	 * formulario debe funcionar sin JavaScript), así que su límite es más estricto.
	 */
	private const DEFAULT_MAX_FALLBACK = 2;

	/**
	 * Duración de la ventana por defecto, en segundos.
	 */
	private const DEFAULT_WINDOW = 600;

	/**
	 * Constructor.
	 *
	 * @param Rate_Limiter $limiter Contadores.
	 */
	public function __construct( private readonly Rate_Limiter $limiter ) {}

	/**
	 * Decide si un envío puede continuar y, si es así, reserva su hueco.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envía.
	 */
	public function check( Submission_Request $request, Form $form ): ?WP_Error {
		$limit = $this->limit( $request, $form );

		if ( '' === $request->client_ip || $limit['max'] <= 0 ) {
			return null;
		}

		$key = $this->key( $request, $form );

		if ( $this->limiter->hit( $key, $limit['window'] ) <= $limit['max'] ) {
			return null;
		}

		// Este intento no ocupa hueco: solo cuentan los que podían haberse aceptado.
		$this->limiter->release( $key );

		return new WP_Error(
			'formulario_mensaje_rate_limited',
			__( 'You have sent too many messages. Please try again in a few minutes.', 'formulario-mensaje' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Devuelve el hueco reservado para un envío que finalmente no se aceptó.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envió.
	 */
	public function release( Submission_Request $request, Form $form ): void {
		if ( '' !== $request->client_ip && $this->limit( $request, $form )['max'] > 0 ) {
			$this->limiter->release( $this->key( $request, $form ) );
		}
	}

	/**
	 * Límite aplicable a un envío.
	 *
	 * @param Submission_Request $request Envío.
	 * @param Form               $form    Formulario.
	 * @return array{max: int, window: int}
	 */
	private function limit( Submission_Request $request, Form $form ): array {
		$default = array(
			'max'    => Submission_Channel::Fallback === $request->channel ? self::DEFAULT_MAX_FALLBACK : self::DEFAULT_MAX,
			'window' => self::DEFAULT_WINDOW,
		);

		/**
		 * Filtra el límite de envíos por IP de un formulario.
		 *
		 * @since 0.1.0
		 * @since 0.2.0 Se añadió el parámetro `$channel`.
		 *
		 * @param array{max: int, window: int} $limit   Envíos permitidos (`max`; 0 desactiva el límite) en
		 *                                              cada ventana de `window` segundos.
		 * @param Form                         $form    Formulario.
		 * @param string                       $channel Vía del envío: `rest` o `fallback` (sin JavaScript).
		 */
		$limit = apply_filters( 'formulario_mensaje_rate_limit', $default, $form, $request->channel->value );

		return array(
			'max'    => is_array( $limit ) && isset( $limit['max'] ) ? (int) $limit['max'] : $default['max'],
			'window' => is_array( $limit ) && isset( $limit['window'] ) ? max( 1, (int) $limit['window'] ) : $default['window'],
		);
	}

	/**
	 * Clave del contador de una IP en un formulario y una vía.
	 *
	 * La IP no se guarda: la clave es un resumen con la sal del sitio, del que no se puede recuperar.
	 *
	 * @param Submission_Request $request Envío.
	 * @param Form               $form    Formulario.
	 */
	private function key( Submission_Request $request, Form $form ): string {
		return substr( hash_hmac( 'sha256', $form->id . '|' . $request->channel->value . '|' . $request->client_ip, wp_salt( 'nonce' ) ), 0, 40 );
	}
}
