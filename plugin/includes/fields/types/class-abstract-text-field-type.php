<?php
/**
 * Base de los campos de una sola línea.
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
 * Comportamiento compartido por los campos que se pintan como un `<input>` de una línea.
 *
 * Cada tipo concreto fija el atributo `type` y, si lo necesita, su saneado y su validación.
 */
abstract class Abstract_Text_Field_Type implements Input_Field_Type {

	/**
	 * Constructor.
	 *
	 * @param Field_Markup     $markup  HTML común de los campos.
	 * @param Common_Settings  $common  Opciones comunes de los campos.
	 * @param Prefill_Resolver $prefill Prellenado de campos.
	 */
	public function __construct(
		protected readonly Field_Markup $markup,
		protected readonly Common_Settings $common,
		protected readonly Prefill_Resolver $prefill
	) {}

	/**
	 * Atributo `type` del `<input>`.
	 */
	abstract protected function input_type(): string;

	/**
	 * Longitud máxima del valor, en caracteres. Es un tope de seguridad, no una regla configurable.
	 */
	protected function max_length(): int {
		return 1000;
	}

	/**
	 * Valor inicial de la opción de autocompletado para un campo nuevo.
	 */
	protected function default_autocomplete(): string {
		return '';
	}

	/**
	 * Atributos propios del tipo que se añaden al `<input>`.
	 *
	 * @return array<string, string|int|bool|null>
	 */
	protected function extra_attributes(): array {
		return array();
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
			$this->common->autocomplete( $this->default_autocomplete() ),
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
			'<input%s>',
			$this->markup->attributes(
				array(
					'class'                 => 'fm-control',
					'type'                  => $this->input_type(),
					'id'                    => $id,
					'name'                  => $context->control_name( $field ),
					'value'                 => $this->prefill->resolve( $field ),
					'placeholder'           => $field->text( 'placeholder' ),
					'autocomplete'          => $field->text( 'autocomplete' ),
					'maxlength'             => $this->max_length(),
					'required'              => $field->is_required(),
					'aria-describedby'      => $this->markup->described_by( $field, $id ),
					'data-fm-prefill-query' => $this->prefill->query_param( $field ),
					...$this->extra_attributes(),
				)
			)
		);

		return $this->markup->field( $field, $context, $control );
	}

	/**
	 * Sanea el valor recibido en un envío.
	 *
	 * @param Field $field Campo al que pertenece el valor.
	 * @param mixed $raw   Valor tal como llegó en la petición.
	 * @return string|string[]
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		return mb_substr( sanitize_text_field( (string) $raw ), 0, $this->max_length() );
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
}
