<?php
/**
 * Cliente de las rutas de verificación de los servicios de captcha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Pregunta a un servicio de captcha si un testigo es válido.
 *
 * Turnstile, reCAPTCHA y hCaptcha comparten el protocolo: un POST con la clave secreta, el testigo
 * y la IP del visitante, y una respuesta en JSON con `success`. La dirección la fija cada proveedor
 * en su código; nunca sale de los ajustes ni de la petición.
 */
final class Siteverify_Client {

	/**
	 * Código de error cuando el servicio no ha respondido: no se sabe si el testigo es bueno.
	 */
	public const ERROR_UNAVAILABLE = 'formulario_mensaje_captcha_unavailable';

	/**
	 * Segundos de espera de la petición. El visitante está esperando la respuesta del formulario.
	 */
	private const TIMEOUT = 8;

	/**
	 * Hace la consulta.
	 *
	 * @param string $url       Dirección de verificación del servicio.
	 * @param string $secret    Clave secreta del sitio en el servicio.
	 * @param string $token     Testigo recibido con el envío.
	 * @param string $remote_ip IP del visitante, o cadena vacía.
	 * @return array<string, mixed>|WP_Error La respuesta del servicio ya decodificada; un error si no ha respondido como se espera.
	 */
	public function verify( string $url, string $secret, string $token, string $remote_ip ): array|WP_Error {
		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);

		if ( '' !== $remote_ip ) {
			$body['remoteip'] = $remote_ip;
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'body'                => $body,
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => 8192,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( self::ERROR_UNAVAILABLE, mb_substr( sanitize_text_field( $response->get_error_message() ), 0, 200 ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || ! array_key_exists( 'success', $data ) ) {
			return new WP_Error( self::ERROR_UNAVAILABLE, 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) );
		}

		return $data;
	}

	/**
	 * Códigos de error que devuelve el servicio en una respuesta, como texto para el diagnóstico.
	 *
	 * @param array<string, mixed> $data Respuesta del servicio.
	 */
	public function error_codes( array $data ): string {
		$codes = is_array( $data['error-codes'] ?? null ) ? array_filter( $data['error-codes'], 'is_string' ) : array();

		return mb_substr( sanitize_text_field( implode( ', ', $codes ) ), 0, 200 );
	}
}
