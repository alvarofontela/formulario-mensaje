<?php
/**
 * Definición de un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con los campos, los ajustes y las acciones de un formulario.
 */
final class Form_Definition {

	/**
	 * Versión del formato de la definición guardada.
	 */
	public const FORMAT_VERSION = 1;

	/**
	 * Constructor.
	 *
	 * @param Field[]                                                                                                                                   $fields   Campos, en orden.
	 * @param array<string, mixed>                                                                                                                      $settings Ajustes del formulario.
	 * @param array<int, array{id: string, type: string, enabled: bool, name: string, condition: array<string, mixed>, settings: array<string, mixed>}> $actions  Acciones de cada envío.
	 */
	public function __construct(
		public readonly array $fields = array(),
		public readonly array $settings = array(),
		public readonly array $actions = array()
	) {}

	/**
	 * Reconstruye una definición ya saneada, tal como se guardó.
	 *
	 * No sanea: para datos que vienen de una petición se usa Form_Definition_Sanitizer.
	 *
	 * @param mixed $data Definición decodificada.
	 */
	public static function from_array( mixed $data ): self {
		$data    = is_array( $data ) ? $data : array();
		$fields  = array();
		$actions = array();

		foreach ( is_array( $data['fields'] ?? null ) ? $data['fields'] : array() as $field ) {
			if ( ! is_array( $field ) || ! is_string( $field['id'] ?? null ) || ! is_string( $field['type'] ?? null ) ) {
				continue;
			}

			$fields[] = new Field( $field['id'], $field['type'], is_array( $field['settings'] ?? null ) ? $field['settings'] : array() );
		}

		foreach ( is_array( $data['actions'] ?? null ) ? $data['actions'] : array() as $action ) {
			if ( ! is_array( $action ) || ! is_string( $action['id'] ?? null ) || ! is_string( $action['type'] ?? null ) ) {
				continue;
			}

			$actions[] = array(
				'id'        => $action['id'],
				'type'      => $action['type'],
				'enabled'   => ! empty( $action['enabled'] ),
				'name'      => is_string( $action['name'] ?? null ) ? $action['name'] : '',
				'condition' => is_array( $action['condition'] ?? null ) ? $action['condition'] : array(),
				'settings'  => is_array( $action['settings'] ?? null ) ? $action['settings'] : array(),
			);
		}

		return new self( $fields, is_array( $data['settings'] ?? null ) ? $data['settings'] : array(), $actions );
	}

	/**
	 * Busca un campo por su identificador.
	 *
	 * @param string $id Identificador del campo.
	 */
	public function get_field( string $id ): ?Field {
		foreach ( $this->fields as $field ) {
			if ( $field->id === $id ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Valor de un ajuste del formulario.
	 *
	 * @param string $key      Clave del ajuste.
	 * @param mixed  $fallback Valor si el ajuste no está definido.
	 */
	public function setting( string $key, mixed $fallback = null ): mixed {
		return $this->settings[ $key ] ?? $fallback;
	}

	/**
	 * Representación para guardar o enviar por REST.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'version'  => self::FORMAT_VERSION,
			'fields'   => array_map( static fn ( Field $field ): array => $field->to_array(), $this->fields ),
			'settings' => $this->settings,
			'actions'  => $this->actions,
		);
	}
}
