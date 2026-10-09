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
 * Un elemento oculto no aporta su valor: si de él depende otro, ese otro lo ve vacío. Por eso la
 * visibilidad se calcula por pasadas hasta que deja de cambiar. Cada pasada parte de la visibilidad
 * de la anterior, sin mezclarla con la que se va calculando, y el número de pasadas tiene un tope:
 * así el resultado es el mismo aquí y en el navegador (`assets/js/rules.js`), también cuando dos
 * elementos dependen el uno del otro.
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
	 * @param array<string, array<string, mixed>>  $conditions Condición de cada elemento, por su identificador; vacía si se muestra siempre.
	 * @param array<string, string|string[]|mixed> $values     Valores, por identificador de campo.
	 * @return array<string, bool> Si se muestra cada elemento, por su identificador.
	 */
	public function resolve( array $conditions, array $values ): array {
		$visible = array_fill_keys( array_keys( $conditions ), true );
		$passes  = count( $conditions );

		for ( $pass = 0; $pass < $passes; $pass++ ) {
			$current = $values;

			foreach ( $visible as $id => $shown ) {
				if ( ! $shown ) {
					unset( $current[ $id ] );
				}
			}

			$next = array();

			foreach ( $conditions as $id => $condition ) {
				$next[ $id ] = $this->rules->passes( $condition, $current );
			}

			if ( $next === $visible ) {
				break;
			}

			$visible = $next;
		}

		return $visible;
	}
}
