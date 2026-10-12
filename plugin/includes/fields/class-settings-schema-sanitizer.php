<?php
/**
 * Saneado de opciones a partir de un esquema.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Support\Inline_Html;
use Formulario_Mensaje\Support\Plain_Text;

defined( 'ABSPATH' ) || exit;

/**
 * Sanea las opciones de un campo según el esquema que declara su tipo.
 *
 * Solo sobreviven las claves del esquema; cada una se sanea según el tipo de control.
 */
final class Settings_Schema_Sanitizer {

	/**
	 * Máximo de opciones en una lista de opciones.
	 */
	private const MAX_OPTIONS = 200;

	/**
	 * Longitud máxima de un texto corto.
	 */
	private const MAX_TEXT_LENGTH = 500;

	/**
	 * Longitud máxima de un texto largo.
	 */
	private const MAX_LONG_TEXT_LENGTH = 5000;

	/**
	 * Constructor.
	 *
	 * @param Inline_Html $inline_html Filtro del HTML permitido en los textos.
	 */
	public function __construct( private readonly Inline_Html $inline_html ) {}

	/**
	 * Sanea unas opciones.
	 *
	 * @param array<int, array<string, mixed>> $schema Esquema del tipo de campo.
	 * @param mixed                            $raw    Opciones sin validar.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $schema, mixed $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$clean = array();

		foreach ( $schema as $control ) {
			$key = $control['key'] ?? '';

			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			$clean[ $key ] = array_key_exists( $key, $raw )
				? $this->sanitize_value( $control, $raw[ $key ] )
				: ( $control['default'] ?? '' );
		}

		return $clean;
	}

	/**
	 * Sanea el valor de un control.
	 *
	 * @param array<string, mixed> $control Descripción del control.
	 * @param mixed                $value   Valor sin validar.
	 */
	private function sanitize_value( array $control, mixed $value ): mixed {
		$default = $control['default'] ?? '';

		switch ( $control['type'] ?? 'text' ) {
			case 'toggle':
				return (bool) rest_sanitize_boolean( is_scalar( $value ) ? $value : false );

			case 'select':
				$allowed = array_map( 'strval', array_column( is_array( $control['choices'] ?? null ) ? $control['choices'] : array(), 'value' ) );
				$value   = is_scalar( $value ) ? (string) $value : '';

				return in_array( $value, $allowed, true ) ? $value : $default;

			case 'checklist':
				$allowed = array_map( 'strval', array_column( is_array( $control['choices'] ?? null ) ? $control['choices'] : array(), 'value' ) );

				// Los elegidos, en el orden de la lista y sin nada que no esté en ella.
				return array_values( array_intersect( $allowed, array_map( 'strval', array_filter( is_array( $value ) ? $value : array(), 'is_scalar' ) ) ) );
			case 'options':
				return $this->sanitize_options( $value );

			case 'textarea':
				return is_scalar( $value ) ? mb_substr( Plain_Text::block( (string) $value ), 0, self::MAX_LONG_TEXT_LENGTH ) : $default;

			case 'html':
				return is_scalar( $value ) ? mb_substr( $this->inline_html->filter( (string) $value ), 0, self::MAX_LONG_TEXT_LENGTH ) : $default;

			case 'css_class':
				$classes = is_scalar( $value ) ? preg_split( '/\s+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) : array();

				return implode( ' ', array_filter( array_map( 'sanitize_html_class', is_array( $classes ) ? $classes : array() ) ) );

			default:
				return is_scalar( $value ) ? mb_substr( Plain_Text::line( (string) $value ), 0, self::MAX_TEXT_LENGTH ) : $default;
		}
	}

	/**
	 * Sanea una lista de opciones de un desplegable, un grupo de radios o de casillas.
	 *
	 * @param mixed $value Lista sin validar.
	 * @return array<int, array{label: string, value: string}>
	 */
	private function sanitize_options( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$options = array();

		foreach ( $value as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$label        = is_scalar( $option['label'] ?? null ) ? Plain_Text::line( (string) $option['label'] ) : '';
			$option_value = is_scalar( $option['value'] ?? null ) ? Plain_Text::line( (string) $option['value'] ) : '';

			if ( '' === $label && '' === $option_value ) {
				continue;
			}

			// El valor se deduce de la etiqueta, y al revés, cuando falta uno de los dos.
			$option_value = '' === $option_value ? $label : $option_value;
			$label        = '' === $label ? $option_value : $label;

			if ( isset( $options[ $option_value ] ) ) {
				continue;
			}

			$options[ $option_value ] = array(
				'label' => mb_substr( $label, 0, self::MAX_TEXT_LENGTH ),
				'value' => mb_substr( $option_value, 0, self::MAX_TEXT_LENGTH ),
			);

			if ( count( $options ) >= self::MAX_OPTIONS ) {
				break;
			}
		}

		return array_values( $options );
	}
}
