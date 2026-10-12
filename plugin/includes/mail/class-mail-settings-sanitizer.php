<?php
/**
 * Saneado de los ajustes de correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use Formulario_Mensaje\Support\Plain_Text;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte datos sin validar (de la base de datos o de una petición) en unos ajustes de correo válidos.
 */
final class Mail_Settings_Sanitizer {

	/**
	 * Constructor.
	 *
	 * @param string[] $transports Identificadores de los transportes disponibles.
	 */
	public function __construct( private readonly array $transports ) {}

	/**
	 * Sanea unos ajustes de correo.
	 *
	 * @param mixed $raw Datos sin validar.
	 */
	public function sanitize( mixed $raw ): Mail_Settings {
		$raw        = is_array( $raw ) ? $raw : array();
		$transport  = $this->text( $raw, 'transport' );
		$encryption = $this->text( $raw, 'smtp_encryption' );
		$from       = sanitize_email( $this->text( $raw, 'from_email' ) );
		$color      = sanitize_hex_color( $this->text( $raw, 'brand_color' ) );
		$port       = is_numeric( $raw['smtp_port'] ?? null ) ? (int) $raw['smtp_port'] : 587;

		return new Mail_Settings(
			in_array( $transport, $this->transports, true ) ? $transport : Mail_Settings::TRANSPORT_WP_MAIL,
			false !== is_email( $from ) ? $from : '',
			// El nombre del remitente acaba en una cabecera: sin saltos de línea ni caracteres de control.
			mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F<>"]+/', ' ', $this->text( $raw, 'from_name' ) ), 0, 100 ),
			$this->sanitize_host( $this->text( $raw, 'smtp_host' ) ),
			$port >= 1 && $port <= 65535 ? $port : 587,
			in_array( $encryption, array( Mail_Settings::ENCRYPTION_NONE, Mail_Settings::ENCRYPTION_SSL, Mail_Settings::ENCRYPTION_TLS ), true ) ? $encryption : Mail_Settings::ENCRYPTION_TLS,
			! array_key_exists( 'smtp_auth', $raw ) || ! empty( $raw['smtp_auth'] ),
			mb_substr( $this->text( $raw, 'smtp_username' ), 0, 200 ),
			esc_url_raw( $this->text( $raw, 'brand_logo_url' ), array( 'http', 'https' ) ),
			is_string( $color ) && '' !== $color ? $color : Mail_Settings::DEFAULT_BRAND_COLOR,
			is_scalar( $raw['brand_footer'] ?? null ) ? mb_substr( Plain_Text::block( (string) $raw['brand_footer'] ), 0, 1000 ) : ''
		);
	}

	/**
	 * Lee un ajuste de texto de una sola línea.
	 *
	 * @param array<string, mixed> $raw Datos sin validar.
	 * @param string               $key Clave del ajuste.
	 */
	private function text( array $raw, string $key ): string {
		// Son valores de cabeceras y de conexión (nombre del remitente, servidor): aquí se quiere el saneado de WordPress tal cual.
		return is_scalar( $raw[ $key ] ?? null ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
	}

	/**
	 * Sanea el nombre o la IP de un servidor: sin esquema, ruta ni puerto.
	 *
	 * @param string $host Servidor sin validar.
	 */
	private function sanitize_host( string $host ): string {
		$host = strtolower( trim( $host ) );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}

		return 1 === preg_match( '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host ) ? $host : '';
	}
}
