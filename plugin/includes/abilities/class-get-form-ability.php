<?php
/**
 * Ability: leer un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Abilities;

use Formulario_Mensaje\Forms\Form_Porter;
use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Lifecycle\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve la definición completa de un formulario, sin sus secretos.
 *
 * Es el mismo documento que descarga «Exportar»: campos, ajustes, estilo y acciones, sin el secreto
 * de firma de los webhooks ni el valor de sus cabeceras. Un agente no necesita credenciales para
 * entender un formulario, y así no salen de la instalación.
 */
final class Get_Form_Ability implements Ability {

	/**
	 * Constructor.
	 *
	 * @param Form_Service $forms  Servicio de formularios.
	 * @param Form_Porter  $porter Exportación de formularios.
	 */
	public function __construct(
		private readonly Form_Service $forms,
		private readonly Form_Porter $porter
	) {}

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
		return __( 'Returns the full definition of one form: its fields, settings, style, notifications and webhooks. Secrets such as webhook signing keys and header values are never included.', 'formulario-mensaje' );
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
					'description' => __( 'Fields, settings, style and actions of the form.', 'formulario-mensaje' ),
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

		$document = $this->porter->export( $form );

		return array(
			'id'         => $form->id,
			'status'     => $form->status,
			'title'      => $document['title'],
			'definition' => $document['definition'],
		);
	}
}
