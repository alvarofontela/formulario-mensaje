<?php
/**
 * Registro de tipos de campo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Tipos de campo disponibles. Los del plugin y los de terceros se registran por la misma vía.
 */
final class Field_Type_Registry {

	/**
	 * Tipos registrados, por identificador.
	 *
	 * @var array<string, Field_Type>
	 */
	private array $types = array();

	/**
	 * Registra un tipo de campo; si ya existe uno con el mismo identificador, lo sustituye.
	 *
	 * @param Field_Type $type Tipo de campo.
	 */
	public function register( Field_Type $type ): void {
		$this->types[ $type->get_type() ] = $type;
	}

	/**
	 * Devuelve un tipo de campo.
	 *
	 * @param string $type Identificador del tipo.
	 */
	public function get( string $type ): ?Field_Type {
		return $this->types[ $type ] ?? null;
	}

	/**
	 * Devuelve un tipo de campo solo si recoge datos del visitante.
	 *
	 * @param string $type Identificador del tipo.
	 */
	public function get_input( string $type ): ?Input_Field_Type {
		$field_type = $this->get( $type );

		return $field_type instanceof Input_Field_Type ? $field_type : null;
	}

	/**
	 * Devuelve un tipo de campo si abre una sección del formulario (un paso).
	 *
	 * @param string $type Identificador del tipo.
	 */
	public function get_section( string $type ): ?Section_Field_Type {
		$field_type = $this->get( $type );

		return $field_type instanceof Section_Field_Type ? $field_type : null;
	}

	/**
	 * Todos los tipos registrados, en orden de registro.
	 *
	 * @return Field_Type[]
	 */
	public function all(): array {
		return array_values( $this->types );
	}
}
