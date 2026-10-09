<?php
/**
 * Trabajo de la cola.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Queue;

use Formulario_Mensaje\Storage\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con una entrega reservada de la cola y, si la lleva, la copia de su envío.
 */
final class Queue_Job {

	/**
	 * Constructor.
	 *
	 * @param Delivery $delivery Entrega reservada, con el intento en curso ya contado.
	 * @param string   $payload  Copia del envío en JSON, o cadena vacía si el envío está guardado.
	 */
	public function __construct(
		public readonly Delivery $delivery,
		public readonly string $payload
	) {}
}
