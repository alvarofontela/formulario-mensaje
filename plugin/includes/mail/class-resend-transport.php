<?php
/**
 * Transporte de correo por la API de Resend.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use Formulario_Mensaje\Secrets\Secret_Vault;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Envía los correos del plugin por la API HTTP de Resend.
 */
final class Resend_Transport implements Mail_Transport {

	/**
	 * Dirección de la API. Es fija: no depende de ningún ajuste ni de la petición.
	 */
	private const ENDPOINT = 'https://api.resend.com/emails';

	/**
	 * Segundos de espera de la respuesta.
	 */
	private const TIMEOUT = 15;

	/**
	 * Constructor.
	 *
	 * @param Mail_Settings_Repository $settings Ajustes de correo.
	 * @param Secret_Vault             $secrets  Almacén de secretos, con la clave de la API.
	 */
	public function __construct(
		private readonly Mail_Settings_Repository $settings,
		private readonly Secret_Vault $secrets
	) {}

	/**
	 * Envía un correo.
	 *
	 * @param Email_Message $message Correo ya compuesto.
	 * @return true|WP_Error True si Resend lo aceptó; el error no contiene el cuerpo del correo ni la clave.
	 */
	public function send( Email_Message $message ): bool|WP_Error {
		$settings = $this->settings->get();
		$api_key  = $this->secrets->get( Mail_Settings::SECRET_RESEND_API_KEY );

		if ( '' === $api_key || '' === $settings->from_email ) {
			return new WP_Error(
				'formulario_mensaje_resend_not_configured',
				__( 'Resend needs an API key and a sender address of a domain verified in Resend.', 'formulario-mensaje' )
			);
		}

		$payload = array(
			'from'    => sprintf( '%s <%s>', str_replace( array( '<', '>', '"' ), '', $settings->sender_name() ), $settings->from_email ),
			'to'      => $message->safe_recipients(),
			'subject' => $message->safe_subject(),
			'text'    => $message->body,
		);

		if ( $message->has_html() ) {
			$payload['html'] = $message->html;
		}

		if ( '' !== $message->safe_reply_to() ) {
			$payload['reply_to'] = $message->safe_reply_to();
		}

		foreach ( $message->safe_attachments() as $name => $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichero local del almacén del plugin, no una URL.
			$content = file_get_contents( $path );

			if ( false !== $content ) {
				$payload['attachments'][] = array(
					'filename' => $name,
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Es el formato en el que la API de Resend recibe los adjuntos.
					'content'  => base64_encode( $content ),
				);
			}
		}

		// La dirección es fija, pero se usa la variante segura por sistema; no se siguen redirecciones.
		$response = wp_safe_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'        => (string) wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'formulario_mensaje_resend_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return true;
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$detail = is_array( $body ) && is_string( $body['message'] ?? null ) ? $body['message'] : '';

		return new WP_Error(
			'formulario_mensaje_resend_failed',
			mb_substr(
				sprintf(
					/* translators: 1: HTTP status code, 2: error message returned by Resend. */
					__( 'Resend answered with error %1$d. %2$s', 'formulario-mensaje' ),
					$status,
					sanitize_text_field( $detail )
				),
				0,
				300
			)
		);
	}
}
