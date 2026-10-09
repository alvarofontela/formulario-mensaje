<?php
/**
 * Formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con un formulario guardado (o uno sin guardar, con identificador 0).
 */
final class Form {

	/**
	 * Estado de un formulario que acepta envíos.
	 */
	public const STATUS_PUBLISHED = 'publish';

	/**
	 * Estado de un formulario en la papelera.
	 */
	public const STATUS_TRASHED = 'trash';

	/**
	 * Constructor.
	 *
	 * @param int             $id         Identificador; 0 si todavía no se ha guardado.
	 * @param string          $title      Nombre del formulario.
	 * @param string          $status     Estado.
	 * @param Form_Definition $definition Campos, ajustes y acciones.
	 * @param string          $modified   Fecha de la última modificación en GMT (formato MySQL), o cadena vacía.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $status,
		public readonly Form_Definition $definition,
		public readonly string $modified = ''
	) {}

	/**
	 * Indica si el formulario acepta envíos.
	 */
	public function is_published(): bool {
		return self::STATUS_PUBLISHED === $this->status;
	}
}
