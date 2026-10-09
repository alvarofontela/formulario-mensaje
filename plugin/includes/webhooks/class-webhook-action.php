<?php
/**
 * Acción: webhook.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

use Formulario_Mensaje\Actions\Action_Type;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Secrets\Secret_Cipher;
use Formulario_Mensaje\Submissions\Submission;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Envía los datos de un envío a la dirección de otro servicio (n8n, un CRM, una hoja de cálculo).
 *
 * Se ejecuta en segundo plano: el visitante no espera a un servicio ajeno, y si el destino falla se
 * reintenta más tarde.
 */
final class Webhook_Action implements Action_Type {

	/**
	 * Identificador del tipo de acción.
	 */
	public const TYPE = 'webhook';

	/**
	 * Métodos HTTP admitidos. Todos llevan cuerpo.
	 */
	public const METHODS = array( 'POST', 'PUT', 'PATCH' );

	private const MAX_HEADERS       = 20;
	private const MAX_PAYLOAD_ITEMS = 50;

	/**
	 * Cabeceras que no se pueden configurar: las fija la petición o cambiarían cómo se interpreta.
	 */
	private const RESERVED_HEADERS = array( 'host', 'content-type', 'content-length', 'transfer-encoding', 'connection', 'expect', 'user-agent' );

	/**
	 * Prefijo de las cabeceras que añade el plugin (referencia, firma), tampoco configurables.
	 */
	private const OWN_HEADER_PREFIX = 'x-formulario-mensaje-';

	/**
	 * Constructor.
	 *
	 * @param Webhook_Sender $sender Envío de webhooks.
	 * @param Secret_Cipher  $cipher Cifrado de secretos, para guardar el de firma.
	 */
	public function __construct(
		private readonly Webhook_Sender $sender,
		private readonly Secret_Cipher $cipher
	) {}

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Webhook', 'formulario-mensaje' );
	}

	/**
	 * Un webhook depende de un servicio ajeno: se ejecuta en segundo plano, con reintentos.
	 */
	public function is_deferred(): bool {
		return true;
	}

	/**
	 * Ajustes iniciales de un webhook nuevo.
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings(): array {
		return array(
			'url'          => '',
			'method'       => 'POST',
			'headers'      => array(),
			'payload_mode' => Webhook_Payload_Builder::MODE_ALL,
			'payload'      => array(),
			'secret'       => '',
		);
	}

	/**
	 * Sanea los ajustes de un webhook antes de guardarlos.
	 *
	 * @param mixed $raw Ajustes sin validar.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( mixed $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$url = is_string( $raw['url'] ?? null ) ? trim( $raw['url'] ) : '';
		// Tiene que venir ya con su protocolo: esc_url_raw() se lo pondría a cualquier texto.
		$url    = 1 === preg_match( '#^https?://#i', $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
		$method = is_string( $raw['method'] ?? null ) ? strtoupper( $raw['method'] ) : '';

		return array(
			'url'          => mb_strlen( $url ) <= 2000 && is_string( wp_parse_url( $url, PHP_URL_HOST ) ) ? $url : '',
			'method'       => in_array( $method, self::METHODS, true ) ? $method : 'POST',
			'headers'      => $this->sanitize_headers( $raw['headers'] ?? array() ),
			'payload_mode' => Webhook_Payload_Builder::MODE_CUSTOM === ( $raw['payload_mode'] ?? null ) ? Webhook_Payload_Builder::MODE_CUSTOM : Webhook_Payload_Builder::MODE_ALL,
			'payload'      => $this->sanitize_payload( $raw['payload'] ?? array() ),
			'secret'       => $this->sanitize_secret( $raw['secret'] ?? '' ),
		);
	}

	/**
	 * Envía el webhook de un envío.
	 *
	 * @param array<string, mixed> $settings   Ajustes guardados de la acción.
	 * @param Submission           $submission Envío ya validado.
	 * @param Form                 $form       Formulario.
	 * @return true|WP_Error True si el destino respondió con un código 2xx.
	 */
	public function execute( array $settings, Submission $submission, Form $form ): bool|WP_Error {
		$response = $this->sender->send( $settings, $submission, $form );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $response->is_success() ) {
			// Solo el código: el cuerpo de la respuesta puede repetir datos del envío y esto acaba en el registro.
			return new WP_Error(
				'formulario_mensaje_webhook_rejected',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'The destination answered with HTTP status %d.', 'formulario-mensaje' ), $response->status_code )
			);
		}

		return true;
	}

	/**
	 * Sanea las cabeceras configuradas.
	 *
	 * @param mixed $raw Lista de cabeceras sin validar.
	 * @return array<int, array{name: string, value: string}>
	 */
	private function sanitize_headers( mixed $raw ): array {
		$headers = array();

		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			$name  = is_array( $item ) && is_string( $item['name'] ?? null ) ? trim( $item['name'] ) : '';
			$lower = strtolower( $name );

			// Un nombre con otros caracteres, o un valor con saltos de línea, permitiría inyectar cabeceras.
			if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/', $name ) || in_array( $lower, self::RESERVED_HEADERS, true ) || str_starts_with( $lower, self::OWN_HEADER_PREFIX ) ) {
				continue;
			}

			$headers[ $lower ] = array(
				'name'  => $name,
				'value' => is_scalar( $item['value'] ?? null ) ? mb_substr( sanitize_text_field( (string) $item['value'] ), 0, 1000 ) : '',
			);

			if ( count( $headers ) >= self::MAX_HEADERS ) {
				break;
			}
		}

		return array_values( $headers );
	}

	/**
	 * Sanea el mapeo de datos: clave que espera el destino y texto con etiquetas que la rellena.
	 *
	 * @param mixed $raw Mapeo sin validar.
	 * @return array<int, array{key: string, value: string}>
	 */
	private function sanitize_payload( mixed $raw ): array {
		$payload = array();

		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			$key = is_array( $item ) && is_string( $item['key'] ?? null ) ? trim( $item['key'] ) : '';

			if ( 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $key ) ) {
				continue;
			}

			$payload[ $key ] = array(
				'key'   => $key,
				'value' => is_scalar( $item['value'] ?? null ) ? mb_substr( sanitize_textarea_field( (string) $item['value'] ), 0, 2000 ) : '',
			);

			if ( count( $payload ) >= self::MAX_PAYLOAD_ITEMS ) {
				break;
			}
		}

		return array_values( $payload );
	}

	/**
	 * Sanea el secreto de firma, que se guarda cifrado dentro de la definición del formulario.
	 *
	 * El constructor nunca recibe el secreto, solo su valor cifrado, y lo devuelve tal cual mientras
	 * no se cambie. Un texto que no sea un valor cifrado por esta instalación es un secreto nuevo.
	 *
	 * @param mixed $raw Valor cifrado guardado, secreto nuevo en claro o cadena vacía.
	 */
	private function sanitize_secret( mixed $raw ): string {
		$raw = is_string( $raw ) ? trim( $raw ) : '';

		if ( '' === $raw ) {
			return '';
		}

		if ( $this->cipher->is_encrypted( $raw ) ) {
			return null === $this->cipher->decrypt( $raw ) ? '' : $raw;
		}

		return $this->cipher->encrypt( mb_substr( $raw, 0, 200 ) );
	}
}
