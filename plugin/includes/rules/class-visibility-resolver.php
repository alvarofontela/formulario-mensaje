<?php
/**
 * Visibilidad condicional.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Decide qué elementos con condición se muestran para unos valores dados.
 *
 * Se recorre el formulario una sola vez, en su orden. La condición de un elemento solo ve los
 * valores de los campos anteriores que se muestran: un campo oculto, o uno que viene después,
 * cuenta como vacío. Así no hay dependencias circulares que resolver y el resultado es el mismo
 * aquí y en el navegador (`assets/js/rules.js`), que sigue el mismo recorrido.
 *
 * Un elemento con padre (un campo dentro de un paso) solo se muestra si su padre se muestra.
 */
final class Visibility_Resolver {

	/**
	 * Constructor.
	 *
	 * @param Rule_Evaluator $rules Motor de reglas.
	 */
	public function __construct( private readonly Rule_Evaluator $rules ) {}

	/**
	 * Calcula qué elementos se muestran.
	 *
	 * @param array<string, array<string, mixed>>  $conditions Condición de cada elemento, por su identificador y en el orden del formulario; vacía si se muestra siempre.
	 * @param array<string, string|string[]|mixed> $values     Valores, por identificador de campo.
	 * @param array<string, string>                $parents    Identificador del padre de cada elemento que lo tiene. El padre va antes que sus hijos.
	 * @return array<string, bool> Si se muestra cada elemento, por su identificador.
	 */
	public function resolve( array $conditions, array $values, array $parents = array() ): array {
		$visible = array();
		$current = array();

		foreach ( $conditions as $id => $condition ) {
			$parent = $parents[ $id ] ?? null;
			$shown  = ( null === $parent || false !== ( $visible[ $parent ] ?? true ) ) && $this->rules->passes( $condition, $current );

			$visible[ $id ] = $shown;

			if ( $shown && array_key_exists( $id, $values ) ) {
				$current[ $id ] = $values[ $id ];
			}
		}

		return $visible;
	}
}
