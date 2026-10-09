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
	 * @param string   $body     Cuerpo en texto plano.
	 * @param string   $reply_to Dirección de respuesta ya validada, o cadena vacía.
	 */
	public function __construct(
		public readonly array $to,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $reply_to = ''
	) {}
}
