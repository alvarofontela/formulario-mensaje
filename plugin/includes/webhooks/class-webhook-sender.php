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
use WP_HTTP_Proxy;

defined( 'ABSPATH' ) || exit;

/**
 * Hace la petición HTTP de un webhook y devuelve lo que respondió el destino.
 *
 * El destino lo escribe quien gestiona formularios, así que la petición va siempre por la variante
 * segura de la HTTP API, que rechaza las direcciones internas (SSRF), y no sigue redirecciones: un
 * destino que redirige podría llevar los datos a otro sitio.
 *
 * Además, la conexión se obliga a ir a la dirección que se ha comprobado (ver Destination_Guard): el
 * núcleo valida resolviendo el nombre una vez y la conexión lo resuelve otra, y entre las dos un
 * DNS malintencionado podría cambiar la respuesta. Eso solo se puede hacer con cURL: sin él, la
 * petición a un destino con nombre no se envía, y si después no consta que la conexión fue a esa
 * dirección, la respuesta se descarta con un error. La única salvedad es un proxy de salida, donde
 * quien resuelve y se conecta es el proxy: la petición se hace, pero del destino solo se cuenta el
 * código, nunca el cuerpo, que es lo que convertiría una petición a ciegas en una lectura.
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
		$address = '';
		$handle  = null;
		$proxied = $this->goes_through_proxy( $url );

		/*
		 * Va el último de su filtro: si otro código ya ha respondido a la petición (una caché, un doble
		 * de pruebas), no va a haber conexión que proteger. Si la va a haber, se decide aquí a qué
		 * dirección, y una interna corta la petición con su error.
		 *
		 * Con un proxy de salida es el proxy quien resuelve el nombre y se conecta, no este servidor:
		 * no hay conexión propia que obligar, y a qué redes llega es cosa de la configuración del proxy.
		 * La dirección se comprueba igual desde aquí, y si pasa, la petición se hace, pero de la
		 * respuesta solo se cuenta el código (ver más abajo).
		 */
		$guard = function ( mixed $preempt ) use ( $url, $proxied, &$address ): mixed {
			if ( false !== $preempt ) {
				return $preempt;
			}

			$destination = $this->destinations->address( $url );

			if ( is_wp_error( $destination ) ) {
				return $destination;
			}

			if ( $proxied ) {
				return $preempt;
			}

			// Si la conexión no se puede obligar a ir a la dirección comprobada, volvería a resolver el
			// nombre por su cuenta: la petición no se envía.
			if ( '' !== $destination && ! $this->can_force_connection( $url ) ) {
				return new WP_Error( 'formulario_mensaje_webhook_unpinned', __( 'This server cannot send webhooks safely: PHP has no cURL to keep the connection on the address that was checked.', 'formulario-mensaje' ) );
			}

			$address = $destination;

			return $preempt;
		};

		/*
		 * La conexión va a la dirección comprobada sea cual sea el nombre de la petición: no depende de
		 * que cURL lo escriba igual que aquí. El puerto es el de la dirección del webhook. Si la opción
		 * no se puede poner, la petición se deja sin dirección para que falle antes de conectarse.
		 */
		$connect = static function ( mixed $curl ) use ( &$address, &$handle ): void {
			if ( '' === $address ) {
				return;
			}

			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- La petición la hace la HTTP API; aquí solo se la obliga a ir a la dirección ya comprobada, que no tiene otra vía.
			if ( curl_setopt( $curl, CURLOPT_CONNECT_TO, array( '::' . $address . ':' ) ) ) {
				$handle = $curl;
			} else {
				curl_setopt( $curl, CURLOPT_URL, '' );
			}
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt
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

		/*
		 * Había una dirección a la que obligar la conexión: si no consta que la petición fue a ella
		 * (no pasó por cURL, o se conectó a otra), no se da por buena ni se cuenta nada de la respuesta.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Solo se lee a qué dirección se conectó la petición que ha hecho la HTTP API.
		if ( '' !== $address && ( null === $handle || curl_getinfo( $handle, CURLINFO_PRIMARY_IP ) !== $address ) ) {
			return new WP_Error( 'formulario_mensaje_webhook_unpinned', __( 'The webhook connection could not be kept on the address that was checked, so its response was discarded.', 'formulario-mensaje' ) );
		}

		// A través de un proxy no se sabe a qué dirección llegó la petición: solo se cuenta el código.
		$body = $proxied ? '' : wp_remote_retrieve_body( $response );

		return new Webhook_Response(
			(int) wp_remote_retrieve_response_code( $response ),
			mb_substr( wp_check_invalid_utf8( $body, true ), 0, self::BODY_EXCERPT ),
			(int) round( ( microtime( true ) - $started ) * 1000 )
		);
	}

	/**
	 * Indica si la petición a una dirección saldrá por el proxy configurado en WordPress.
	 *
	 * @param string $url Dirección del webhook.
	 */
	private function goes_through_proxy( string $url ): bool {
		$proxy = new WP_HTTP_Proxy();

		return $proxy->is_enabled() && $proxy->send_through_proxy( $url );
	}

	/**
	 * Indica si la petición se hará con cURL, que es lo único que deja obligar a la conexión a ir a
	 * una dirección concreta. Es el mismo criterio con el que la HTTP API elige cURL.
	 *
	 * @param string $url Dirección del webhook.
	 */
	private function can_force_connection( string $url ): bool {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) || ! defined( 'CURLOPT_CONNECT_TO' ) ) {
			return false;
		}

		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_version -- Solo se consulta si el cURL de PHP sabe hacer conexiones cifradas.
		$version = curl_version();

		return is_array( $version ) && 0 !== ( (int) $version['features'] & CURL_VERSION_SSL );
	}
}
