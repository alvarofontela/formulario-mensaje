<?php
/**
 * Envío de webhooks.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Secrets\Secret_Cipher;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Support\Clock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Hace la petición HTTP de un webhook y devuelve lo que respondió el destino.
 *
 * El destino lo escribe quien gestiona formularios, así que la petición va siempre por la variante
 * segura de la HTTP API, que rechaza las direcciones internas (SSRF), y no sigue redirecciones: un
 * destino que redirige podría llevar los datos a otro sitio.
 *
 * Además, la conexión queda fijada a la dirección que se ha comprobado (ver Destination_Guard): el
 * núcleo valida resolviendo el nombre una vez y la conexión lo resuelve otra, y entre las dos un
 * DNS malintencionado podría cambiar la respuesta. Fijarla solo es posible con cURL; con otro
 * transporte la petición se hace igual, pero no se devuelve nada del cuerpo de la respuesta, que es
 * lo que convertiría una petición a ciegas en una lectura.
 */
final class Webhook_Sender {

	public const HEADER_REFERENCE = 'X-Formulario-Mensaje-Reference';
	public const HEADER_TEST      = 'X-Formulario-Mensaje-Test';

	/**
	 * Segundos de espera de la petición.
	 */
	private const TIMEOUT = 10;

	/**
	 * Bytes de la respuesta que se leen: solo interesa el código y, en las pruebas, el principio del cuerpo.
	 */
	private const RESPONSE_LIMIT = 8192;

	/**
	 * Caracteres de la respuesta que se devuelven.
	 */
	private const BODY_EXCERPT = 2000;

	/**
	 * Constructor.
	 *
	 * @param Webhook_Payload_Builder $payloads     Datos de los webhooks.
	 * @param Webhook_Signer          $signer       Firma de los webhooks.
	 * @param Secret_Cipher           $cipher       Cifrado de secretos, para leer el de firma.
	 * @param Clock                   $clock        Reloj.
	 * @param Destination_Guard       $destinations Dirección a la que se conecta cada petición.
	 * @param string                  $version      Versión del plugin, para identificarse ante el destino.
	 */
	public function __construct(
		private readonly Webhook_Payload_Builder $payloads,
		private readonly Webhook_Signer $signer,
		private readonly Secret_Cipher $cipher,
		private readonly Clock $clock,
		private readonly Destination_Guard $destinations,
		private readonly string $version
	) {}

	/**
	 * Envía un webhook.
	 *
	 * @param array<string, mixed> $settings   Ajustes guardados del webhook.
	 * @param Submission           $submission Envío.
	 * @param Form                 $form       Formulario.
	 * @param bool                 $is_test    Si es una prueba con datos de ejemplo; el destino lo ve en una cabecera.
	 * @return Webhook_Response|WP_Error La respuesta del destino, sea cual sea su código; un error si no se pudo hacer la petición.
	 */
	public function send( array $settings, Submission $submission, Form $form, bool $is_test = false ): Webhook_Response|WP_Error {
		$url = is_string( $settings['url'] ?? null ) ? $settings['url'] : '';

		if ( '' === $url ) {
			return new WP_Error( 'formulario_mensaje_webhook_not_configured', __( 'The webhook has no destination address.', 'formulario-mensaje' ) );
		}

		$body = wp_json_encode( $this->payloads->build( $settings, $submission, $form ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $body ) {
			return new WP_Error( 'formulario_mensaje_webhook_payload', __( 'The submission data could not be converted to JSON.', 'formulario-mensaje' ) );
		}

		$headers = array();

		foreach ( is_array( $settings['headers'] ?? null ) ? $settings['headers'] : array() as $header ) {
			if ( is_array( $header ) && is_string( $header['name'] ?? null ) && '' !== $header['name'] && is_string( $header['value'] ?? null ) ) {
				$headers[ $header['name'] ] = $header['value'];
			}
		}

		// Las cabeceras propias van después: las configuradas no pueden sustituirlas.
		$headers['Content-Type']           = 'application/json; charset=utf-8';
		$headers[ self::HEADER_REFERENCE ] = $submission->reference;

		if ( $is_test ) {
			$headers[ self::HEADER_TEST ] = '1';
		}

		$secret = is_string( $settings['secret'] ?? null ) ? (string) $this->cipher->decrypt( $settings['secret'] ) : '';

		if ( '' !== $secret ) {
			$headers = array_merge( $headers, $this->signer->headers( $body, $secret, $this->clock->now() ) );
		}

		/**
		 * Filtra los segundos de espera de la petición de un webhook.
		 *
		 * @since 0.4.0
		 *
		 * @param int $timeout Segundos de espera.
		 */
		$timeout = max( 1, min( 30, (int) apply_filters( 'formulario_mensaje_webhook_timeout', self::TIMEOUT ) ) );
		$started = microtime( true );
		$pin     = '';
		$pinned  = false;

		/*
		 * Va el último de su filtro: si otro código ya ha respondido a la petición (una caché, un doble
		 * de pruebas), no va a haber conexión que proteger. Si la va a haber, se decide aquí a qué
		 * dirección, y una interna corta la petición con su error.
		 */
		$guard = function ( mixed $preempt ) use ( $url, &$pin ): mixed {
			if ( false !== $preempt ) {
				return $preempt;
			}

			$destination = $this->destinations->pin( $url );

			if ( is_wp_error( $destination ) ) {
				return $destination;
			}

			$pin = $destination;

			return $preempt;
		};

		$connect = static function ( mixed $handle ) use ( &$pin, &$pinned ): void {
			if ( '' !== $pin ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- La petición la hace la HTTP API; aquí solo se le fija la dirección ya comprobada, que no tiene otra vía.
				$pinned = curl_setopt( $handle, CURLOPT_RESOLVE, array( $pin ) );
			}
		};

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hooks del núcleo.
		add_filter( 'pre_http_request', $guard, PHP_INT_MAX );
		add_action( 'http_api_curl', $connect, PHP_INT_MAX );

		$response = wp_safe_remote_request(
			$url,
			array(
				'method'              => in_array( $settings['method'] ?? '', Webhook_Action::METHODS, true ) ? $settings['method'] : 'POST',
				'headers'             => $headers,
				'body'                => $body,
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => self::RESPONSE_LIMIT,
				'user-agent'          => 'Formulario-Mensaje/' . $this->version . '; ' . home_url( '/' ),
			)
		);

		remove_filter( 'pre_http_request', $guard, PHP_INT_MAX );
		remove_action( 'http_api_curl', $connect, PHP_INT_MAX );
		// phpcs:enable

		if ( is_wp_error( $response ) ) {
			// El mensaje describe el fallo de red o por qué la dirección no es segura; no lleva datos del envío.
			return new WP_Error( 'formulario_mensaje_webhook_failed', mb_substr( sanitize_text_field( $response->get_error_message() ), 0, 200 ) );
		}

		// Había una dirección que fijar y la conexión no ha pasado por cURL: no se ha podido fijar, así que
		// del destino solo se cuenta el código con el que respondió.
		$body = '' !== $pin && ! $pinned ? '' : wp_remote_retrieve_body( $response );

		return new Webhook_Response(
			(int) wp_remote_retrieve_response_code( $response ),
			mb_substr( wp_check_invalid_utf8( $body, true ), 0, self::BODY_EXCERPT ),
			(int) round( ( microtime( true ) - $started ) * 1000 )
		);
	}
}
