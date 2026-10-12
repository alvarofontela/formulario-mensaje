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
	 * Constructor.
	 *
	 * @param Mail_Settings_Repository $settings Ajustes de correo.
	 */
	public function __construct( private readonly Mail_Settings_Repository $settings ) {}

	/**
	 * Envía un correo.
	 *
	 * @param Email_Message $message Correo ya compuesto.
	 * @return true|WP_Error True si el transporte lo aceptó.
	 */
	public function send( Email_Message $message ): bool|WP_Error {
		$settings = $this->settings->get();
		$headers  = array();
		$reply_to = $message->safe_reply_to();

		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: <' . $reply_to . '>';
		}

		// El remitente sale de los ajustes del plugin, nunca del visitante.
		if ( '' !== $settings->from_email ) {
			$headers[] = sprintf( 'From: "%s" <%s>', str_replace( '"', '', $settings->sender_name() ), $settings->from_email );
		}

		if ( $message->has_html() ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		$failure = null;
		$capture = static function ( WP_Error $error ) use ( &$failure ): void {
			$failure = $error;
		};

		// wp_mail() no tiene parámetro para la versión en texto de un correo HTML: se añade al prepararlo.
		$alternative = static function ( $phpmailer ) use ( $message ): void {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Propiedad de PHPMailer.
			$phpmailer->AltBody = $message->body;
		};

		// Con nombre de remitente pero sin dirección propia no hay cabecera «From» que lo lleve: se le da a
		// wp_mail() por su filtro, para que acompañe a la dirección del sitio. El nombre sale de los ajustes
		// del plugin, nunca del visitante, y sender_name() le quita los saltos de línea y los delimitadores.
		$named       = '' === $settings->from_email && '' !== $settings->from_name;
		$sender_name = static fn (): string => $settings->sender_name();

		add_action( 'wp_mail_failed', $capture );

		if ( $message->has_html() ) {
			add_action( 'phpmailer_init', $alternative );
		}

		if ( $named ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro del núcleo.
			add_filter( 'wp_mail_from_name', $sender_name );
		}

		$sent = wp_mail(
			$message->safe_recipients(),
			$message->safe_subject(),
			$message->has_html() ? $message->html : $message->body,
			$headers,
			$message->safe_attachments()
		);

		remove_action( 'wp_mail_failed', $capture );
		remove_action( 'phpmailer_init', $alternative );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro del núcleo.
		remove_filter( 'wp_mail_from_name', $sender_name );

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
