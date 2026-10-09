<?php
/**
 * Estilo de un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Vocabulario del estilo de un formulario: qué se puede ajustar, entre qué límites y a qué propiedad
 * personalizada de CSS corresponde.
 *
 * El estilo no es CSS libre: es una lista cerrada de colores y medidas. Lo que no se ajusta se
 * hereda del tema. Hay dos niveles: el del formulario (su diseñador) y el del sitio donde se inserta
 * (los controles del bloque), que tiene prioridad sobre el primero.
 */
final class Form_Style {

	public const SCOPE_FORM  = 'form';
	public const SCOPE_EMBED = 'embed';

	/**
	 * Colores: clave del ajuste y nombre de su propiedad CSS.
	 */
	private const COLORS = array(
		'label_color'       => 'label-color',
		'field_color'       => 'field-color',
		'field_background'  => 'field-background',
		'border_color'      => 'border-color',
		'focus_color'       => 'focus-color',
		'button_background' => 'button-background',
		'button_color'      => 'button-color',
	);

	/**
	 * Medidas en píxeles: clave del ajuste, nombre de su propiedad CSS, mínimo y máximo.
	 */
	private const SIZES = array(
		'gap'           => array( 'gap', 4, 64 ),
		'border_width'  => array( 'border-width', 0, 8 ),
		'border_radius' => array( 'border-radius', 0, 40 ),
		'field_padding' => array( 'control-padding', 2, 32 ),
	);

	/**
	 * Sanea un estilo. Solo quedan los ajustes con un valor válido; el resto se hereda.
	 *
	 * @param mixed $raw Estilo sin validar.
	 * @return array<string, string|int>
	 */
	public function sanitize( mixed $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$style = array();

		foreach ( array_keys( self::COLORS ) as $key ) {
			$color = is_string( $raw[ $key ] ?? null ) ? sanitize_hex_color( $raw[ $key ] ) : null;

			if ( is_string( $color ) && '' !== $color ) {
				$style[ $key ] = strtolower( $color );
			}
		}

		foreach ( self::SIZES as $key => list( , $minimum, $maximum ) ) {
			if ( is_numeric( $raw[ $key ] ?? null ) ) {
				$style[ $key ] = max( $minimum, min( $maximum, (int) round( (float) $raw[ $key ] ) ) );
			}
		}

		return $style;
	}

	/**
	 * Declaraciones CSS de un estilo, para el atributo `style` del formulario.
	 *
	 * @param array<string, mixed> $style Estilo. Se vuelve a sanear: lo que salga de aquí acaba en el HTML.
	 * @param string               $scope Nivel: SCOPE_FORM o SCOPE_EMBED.
	 */
	public function css( array $style, string $scope ): string {
		$style = $this->sanitize( $style );
		$scope = self::SCOPE_EMBED === $scope ? self::SCOPE_EMBED : self::SCOPE_FORM;
		$css   = '';

		foreach ( self::COLORS as $key => $property ) {
			if ( isset( $style[ $key ] ) ) {
				$css .= sprintf( '--fm-%s-%s:%s;', $scope, $property, $style[ $key ] );
			}
		}

		foreach ( self::SIZES as $key => list( $property ) ) {
			if ( ! isset( $style[ $key ] ) ) {
				continue;
			}

			// El relleno de los controles es algo mayor a los lados que arriba y abajo.
			$value = 'field_padding' === $key
				? sprintf( '%dpx %dpx', $style[ $key ], (int) round( $style[ $key ] * 1.25 ) )
				: $style[ $key ] . 'px';

			$css .= sprintf( '--fm-%s-%s:%s;', $scope, $property, $value );
		}

		return $css;
	}

	/**
	 * Indica si alguno de los estilos da color al botón. El botón solo deja de heredar el aspecto
	 * del tema cuando alguien le pone color.
	 *
	 * @param array<string, mixed> ...$styles Estilos.
	 */
	public function styles_button( array ...$styles ): bool {
		foreach ( $styles as $style ) {
			$style = $this->sanitize( $style );

			if ( isset( $style['button_background'] ) || isset( $style['button_color'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Descripción de los ajustes para el constructor y el bloque: clave, tipo y límites.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function schema(): array {
		$schema = array();

		foreach ( array_keys( self::COLORS ) as $key ) {
			$schema[] = array(
				'key'  => $key,
				'type' => 'color',
			);
		}

		foreach ( self::SIZES as $key => list( , $minimum, $maximum ) ) {
			$schema[] = array(
				'key'  => $key,
				'type' => 'size',
				'min'  => $minimum,
				'max'  => $maximum,
			);
		}

		return $schema;
	}
}
