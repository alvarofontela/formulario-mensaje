<?php
/**
 * Correos de prueba.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use Formulario_Mensaje\Antispam\Rate_Limiter;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Sample_Submission_Factory;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Envía correos de prueba: uno genérico para comprobar el transporte, y cualquier notificación de un
 * formulario con datos de ejemplo.
 *
 * Una prueba va siempre a la dirección que indica quien la lanza, nunca a los destinatarios reales
 * de la notificación.
 *
 * Probar una notificación pide el mismo permiso que editarla: quien gestiona formularios ya decide
 * a quién escriben y con qué texto, así que la prueba no le da nada que no tenga guardando el
 * formulario y enviándolo. Lo que sí evita el límite por usuario es que la prueba, que no deja
 * rastro en el registro de entregas, sirva para enviar correo en cantidad.
 */
final class Mail_Tester {

	/**
	 * Pruebas que un usuario puede lanzar en cada ventana.
	 */
	private const LIMIT = 10;

	/**
	 * Duración de la ventana del límite, en segundos.
	 */
	private const WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Mail_Transport            $transport Transporte de correo.
	 * @param Email_Template            $template  Plantilla HTML con la marca.
	 * @param Email_Action              $action    Acción de notificación por correo.
	 * @param Sample_Submission_Factory $samples   Envíos de ejemplo.
	 * @param Rate_Limiter              $limiter   Contador del límite de pruebas por usuario.
	 */
	public function __construct(
		private readonly Mail_Transport $transport,
		private readonly Email_Template $template,
		private readonly Email_Action $action,
		private readonly Sample_Submission_Factory $samples,
		private readonly Rate_Limiter $limiter
	) {}

	/**
	 * Envía un correo genérico para comprobar que el transporte configurado funciona.
	 *
	 * @param string $to      Dirección a la que se envía la prueba.
	 * @param int    $user_id Usuario que lanza la prueba.
	 * @return true|WP_Error El error lleva el mensaje del transporte, para poder diagnosticar la configuración.
	 */
	public function send_transport_test( string $to, int $user_id ): bool|WP_Error {
		$rejection = $this->check( $to, $user_id );

		if ( null !== $rejection ) {
			return $rejection;
		}

		$subject = sprintf(
			/* translators: %s: name of the site. */
			__( 'Test email from %s', 'formulario-mensaje' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$text = __( 'If you are reading this, Formulario Mensaje emails are being delivered with the current settings.', 'formulario-mensaje' );

		return $this->transport->send(
			new Email_Message( array( $to ), $subject, $text, '', $this->template->render( '<p style="margin:0;">' . esc_html( $text ) . '</p>', $subject ) )
		);
	}

	/**
	 * Envía una notificación de un formulario con datos de ejemplo.
	 *
	 * @param Form   $form      Formulario, guardado o todavía en edición.
	 * @param string $action_id Identificador de la notificación dentro del formulario.
	 * @param string $to        Dirección a la que se envía la prueba.
	 * @param int    $user_id   Usuario que lanza la prueba.
	 * @return true|WP_Error
	 */
	public function send_notification_test( Form $form, string $action_id, string $to, int $user_id ): bool|WP_Error {
		$rejection = $this->check( $to, $user_id );

		if ( null !== $rejection ) {
			return $rejection;
		}

		$action = $form->definition->get_action( $action_id, Email_Action::TYPE );

		if ( null === $action ) {
			return new WP_Error( 'formulario_mensaje_action_not_found', __( 'The form has no notification with that identifier.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		$settings = $action['settings'];

		// La prueba sustituye a los destinatarios reales y se distingue por el asunto.
		$settings['to']      = array( $to );
		$settings['subject'] = sprintf(
			/* translators: %s: subject of the notification. */
			__( '[Test] %s', 'formulario-mensaje' ),
			is_string( $settings['subject'] ?? null ) ? $settings['subject'] : ''
		);

		return $this->action->execute( $settings, $this->samples->create( $form ), $form );
	}

	/**
	 * Comprueba la dirección de la prueba y que el usuario no haya agotado su límite.
	 *
	 * @param string $to      Dirección a la que se envía la prueba.
	 * @param int    $user_id Usuario que lanza la prueba.
	 * @return WP_Error|null Null si la prueba se puede enviar.
	 */
	private function check( string $to, int $user_id ): ?WP_Error {
		if ( false === is_email( $to ) ) {
			return new WP_Error( 'formulario_mensaje_invalid_test_address', __( 'Enter a valid email address for the test.', 'formulario-mensaje' ), array( 'status' => 400 ) );
		}

		if ( $this->limiter->hit( 'mail-test:' . $user_id, self::WINDOW ) > self::LIMIT ) {
			return new WP_Error( 'formulario_mensaje_test_limit', __( 'You have sent too many test emails. Wait a few minutes and try again.', 'formulario-mensaje' ), array( 'status' => 429 ) );
		}

		return null;
	}
}
