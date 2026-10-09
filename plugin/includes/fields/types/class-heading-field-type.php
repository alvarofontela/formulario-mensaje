<?php
/**
 * Elemento de presentación: título de sección.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Common_Settings;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Field_Type;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Título de sección. No recoge datos.
 */
final class Heading_Field_Type implements Field_Type {

	/**
	 * Niveles de encabezado permitidos.
	 */
	private const LEVELS = array( '2', '3', '4' );

	/**
	 * Constructor.
	 *
	 * @param Field_Markup    $markup HTML común de los campos.
	 * @param Common_Settings $common Opciones comunes de los campos.
	 */
	public function __construct(
		private readonly Field_Markup $markup,
		private readonly Common_Settings $common
	) {}

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'heading';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Section title', 'formulario-mensaje' );
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
		$label          = $this->common->label( $this->get_label() );
		$label['label'] = __( 'Text', 'formulario-mensaje' );

		return array(
			$label,
			array(
				'key'     => 'level',
				'type'    => 'select',
				'label'   => __( 'Heading level', 'formulario-mensaje' ),
				'default' => '3',
				'choices' => array_map(
					/* translators: %s: heading level, a number from 2 to 4. */
					fn ( string $level ): array => $this->common->choice( $level, sprintf( __( 'Heading %s', 'formulario-mensaje' ), $level ) ),
					self::LEVELS
				),
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
		$text = $field->label();

		if ( '' === $text ) {
			return '';
		}

		$level = $field->text( 'level', '3' );
		$tag   = 'h' . ( in_array( $level, self::LEVELS, true ) ? $level : '3' );

		return $this->markup->presentation( $field, sprintf( '<%1$s class="fm-heading">%2$s</%1$s>', $tag, esc_html( $text ) ) );
	}
}
