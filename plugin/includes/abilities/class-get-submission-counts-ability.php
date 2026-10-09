<?php
/**
 * Ability: recuento de envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Abilities;

use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Storage\Submission_Service;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cuántos envíos guardados hay, en total y por formulario. No necesita entrada.
 */
final class Get_Submission_Counts_Ability implements Ability {

	/**
	 * Constructor.
	 *
	 * @param Submission_Service $submissions Servicio de envíos guardados.
	 * @param Form_Service       $forms       Servicio de formularios.
	 */
	public function __construct(
		private readonly Submission_Service $submissions,
		private readonly Form_Service $forms
	) {}

	/**
	 * Nombre con el que se registra.
	 */
	public function get_name(): string {
		return 'formulario-mensaje/get-submission-counts';
	}

	/**
	 * Nombre legible, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Get submission counts', 'formulario-mensaje' );
	}

	/**
	 * Qué hace y cuándo usarla, ya traducido.
	 */
	public function get_description(): string {
		return __( 'Returns how many submissions are stored, in total and for each form. It takes no input and returns no personal data.', 'formulario-mensaje' );
	}

	/**
	 * Esquema JSON de la entrada.
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
			'type'       => 'object',
			'properties' => array(
				'total' => array( 'type' => 'integer' ),
				'forms' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'form_id' => array( 'type' => 'integer' ),
							'title'   => array(
								'type'        => 'string',
								'description' => __( 'Title of the form; empty if the form no longer exists.', 'formulario-mensaje' ),
							),
							'count'   => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Indica si el usuario actual puede ejecutarla.
	 */
	public function is_allowed(): bool {
		return current_user_can( Capabilities::VIEW_SUBMISSIONS );
	}

	/**
	 * Cuenta los envíos.
	 *
	 * @param array<string, mixed> $input Entrada; no se usa.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		$forms = array();

		foreach ( $this->submissions->counts_by_form() as $form_id => $count ) {
			$form    = $this->forms->get( (int) $form_id );
			$forms[] = array(
				'form_id' => (int) $form_id,
				'title'   => null === $form ? '' : $form->title,
				'count'   => (int) $count,
			);
		}

		return array(
			'total' => array_sum( array_column( $forms, 'count' ) ),
			'forms' => $forms,
		);
	}
}
