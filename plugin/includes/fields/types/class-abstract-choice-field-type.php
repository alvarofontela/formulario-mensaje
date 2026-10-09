<?php
/**
 * Base de los campos de opciones.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Common_Settings;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Input_Field_Type;
use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Comportamiento compartido por los campos que eligen entre una lista cerrada de opciones.
 *
 * El valor recibido solo se acepta si coincide con una de las opciones configuradas.
 */
abstract class Abstract_Choice_Field_Type implements Input_Field_Type {

	/**
	 * Constructor.
	 *
	 * @param Field_Markup    $markup HTML común de los campos.
	 * @param Common_Settings $common Opciones comunes de los campos.
	 */
	public function __construct(
		protected readonly Field_Markup $markup,
		protected readonly Common_Settings $common
	) {}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'choice';
	}

	/**
	 * Descripción de la opción que guarda la lista de opciones.
	 *
	 * @return array<string, mixed>
	 */
	protected function options_setting(): array {
		return array(
			'key'     => 'options',
			'type'    => 'options',
			'label'   => __( 'Options', 'formulario-mensaje' ),
			'default' => array(
				array(
					'label' => __( 'Option 1', 'formulario-mensaje' ),
					'value' => __( 'Option 1', 'formulario-mensaje' ),
				),
				array(
					'label' => __( 'Option 2', 'formulario-mensaje' ),
					'value' => __( 'Option 2', 'formulario-mensaje' ),
				),
			),
		);
	}

	/**
	 * Opciones del campo, por valor.
	 *
	 * @param Field $field Campo.
	 * @return array<string, string> Etiqueta de cada opción, indexada por su valor.
	 */
	protected function options( Field $field ): array {
		$options = array();

		foreach ( (array) $field->get( 'options', array() ) as $option ) {
			if ( is_array( $option ) && is_string( $option['value'] ?? null ) && is_string( $option['label'] ?? null ) ) {
				$options[ $option['value'] ] = $option['label'];
			}
		}

		return $options;
	}

	/**
	 * Sanea el valor recibido en un envío: una sola opción.
	 *
	 * @param Field $field Campo al que pertenece el valor.
	 * @param mixed $raw   Valor tal como llegó en la petición.
	 * @return string|string[]
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array {
		return is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
	}

	/**
	 * Valida que el valor sea una de las opciones configuradas.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function validate( Field $field, string|array $value ): ?string {
		$options = $this->options( $field );

		foreach ( (array) $value as $item ) {
			if ( ! isset( $options[ $item ] ) ) {
				return __( 'Choose one of the available options.', 'formulario-mensaje' );
			}
		}

		return null;
	}

	/**
	 * Convierte un valor saneado en un texto legible: las etiquetas de las opciones elegidas.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function format_value( Field $field, string|array $value ): string {
		$options = $this->options( $field );
		$labels  = array();

		foreach ( (array) $value as $item ) {
			$labels[] = $options[ $item ] ?? $item;
		}

		return implode( ', ', $labels );
	}

	/**
	 * Valor de ejemplo para probar notificaciones y otras acciones.
	 *
	 * @param Field $field Campo.
	 * @return string|string[]
	 */
	public function sample_value( Field $field ): string|array {
		return (string) ( array_key_first( $this->options( $field ) ) ?? '' );
	}
}
