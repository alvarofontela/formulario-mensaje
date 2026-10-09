<?php
/**
 * Datos iniciales de la aplicación de administración.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Admin;

use Formulario_Mensaje\Actions\Action_Type;
use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Fields\Field_Type;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Fields\Input_Field_Type;
use Formulario_Mensaje\Forms\Default_Definition;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Rest\Rest_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Reúne lo que el constructor necesita saber del servidor al arrancar: tipos de campo y de acción
 * registrados (con sus esquemas de opciones), permisos del usuario y direcciones.
 *
 * El constructor pinta los ajustes de cada campo a partir de su esquema, así que un tipo registrado
 * por otro plugin aparece en él sin tocar el JavaScript.
 */
final class Admin_Data {

	/**
	 * Constructor.
	 *
	 * @param Field_Type_Registry  $field_types        Tipos de campo.
	 * @param Action_Type_Registry $action_types       Tipos de acción.
	 * @param Default_Definition   $default_definition Definición de un formulario nuevo.
	 */
	public function __construct(
		private readonly Field_Type_Registry $field_types,
		private readonly Action_Type_Registry $action_types,
		private readonly Default_Definition $default_definition
	) {}

	/**
	 * Datos para la aplicación. No incluyen secretos ni datos de envíos.
	 *
	 * @param string $view Vista inicial: `forms` o `settings`.
	 * @return array<string, mixed>
	 */
	public function get( string $view ): array {
		return array(
			'view'              => $view,
			'restNamespace'     => Rest_Provider::ROUTE_NAMESPACE,
			'fieldTypes'        => array_map(
				static fn ( Field_Type $type ): array => array(
					'type'    => $type->get_type(),
					'label'   => $type->get_label(),
					'group'   => $type->get_group(),
					'isInput' => $type instanceof Input_Field_Type,
					'schema'  => $type->get_settings_schema(),
				),
				$this->field_types->all()
			),
			'fieldGroups'       => array(
				'basic'    => __( 'Basic fields', 'formulario-mensaje' ),
				'choice'   => __( 'Choice fields', 'formulario-mensaje' ),
				'advanced' => __( 'Advanced fields', 'formulario-mensaje' ),
				'layout'   => __( 'Layout', 'formulario-mensaje' ),
			),
			'actionTypes'       => array_map(
				static fn ( Action_Type $type ): array => array(
					'type'     => $type->get_type(),
					'label'    => $type->get_label(),
					'defaults' => $type->default_settings(),
				),
				$this->action_types->all()
			),
			'defaultDefinition' => $this->default_definition->create()->to_array(),
			'can'               => array(
				'manageForms'    => current_user_can( Capabilities::MANAGE_FORMS ),
				'manageSettings' => current_user_can( Capabilities::MANAGE_SETTINGS ),
			),
			'urls'              => array(
				'forms'    => admin_url( 'admin.php?page=' . Admin_Menu::FORMS_PAGE ),
				'settings' => admin_url( 'admin.php?page=' . Admin_Menu::SETTINGS_PAGE ),
			),
			'siteHost'          => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		);
	}
}
