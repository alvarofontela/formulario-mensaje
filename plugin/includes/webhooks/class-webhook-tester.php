<?php
/**
 * Webhooks de prueba.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

use Formulario_Mensaje\Antispam\Rate_Limiter;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Sample_Submission_Factory;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Envía un webhook de un formulario con datos de ejemplo y devuelve lo que respondió el destino.
 *
 * La prueba se hace en el momento, sin pasar por la cola, y no deja rastro en el registro de
 * entregas: por eso tiene un límite por usuario, para que no sirva de sonda contra otros servidores.
 */
final class Webhook_Tester {

	/**
	 * Pruebas que un usuario puede lanzar en cada ventana.
	 */
	private const LIMIT = 20;

	/**
	 * Duración de la ventana del límite, en segundos.
	 */
	private const WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Webhook_Sender            $sender  Envío de webhooks.
	 * @param Sample_Submission_Factory $samples Envíos de ejemplo.
	 * @param Rate_Limiter              $limiter Contador del límite de pruebas por usuario.
	 */
	public function __construct(
		private readonly Webhook_Sender $sender,
		private readonly Sample_Submission_Factory $samples,
		private readonly Rate_Limiter $limiter
	) {}

	/**
	 * Envía un webhook de un formulario con datos de ejemplo.
	 *
	 * @param Form   $form      Formulario, guardado o todavía en edición.
	 * @param string $action_id Identificador del webhook dentro del formulario.
	 * @param int    $user_id   Usuario que lanza la prueba.
	 * @return Webhook_Response|WP_Error
	 */
	public function test( Form $form, string $action_id, int $user_id ): Webhook_Response|WP_Error {
		$action = $form->definition->get_action( $action_id, Webhook_Action::TYPE );

		if ( null === $action ) {
			return new WP_Error( 'formulario_mensaje_action_not_found', __( 'The form has no webhook with that identifier.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		if ( $this->limiter->hit( 'webhook-test:' . $user_id, self::WINDOW ) > self::LIMIT ) {
			return new WP_Error( 'formulario_mensaje_test_limit', __( 'You have sent too many test webhooks. Wait a few minutes and try again.', 'formulario-mensaje' ), array( 'status' => 429 ) );
		}

		return $this->sender->send( $action['settings'], $this->samples->create( $form ), $form, true );
	}
}
