<?php
/**
 * Elemento de presentación: párrafo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Common_Settings;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Field_Type;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Support\Inline_Html;

defined( 'ABSPATH' ) || exit;

/**
 * Párrafo con formato básico. No recoge datos.
 */
final class Paragraph_Field_Type implements Field_Type {

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
		return 'paragraph';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Paragraph', 'formulario-mensaje' );
	}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'layout';
	}

	/**
	 * Opciones que admite un campo de este tipo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_schema(): array {
		return array(
			array(
				'key'     => 'content',
				'type'    => 'html',
				'label'   => __( 'Text', 'formulario-mensaje' ),
				'help'    => __( 'You can use basic HTML: bold, italics and links.', 'formulario-mensaje' ),
				'default' => '',
			),
			$this->common->css_class(),
		);
	}

	/**
	 * Devuelve el HTML del elemento, con todos los valores ya escapados.
	 *
	 * @param Field          $field   Campo que se pinta.
	 * @param Render_Context $context Formulario e instancia en los que se pinta.
	 */
	public function render( Field $field, Render_Context $context ): string {
		$content = trim( $field->text( 'content' ) );

		if ( '' === $content ) {
			return '';
		}

		// El texto se filtró al guardar; se vuelve a filtrar al imprimir.
		return $this->markup->presentation( $field, '<p class="fm-paragraph">' . nl2br( $this->inline_html->filter( $content ) ) . '</p>' );
	}
}
