<?php
/**
 * Saneado de condiciones.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rules;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte una condición que llega del constructor en una condición válida.
 *
 * Una condición es un grupo de reglas: `{ match: 'all'|'any', rules: [ { field, operator, value } ] }`.
 * Sin reglas, se cumple siempre.
 */
final class Condition_Sanitizer {

	public const MATCH_ALL = 'all';
	public const MATCH_ANY = 'any';

	/**
	 * Máximo de reglas de una condición.
	 */
	private const MAX_RULES = 20;

	/**
	 * Sanea una condición.
	 *
	 * @param mixed $raw Condición sin validar.
	 * @return array{match: string, rules: array<int, array{field: string, operator: string, value: string}>}
	 */
	public function sanitize( mixed $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$rules = array();

		foreach ( is_array( $raw['rules'] ?? null ) ? array_slice( $raw['rules'], 0, self::MAX_RULES ) : array() as $rule ) {
			$field    = is_array( $rule ) && is_string( $rule['field'] ?? null ) ? $rule['field'] : '';
			$operator = is_array( $rule ) && is_string( $rule['operator'] ?? null ) ? Operator::tryFrom( $rule['operator'] ) : null;

			// Una regla sin campo o con un operador desconocido no dice nada: se descarta.
			if ( 1 !== preg_match( Field::ID_PATTERN, $field ) || null === $operator ) {
				continue;
			}

			$rules[] = array(
				'field'    => $field,
				'operator' => $operator->value,
				'value'    => $operator->needs_value() && is_scalar( $rule['value'] ?? null ) ? mb_substr( sanitize_text_field( (string) $rule['value'] ), 0, 500 ) : '',
			);
		}

		return array(
			'match' => self::MATCH_ANY === ( $raw['match'] ?? null ) ? self::MATCH_ANY : self::MATCH_ALL,
			'rules' => $rules,
		);
	}
}
