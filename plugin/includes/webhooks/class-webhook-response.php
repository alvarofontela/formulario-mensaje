<?php
/**
 * Respuesta de un webhook.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con lo que respondió el destino de un webhook.
 */
final class Webhook_Response {

	/**
	 * Constructor.
	 *
	 * @param int    $status_code Código de estado HTTP.
	 * @param string $body        Principio del cuerpo de la respuesta, como texto.
	 * @param int    $duration_ms Milisegundos que tardó la petición.
	 */
	public function __construct(
		public readonly int $status_code,
		public readonly string $body,
		public readonly int $duration_ms
	) {}

	/**
	 * Indica si el destino aceptó la petición: cualquier código 2xx.
	 */
	public function is_success(): bool {
		return $this->status_code >= 200 && $this->status_code < 300;
	}
}
