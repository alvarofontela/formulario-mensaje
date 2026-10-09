<?php
/**
 * Elemento de presentación: separador.
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
 * Línea separadora. No recoge datos.
 */
final class Divider_Field_Type implements Field_Type {

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
		return 'divider';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Divider', 'formulario-mensaje' );
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
		return array( $this->common->css_class() );
	}

	/**
	 * Devuelve el HTML del elemento.
	 *
	 * @param Field          $field   Campo que se pinta.
	 * @param Render_Context $context Formulario e instancia en los que se pinta.
	 */
	public function render( Field $field, Render_Context $context ): string {
		return $this->markup->presentation( $field, '<hr class="fm-divider">' );
	}
}
