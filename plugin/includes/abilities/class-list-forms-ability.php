<?php
/**
 * Ability: listar formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Abilities;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Frontend\Shortcode;
use Formulario_Mensaje\Lifecycle\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lista los formularios del sitio, publicados o en la papelera.
 */
final class List_Forms_Ability implements Ability {

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
		return 'formulario-mensaje/list-forms';
	}

	/**
	 * Nombre legible, ya traducido.
	 */
	public function get_label(): string {
		return __( 'List forms', 'formulario-mensaje' );
	}

	/**
	 * Qué hace y cuándo usarla, ya traducido.
	 */
	public function get_description(): string {
		return __( 'Lists the forms created with Formulario Mensaje, with their title, number of fields and the shortcode that inserts each one. By default it returns the published forms; it can return the ones in the trash instead.', 'formulario-mensaje' );
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
				'status' => array(
					'type'        => 'string',
					'enum'        => array( Form::STATUS_PUBLISHED, Form::STATUS_TRASHED ),
					'default'     => Form::STATUS_PUBLISHED,
					'description' => __( 'Which forms to list: the published ones or the ones in the trash.', 'formulario-mensaje' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	/**
	 * Esquema JSON del resultado.
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'title'       => array( 'type' => 'string' ),
					'status'      => array( 'type' => 'string' ),
					'modified'    => array(
						'type'        => 'string',
						'description' => __( 'Date of the last change, in the site time zone.', 'formulario-mensaje' ),
					),
					'field_count' => array( 'type' => 'integer' ),
					'shortcode'   => array( 'type' => 'string' ),
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
	 * Lista los formularios.
	 *
	 * @param array<string, mixed> $input Entrada ya validada.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		$status = Form::STATUS_TRASHED === ( $input['status'] ?? null ) ? Form::STATUS_TRASHED : Form::STATUS_PUBLISHED;

		return array_map(
			static fn ( Form $form ): array => array(
				'id'          => $form->id,
				'title'       => $form->title,
				'status'      => $form->status,
				'modified'    => $form->modified,
				'field_count' => count( $form->definition->fields ),
				'shortcode'   => sprintf( '[%s id="%d"]', Shortcode::TAG, $form->id ),
			),
			array_values( $this->forms->list( $status ) )
		);
	}
}
