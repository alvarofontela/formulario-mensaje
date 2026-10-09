<?php
/**
 * Resultado de una acción.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con el resultado de ejecutar una acción sobre un envío.
 */
final class Action_Result {

	/**
	 * Constructor.
	 *
	 * @param string $action_id Identificador de la acción dentro del formulario.
	 * @param string $type      Tipo de la acción.
	 * @param bool   $success   Si la acción se completó.
	 * @param string $error     Motivo del fallo, para diagnóstico; nunca contiene datos del envío.
	 * @param bool   $deferred  Si la acción no se ha ejecutado todavía porque va por la cola.
	 */
	public function __construct(
		public readonly string $action_id,
		public readonly string $type,
		public readonly bool $success,
		public readonly string $error = '',
		public readonly bool $deferred = false
	) {}
}
