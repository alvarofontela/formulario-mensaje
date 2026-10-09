<?php
/**
 * Mensaje de correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con un correo listo para enviar.
 */
final class Email_Message {

	/**
	 * Constructor.
	 *
	 * @param string[] $to       Direcciones de destino, ya validadas.
	 * @param string   $subject  Asunto, en una sola línea.
	 * @param string   $body     Cuerpo en texto plano. Va siempre: es la alternativa para quien no lee HTML.
	 * @param string   $reply_to Dirección de respuesta ya validada, o cadena vacía.
	 * @param string   $html     Cuerpo en HTML, con todo dato externo ya escapado; cadena vacía para un correo solo de texto.
	 */
	public function __construct(
		public readonly array $to,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $reply_to = '',
		public readonly string $html = ''
	) {}

	/**
	 * Indica si el correo lleva versión en HTML.
	 */
	public function has_html(): bool {
		return '' !== $this->html;
	}

	/**
	 * Asunto en una sola línea: un salto de línea permitiría inyectar cabeceras.
	 */
	public function safe_subject(): string {
		return trim( (string) preg_replace( '/[\r\n]+/', ' ', $this->subject ) );
	}

	/**
	 * Destinatarios válidos.
	 *
	 * @return string[]
	 */
	public function safe_recipients(): array {
		return array_values( array_filter( $this->to, static fn ( mixed $address ): bool => is_string( $address ) && false !== is_email( $address ) ) );
	}

	/**
	 * Dirección de respuesta, si es una dirección válida y sin saltos de línea.
	 *
	 * Es el único dato del visitante que puede llegar a una cabecera: se vuelve a validar aquí, justo
	 * antes de usarlo, sea cual sea el transporte.
	 */
	public function safe_reply_to(): string {
		$valid = '' !== $this->reply_to && false !== is_email( $this->reply_to ) && 1 !== preg_match( '/[\r\n]/', $this->reply_to );

		return $valid ? $this->reply_to : '';
	}
}
