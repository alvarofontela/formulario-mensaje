<?php
/**
 * Registro de tipos de acción.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Tipos de acción disponibles. Los del plugin y los de terceros se registran por la misma vía.
 */
final class Action_Type_Registry {

	/**
	 * Tipos registrados, por identificador.
	 *
	 * @var array<string, Action_Type>
	 */
	private array $types = array();

	/**
	 * Registra un tipo de acción; si ya existe uno con el mismo identificador, lo sustituye.
	 *
	 * @param Action_Type $type Tipo de acción.
	 */
	public function register( Action_Type $type ): void {
		$this->types[ $type->get_type() ] = $type;
	}

	/**
	 * Devuelve un tipo de acción.
	 *
	 * @param string $type Identificador del tipo.
	 */
	public function get( string $type ): ?Action_Type {
		return $this->types[ $type ] ?? null;
	}

	/**
	 * Todos los tipos registrados, en orden de registro.
	 *
	 * @return Action_Type[]
	 */
	public function all(): array {
		return array_values( $this->types );
	}
}
