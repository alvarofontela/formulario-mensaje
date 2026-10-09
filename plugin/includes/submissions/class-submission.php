<?php
/**
 * Envío de un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con un envío ya validado.
 */
final class Submission {

	/**
	 * Constructor.
	 *
	 * @param int                             $form_id    Identificador del formulario.
	 * @param string                          $reference  Número de referencia del envío.
	 * @param int                             $created_at Marca de tiempo Unix en que se recibió.
	 * @param string                          $page_url   Dirección de la página desde la que se envió, o cadena vacía.
	 * @param array<string, Submission_Entry> $entries    Datos recibidos, por identificador de campo y en el orden del formulario.
	 */
	public function __construct(
		public readonly int $form_id,
		public readonly string $reference,
		public readonly int $created_at,
		public readonly string $page_url,
		public readonly array $entries
	) {}

	/**
	 * Dato recibido en un campo.
	 *
	 * @param string $field_id Identificador del campo.
	 */
	public function entry( string $field_id ): ?Submission_Entry {
		return $this->entries[ $field_id ] ?? null;
	}

	/**
	 * Valor legible de un campo, sin escapar.
	 *
	 * @param string $field_id Identificador del campo.
	 * @return string Cadena vacía si el campo no existe o llegó sin dato.
	 */
	public function formatted( string $field_id ): string {
		return isset( $this->entries[ $field_id ] ) ? $this->entries[ $field_id ]->formatted : '';
	}
}
