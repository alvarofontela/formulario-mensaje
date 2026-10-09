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
	 * @param string                          $client_ip  IP del visitante, o cadena vacía si no se conoce. Solo se guarda si el formulario lo pide.
	 * @param string                          $user_agent Navegador del visitante, o cadena vacía. Solo se guarda si el formulario lo pide.
	 */
	public function __construct(
		public readonly int $form_id,
		public readonly string $reference,
		public readonly int $created_at,
		public readonly string $page_url,
		public readonly array $entries,
		public readonly string $client_ip = '',
		public readonly string $user_agent = ''
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

	/**
	 * Copia del envío para guardarla como JSON mientras espera en la cola.
	 *
	 * No lleva la IP ni el navegador: las acciones no los usan.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'form_id'    => $this->form_id,
			'reference'  => $this->reference,
			'created_at' => $this->created_at,
			'page_url'   => $this->page_url,
			'entries'    => array_map( static fn ( Submission_Entry $entry ): array => $entry->to_array(), array_values( $this->entries ) ),
		);
	}

	/**
	 * Reconstruye un envío a partir del array que devuelve to_array().
	 *
	 * @param mixed $data Array leído de la base de datos.
	 * @return self|null Null si no tiene la forma esperada.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_string( $data['reference'] ?? null ) || ! is_array( $data['entries'] ?? null ) ) {
			return null;
		}

		$entries = array();

		foreach ( $data['entries'] as $item ) {
			$entry = Submission_Entry::from_array( $item );

			if ( null !== $entry ) {
				$entries[ $entry->field_id ] = $entry;
			}
		}

		return new self(
			(int) ( $data['form_id'] ?? 0 ),
			$data['reference'],
			(int) ( $data['created_at'] ?? 0 ),
			is_string( $data['page_url'] ?? null ) ? $data['page_url'] : '',
			$entries
		);
	}
}
