<?php
/**
 * Petición de envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con un envío tal como llega, antes de validarlo. Nada de lo que contiene es de fiar.
 */
final class Submission_Request {

	/**
	 * Constructor.
	 *
	 * @param int                  $form_id    Identificador del formulario.
	 * @param array<string, mixed> $raw_values Valores de los campos tal como llegaron, sin barras de escape.
	 * @param string               $honeypot   Contenido del campo trampa; vacío en un envío legítimo.
	 * @param string               $token      Token antispam, o cadena vacía si no se envió.
	 * @param Submission_Channel   $channel    Vía por la que ha llegado.
	 * @param string               $client_ip  IP del visitante, o cadena vacía si no se conoce.
	 * @param string               $page_url   Dirección de la página de origen, o cadena vacía.
	 * @param string               $user_agent Navegador del visitante, o cadena vacía.
	 * @param string               $captcha_token Testigo del captcha, si el formulario lo lleva.
	 */
	public function __construct(
		public readonly int $form_id,
		public readonly array $raw_values,
		public readonly string $honeypot,
		public readonly string $token,
		public readonly Submission_Channel $channel,
		public readonly string $client_ip = '',
		public readonly string $page_url = '',
		public readonly string $user_agent = '',
		public readonly string $captcha_token = ''
	) {}
}
