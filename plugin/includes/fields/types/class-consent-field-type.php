<?php
/**
 * Tipo de campo: consentimiento.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Common_Settings;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Input_Field_Type;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Support\Inline_Html;

defined( 'ABSPATH' ) || exit;

/**
 * Casilla de consentimiento, con un texto que puede enlazar a la política de privacidad.
 */
final class Consent_Field_Type implements Input_Field_Type {

	/**
	 * Valor que envía la casilla marcada.
	 */
	private const ACCEPTED = '1';

	/**
	 * Constructor.
	 *
	 * @param Field_Markup    $markup      HTML común de los campos.
	 * @param Common_Settings $common      Opciones comunes de los campos.
	 * @param Inline_Html     $inline_html Filtro del HTML permitido en los textos.
	 */
	public function __construct(
		private readonly Field_Markup $markup,
		private readonly Common_Settings $common,
		private readonly Inline_Html $inline_html
	) {}

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'consent';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Consent', 'formulario-mensaje' );
	}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'choice';
	}

	/**
	 * Opciones que admite un campo de este tipo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_schema(): array {
		$label         = $this->common->label( __( 'Privacy consent', 'formulario-mensaje' ) );
		$label['help'] = __( 'Name of the field in notifications and in the list of submissions.', 'formulario-mensaje' );

		return array(
			$label,
			array(
				'key'     => 'text',
				'type'    => 'html',
				'label'   => __( 'Consent text', 'formulario-mensaje' ),
				'help'    => __( 'You can use basic HTML, for example a link to the privacy policy.', 'formulario-mensaje' ),
				'default' => __( 'I have read and accept the privacy policy.', 'formulario-mensaje' ),
			),
			$this->common->required( true ),
			$this->common->help(),
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
			'<div class="fm-choice"><input%s><label for="%s">%s%s</label></div>',
			$this->markup->attributes(
				array(
					'type'             => 'checkbox',
					'id'               => $id,
					'name'             => $context->control_name( $field ),
					'value'            => self::ACCEPTED,
					'required'         => $field->is_required(),
					'aria-describedby' => $this->markup->described_by( $field, $id ),
				)
			),
			esc_attr( $id ),
			// El texto se filtró al guardar; se vuelve a filtrar al imprimir.
			$this->inline_html->filter( $field->text( 'text' ) ),
			$this->markup->required_mark( $field )
		);

		return $this->markup->bare( $field, $context, $control );
	}

	/**
	 * Sanea el valor recibido en un envío: aceptado o vacío.
	 *
	 * @param Field $field Campo al que pertenece el valor.
	 * @param mixed $raw   Valor tal como llegó en la petición.
	 * @return string|string[]
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array {
		return is_scalar( $raw ) && self::ACCEPTED === (string) $raw ? self::ACCEPTED : '';
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
	 * Convierte un valor saneado en un texto legible: si se aceptó y qué texto se aceptó.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function format_value( Field $field, string|array $value ): string {
		if ( self::ACCEPTED !== $value ) {
			return __( 'Not accepted', 'formulario-mensaje' );
		}

		return sprintf(
			/* translators: %s: text of the consent that the visitor accepted. */
			__( 'Accepted: %s', 'formulario-mensaje' ),
			wp_strip_all_tags( $field->text( 'text' ) )
		);
	}
}
