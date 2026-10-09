<?php
/**
 * Ability: leer un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Abilities;

use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Lifecycle\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve la definición de un formulario: sus campos, sus ajustes, su estilo y qué acciones tiene.
 *
 * De las acciones solo dice cuáles son y si están activas, no sus ajustes. Ahí están la dirección
 * de los webhooks, que a menudo es en sí misma una credencial, sus cabeceras y los destinatarios de
 * los correos: nada de eso hace falta para entender un formulario, y así no llega a un agente ni
 * a lo que el agente tenga conectado. Para verlo o cambiarlo está la administración.
 */
final class Get_Form_Ability implements Ability {

	/**
	 * Constructor.
	 *
	 * @param Form_Service $forms Servicio de formularios.
	 */
	public function __construct( private readonly Form_Service $forms ) {}

	/**
	 * Nombre con el que se registra.
	 */
	public function get_name(): string {
		return 'formulario-mensaje/get-form';
	}

	/**
	 * Nombre legible, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Get a form', 'formulario-mensaje' );
	}

	/**
	 * Qué hace y cuándo usarla, ya traducido.
	 */
	public function get_description(): string {
		return __( 'Returns the definition of one form: its fields, settings and style, and which notifications and webhooks it has. The settings of those actions (recipients, webhook addresses, headers and secrets) are never included.', 'formulario-mensaje' );
	}

	/**
	 * Esquema JSON de la entrada.
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Identifier of the form.', 'formulario-mensaje' ),
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema JSON del resultado.
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'status'     => array( 'type' => 'string' ),
				'title'      => array( 'type' => 'string' ),
				'definition' => array(
					'type'        => 'object',
					'description' => __( 'Fields, settings and style of the form, and the list of its actions without their settings.', 'formulario-mensaje' ),
				),
			),
		);
	}

	/**
	 * Indica si el usuario actual puede ejecutarla.
	 */
	public function is_allowed(): bool {
		return current_user_can( Capabilities::MANAGE_FORMS );
	}

	/**
	 * Indica si el resultado lleva texto escrito por visitantes anónimos.
	 */
	public function returns_visitor_content(): bool {
		return false;
	}

	/**
	 * Devuelve el formulario.
	 *
	 * @param array<string, mixed> $input Entrada ya validada.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		$form = is_int( $input['id'] ?? null ) && $input['id'] > 0 ? $this->forms->get( $input['id'] ) : null;

		if ( null === $form ) {
			return new WP_Error( 'formulario_mensaje_form_not_found', __( 'The form does not exist.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		$definition            = $form->definition->to_array();
		$definition['actions'] = array_map(
			// Solo lo que identifica a cada acción: sus ajustes no salen de la administración.
			static fn ( array $action ): array => array(
				'id'      => (string) ( $action['id'] ?? '' ),
				'type'    => (string) ( $action['type'] ?? '' ),
				'name'    => (string) ( $action['name'] ?? '' ),
				'enabled' => ! empty( $action['enabled'] ),
			),
			array_values( array_filter( is_array( $definition['actions'] ?? null ) ? $definition['actions'] : array(), 'is_array' ) )
		);

		return array(
			'id'         => $form->id,
			'status'     => $form->status,
			'title'      => $form->title,
			'definition' => $definition,
		);
	}
}
