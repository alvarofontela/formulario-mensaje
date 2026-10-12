<?php
/**
 * Motor de reglas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Evalúa una condición contra los valores de un envío.
 *
 * Es el único motor de reglas del plugin: decide si una acción se ejecuta y, más adelante, si un
 * campo o un paso se muestran. El navegador tiene la misma lógica en JavaScript; lo que decide el
 * servidor es lo que vale.
 *
 * Las comparaciones no distinguen mayúsculas ni espacios en los extremos. Un campo con varios
 * valores (casillas) «es» un valor si lo incluye, y lo «contiene» si alguno de los suyos lo contiene.
 */
final class Rule_Evaluator {

	/**
	 * Indica si una condición se cumple.
	 *
	 * @param array<string, mixed>           $condition Condición saneada: `match` y `rules`.
	 * @param array<string, string|string[]> $values    Valores del envío, por identificador de campo.
	 * @return bool True si se cumple o si la condición no tiene reglas.
	 */
	public function passes( array $condition, array $values ): bool {
		$rules = is_array( $condition['rules'] ?? null ) ? $condition['rules'] : array();

		if ( array() === $rules ) {
			return true;
		}

		$any = Condition_Sanitizer::MATCH_ANY === ( $condition['match'] ?? null );

		foreach ( $rules as $rule ) {
			$passes = is_array( $rule ) && $this->rule_passes( $rule, $values );

			// Con «alguna» basta la primera que se cumple; con «todas», la primera que falla decide.
			if ( $passes === $any ) {
				return $any;
			}
		}

		return ! $any;
	}

	/**
	 * Indica si una regla se cumple.
	 *
	 * @param array<string, mixed>           $rule   Regla: `field`, `operator` y `value`.
	 * @param array<string, string|string[]> $values Valores del envío.
	 */
	private function rule_passes( array $rule, array $values ): bool {
		$operator = Operator::tryFrom( is_string( $rule['operator'] ?? null ) ? $rule['operator'] : '' );

		if ( null === $operator ) {
			return false;
		}

		// Un campo que no está en el envío (porque se borró del formulario) cuenta como vacío.
		$actual   = array_values( array_filter( array_map( array( $this, 'normalize' ), (array) ( $values[ $rule['field'] ?? '' ] ?? array() ) ), 'strlen' ) );
		$expected = $this->normalize( is_scalar( $rule['value'] ?? null ) ? (string) $rule['value'] : '' );

		return match ( $operator ) {
			Operator::Is_Empty     => array() === $actual,
			Operator::Is_Not_Empty => array() !== $actual,
			Operator::Equals       => in_array( $expected, $actual, true ),
			Operator::Not_Equals   => ! in_array( $expected, $actual, true ),
			Operator::Contains     => $this->any_contains( $actual, $expected ),
			Operator::Not_Contains => ! $this->any_contains( $actual, $expected ),
			Operator::Greater_Than => $this->compare( $actual, $expected ) > 0,
			Operator::Less_Than    => $this->compare( $actual, $expected ) < 0,
		};
	}

	/**
	 * Normaliza un texto para compararlo.
	 *
	 * @param mixed $value Valor del envío o de la regla.
	 */
	private function normalize( mixed $value ): string {
		return is_scalar( $value ) ? mb_strtolower( trim( (string) $value ) ) : '';
	}

	/**
	 * Indica si alguno de los valores contiene un texto. Un texto vacío no está contenido en nada.
	 *
	 * @param string[] $actual   Valores del campo.
	 * @param string   $expected Texto buscado.
	 */
	private function any_contains( array $actual, string $expected ): bool {
		if ( '' === $expected ) {
			return false;
		}

		foreach ( $actual as $value ) {
			if ( str_contains( $value, $expected ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compara como números el valor del campo y el de la regla.
	 *
	 * @param string[] $actual   Valores del campo; se compara el primero.
	 * @param string   $expected Valor de la regla.
	 * @return int Negativo, cero o positivo; cero también si alguno de los dos no es un número.
	 */
	private function compare( array $actual, string $expected ): int {
		// Sin espacios (también el de los millares) y con punto decimal: lo mismo que hace el campo de número al sanear.
		$left  = str_replace( ',', '.', (string) preg_replace( '/\s+/u', '', $actual[0] ?? '' ) );
		$right = str_replace( ',', '.', (string) preg_replace( '/\s+/u', '', $expected ) );

		if ( ! is_numeric( $left ) || ! is_numeric( $right ) ) {
			return 0;
		}

		return (float) $left <=> (float) $right;
	}
}
