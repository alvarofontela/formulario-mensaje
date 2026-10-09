<?php
/**
 * Ajustes de correo del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con cómo se envían los correos del plugin y qué aspecto tienen.
 *
 * Solo afecta a los correos del plugin: el resto del correo del sitio no se toca. Las contraseñas y
 * claves no están aquí, sino cifradas en el almacén de secretos.
 */
final class Mail_Settings {

	public const TRANSPORT_WP_MAIL = 'wp_mail';
	public const TRANSPORT_SMTP    = 'smtp';
	public const TRANSPORT_RESEND  = 'resend';

	public const ENCRYPTION_NONE = 'none';
	public const ENCRYPTION_SSL  = 'ssl';
	public const ENCRYPTION_TLS  = 'tls';

	/**
	 * Nombre, en el almacén de secretos, de la contraseña de SMTP.
	 */
	public const SECRET_SMTP_PASSWORD = 'smtp_password';

	/**
	 * Nombre, en el almacén de secretos, de la clave de la API de Resend.
	 */
	public const SECRET_RESEND_API_KEY = 'resend_api_key';

	/**
	 * Color de marca por defecto: el azul de los enlaces de WordPress.
	 */
	public const DEFAULT_BRAND_COLOR = '#2271b1';

	/**
	 * Constructor.
	 *
	 * @param string $transport       Transporte: una de las constantes TRANSPORT_*, o el de un tercero.
	 * @param string $from_email      Dirección del remitente; vacía para la que use el sitio.
	 * @param string $from_name       Nombre del remitente; vacío para el del sitio.
	 * @param string $smtp_host       Servidor SMTP.
	 * @param int    $smtp_port       Puerto SMTP.
	 * @param string $smtp_encryption Cifrado de la conexión SMTP: una de las constantes ENCRYPTION_*.
	 * @param bool   $smtp_auth       Si el servidor SMTP pide usuario y contraseña.
	 * @param string $smtp_username   Usuario de SMTP.
	 * @param string $brand_logo_url  Dirección del logotipo de los correos; vacía para mostrar el nombre del sitio.
	 * @param string $brand_color     Color de marca, en hexadecimal.
	 * @param string $brand_footer    Texto del pie de los correos.
	 */
	public function __construct(
		public readonly string $transport = self::TRANSPORT_WP_MAIL,
		public readonly string $from_email = '',
		public readonly string $from_name = '',
		public readonly string $smtp_host = '',
		public readonly int $smtp_port = 587,
		public readonly string $smtp_encryption = self::ENCRYPTION_TLS,
		public readonly bool $smtp_auth = true,
		public readonly string $smtp_username = '',
		public readonly string $brand_logo_url = '',
		public readonly string $brand_color = self::DEFAULT_BRAND_COLOR,
		public readonly string $brand_footer = ''
	) {}

	/**
	 * Representación para guardar en la base de datos o enviar por REST. No contiene secretos.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'transport'       => $this->transport,
			'from_email'      => $this->from_email,
			'from_name'       => $this->from_name,
			'smtp_host'       => $this->smtp_host,
			'smtp_port'       => $this->smtp_port,
			'smtp_encryption' => $this->smtp_encryption,
			'smtp_auth'       => $this->smtp_auth,
			'smtp_username'   => $this->smtp_username,
			'brand_logo_url'  => $this->brand_logo_url,
			'brand_color'     => $this->brand_color,
			'brand_footer'    => $this->brand_footer,
		);
	}

	/**
	 * Nombre con el que firma el remitente: el configurado o el del sitio.
	 */
	public function sender_name(): string {
		$name = '' !== $this->from_name ? $this->from_name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		// Acaba en una cabecera: sin saltos de línea, caracteres de control ni los que delimitan una dirección.
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F<>"]+/', ' ', $name ) );
	}
}
