<?php
/**
 * Entrega: resultado de una acción sobre un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con una fila del registro de entregas. No contiene datos del envío.
 */
final class Delivery {

	/**
	 * Constructor.
	 *
	 * @param int             $id            Identificador; 0 si todavía no se ha guardado.
	 * @param int             $submission_id Identificador del envío guardado, o 0 si el formulario no guarda sus envíos.
	 * @param int             $form_id       Identificador del formulario.
	 * @param string          $action_id     Identificador de la acción dentro del formulario.
	 * @param string          $action_type   Tipo de la acción.
	 * @param Delivery_Status $status        Resultado.
	 * @param int             $attempts      Veces que se ha ejecutado la acción.
	 * @param string          $error         Motivo del fallo, o cadena vacía.
	 * @param int             $created_at    Marca de tiempo Unix del primer intento.
	 * @param int             $updated_at    Marca de tiempo Unix del último intento.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $submission_id,
		public readonly int $form_id,
		public readonly string $action_id,
		public readonly string $action_type,
		public readonly Delivery_Status $status,
		public readonly int $attempts,
		public readonly string $error,
		public readonly int $created_at,
		public readonly int $updated_at
	) {}

	/**
	 * Indica si la entrega se puede reintentar: falló y el envío sigue guardado.
	 */
	public function can_retry(): bool {
		return Delivery_Status::Failed === $this->status && $this->submission_id > 0;
	}
}
