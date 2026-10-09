<?php
/**
 * Transporte de correo sobre wp_mail().
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Envía los correos con `wp_mail()`, es decir, con el mismo sistema que el resto del sitio.
 */
final class Wp_Mail_Transport implements Mail_Transport {

	/**
	 * Envía un correo.
	 *
	 * @param Email_Message $message Correo ya compuesto.
	 * @return true|WP_Error True si el transporte lo aceptó.
	 */
	public function send( Email_Message $message ): bool|WP_Error {
		$headers = array();

		/*
		 * La dirección de respuesta es el único dato del visitante que llega a una cabecera. Se vuelve
		 * a validar aquí, justo antes de usarla, y se rechaza si lleva saltos de línea.
		 */
		if ( '' !== $message->reply_to && false !== is_email( $message->reply_to ) && 1 !== preg_match( '/[\r\n]/', $message->reply_to ) ) {
			$headers[] = 'Reply-To: <' . $message->reply_to . '>';
		}

		$failure = null;
		$capture = static function ( WP_Error $error ) use ( &$failure ): void {
			$failure = $error;
		};

		add_action( 'wp_mail_failed', $capture );

		$sent = wp_mail(
			array_values( array_filter( $message->to, 'is_email' ) ),
			// Un asunto con saltos de línea permitiría inyectar cabeceras.
			trim( (string) preg_replace( '/[\r\n]+/', ' ', $message->subject ) ),
			$message->body,
			$headers
		);

		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			return true;
		}

		// Del error solo se conserva el mensaje: sus datos incluyen destinatarios y cuerpo del correo.
		return new WP_Error(
			'formulario_mensaje_mail_failed',
			$failure instanceof WP_Error ? $failure->get_error_message() : __( 'The email could not be sent.', 'formulario-mensaje' )
		);
	}
}
