<?php
/**
 * Tipo de campo: área de texto.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Common_Settings;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Input_Field_Type;
use Formulario_Mensaje\Fields\Prefill_Resolver;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Campo de texto de varias líneas.
 */
final class Textarea_Field_Type implements Input_Field_Type {

	/**
	 * Longitud máxima del valor, en caracteres. Es un tope de seguridad, no una regla configurable.
	 */
	private const MAX_LENGTH = 10000;

	/**
	 * Constructor.
	 *
	 * @param Field_Markup     $markup  HTML común de los campos.
	 * @param Common_Settings  $common  Opciones comunes de los campos.
	 * @param Prefill_Resolver $prefill Prellenado de campos.
	 */
	public function __construct(
		private readonly Field_Markup $markup,
		private readonly Common_Settings $common,
		private readonly Prefill_Resolver $prefill
	) {}

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'textarea';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Text area', 'formulario-mensaje' );
	}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'basic';
	}

	/**
	 * Opciones que admite un campo de este tipo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_schema(): array {
		return array(
			$this->common->label( $this->get_label() ),
			$this->common->required(),
			$this->common->placeholder(),
			$this->common->help(),
			...$this->common->prefill(),
			$this->common->css_class(),
		);
	}

	/**
	 * Devuelve el HTML del campo, con todos los valores ya escapados.
	 *
	 * @param Field          $field   Campo que se pinta.
	 * @param Render_Context $context Formulario e instancia en los que se pinta.
	 */
	public function render( Field $field, Render_Context $context ): string {
		$id = $context->control_id( $field );

		$control = sprintf(
			'<textarea%s>%s</textarea>',
			$this->markup->attributes(
				array(
					'class'                 => 'fm-control',
					'id'                    => $id,
					'name'                  => $context->control_name( $field ),
					'rows'                  => 5,
					'placeholder'           => $field->text( 'placeholder' ),
					'maxlength'             => self::MAX_LENGTH,
					'required'              => $field->is_required(),
					'aria-describedby'      => $this->markup->described_by( $field, $id ),
					'data-fm-prefill-query' => $this->prefill->query_param( $field ),
				)
			),
			esc_textarea( $this->prefill->resolve( $field ) )
		);

		return $this->markup->field( $field, $context, $control );
	}

	/**
	 * Sanea el valor recibido en un envío, conservando los saltos de línea.
	 *
	 * @param Field $field Campo al que pertenece el valor.
	 * @param mixed $raw   Valor tal como llegó en la petición.
	 * @return string|string[]
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		return mb_substr( sanitize_textarea_field( (string) $raw ), 0, self::MAX_LENGTH );
	}

	/**
	 * Valida el formato de un valor saneado y no vacío.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function validate( Field $field, string|array $value ): ?string {
		return null;
	}

	/**
	 * Convierte un valor saneado en un texto legible.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function format_value( Field $field, string|array $value ): string {
		return is_array( $value ) ? implode( ', ', $value ) : $value;
	}

	/**
	 * Valor de ejemplo para probar notificaciones y otras acciones.
	 *
	 * @param Field $field Campo.
	 * @return string|string[]
	 */
	public function sample_value( Field $field ): string|array {
		return __( "This is a sample message.\nIt has two lines.", 'formulario-mensaje' );
	}
}
