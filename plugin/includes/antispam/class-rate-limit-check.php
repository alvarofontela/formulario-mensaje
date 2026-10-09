<?php
/**
 * Comprobación antispam: límite de envíos por IP.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Limita cuántos envíos aceptados puede hacer una misma IP a un formulario en un periodo.
 */
final class Rate_Limit_Check implements Spam_Check, Submission_Recorder {

	/**
	 * Envíos permitidos por ventana, por defecto.
	 */
	private const DEFAULT_MAX = 5;

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
	 * Decide si un envío puede continuar.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envía.
	 */
	public function check( Submission_Request $request, Form $form ): ?WP_Error {
		$limit = $this->limit( $form );

		if ( '' === $request->client_ip || $limit['max'] <= 0 || $this->limiter->count( $this->key( $request, $form ) ) < $limit['max'] ) {
			return null;
		}

		return new WP_Error(
			'formulario_mensaje_rate_limited',
			__( 'You have sent too many messages. Please try again in a few minutes.', 'formulario-mensaje' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Cuenta un envío aceptado.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envió.
	 */
	public function record( Submission_Request $request, Form $form ): void {
		$limit = $this->limit( $form );

		if ( '' !== $request->client_ip && $limit['max'] > 0 ) {
			$this->limiter->hit( $this->key( $request, $form ), $limit['window'] );
		}
	}

	/**
	 * Límite aplicable a un formulario.
	 *
	 * @param Form $form Formulario.
	 * @return array{max: int, window: int}
	 */
	private function limit( Form $form ): array {
		/**
		 * Filtra el límite de envíos por IP de un formulario.
		 *
		 * @since 0.1.0
		 *
		 * @param array{max: int, window: int} $limit Envíos permitidos (`max`; 0 desactiva el límite) en
		 *                                            cada ventana de `window` segundos.
		 * @param Form                         $form  Formulario.
		 */
		$limit = apply_filters(
			'formulario_mensaje_rate_limit',
			array(
				'max'    => self::DEFAULT_MAX,
				'window' => self::DEFAULT_WINDOW,
			),
			$form
		);

		return array(
			'max'    => is_array( $limit ) && isset( $limit['max'] ) ? (int) $limit['max'] : self::DEFAULT_MAX,
			'window' => is_array( $limit ) && isset( $limit['window'] ) ? max( 1, (int) $limit['window'] ) : self::DEFAULT_WINDOW,
		);
	}

	/**
	 * Clave del contador de una IP en un formulario.
	 *
	 * La IP no se guarda: la clave es un resumen con la sal del sitio, del que no se puede recuperar.
	 *
	 * @param Submission_Request $request Envío.
	 * @param Form               $form    Formulario.
	 */
	private function key( Submission_Request $request, Form $form ): string {
		return substr( hash_hmac( 'sha256', $form->id . '|' . $request->client_ip, wp_salt( 'nonce' ) ), 0, 40 );
	}
}
