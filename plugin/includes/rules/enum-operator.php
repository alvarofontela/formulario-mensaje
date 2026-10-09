<?php
/**
 * Operadores del motor de reglas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Comparaciones que admite una regla. Son las mismas en el servidor y en el navegador.
 */
enum Operator: string {

	case Equals       = 'equals';
	case Not_Equals   = 'not_equals';
	case Contains     = 'contains';
	case Not_Contains = 'not_contains';
	case Is_Empty     = 'is_empty';
	case Is_Not_Empty = 'is_not_empty';
	case Greater_Than = 'greater_than';
	case Less_Than    = 'less_than';

	/**
	 * Indica si el operador compara con un valor, o solo mira si el campo tiene dato.
	 */
	public function needs_value(): bool {
		return self::Is_Empty !== $this && self::Is_Not_Empty !== $this;
	}

	/**
	 * Nombre del operador, ya traducido, para el constructor.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Equals       => __( 'is', 'formulario-mensaje' ),
			self::Not_Equals   => __( 'is not', 'formulario-mensaje' ),
			self::Contains     => __( 'contains', 'formulario-mensaje' ),
			self::Not_Contains => __( 'does not contain', 'formulario-mensaje' ),
			self::Is_Empty     => __( 'is empty', 'formulario-mensaje' ),
			self::Is_Not_Empty => __( 'is not empty', 'formulario-mensaje' ),
			self::Greater_Than => __( 'is greater than', 'formulario-mensaje' ),
			self::Less_Than    => __( 'is less than', 'formulario-mensaje' ),
		};
	}
}
