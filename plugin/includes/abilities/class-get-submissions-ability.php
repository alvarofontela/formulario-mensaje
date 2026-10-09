<?php
/**
 * Ability: leer envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Abilities;

use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Storage\Stored_Submission;
use Formulario_Mensaje\Storage\Submission_Query;
use Formulario_Mensaje\Storage\Submission_Service;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Busca entre los envíos guardados y devuelve sus datos.
 *
 * Una sola ability para todas las preguntas sobre envíos («los de hoy», «los de este formulario»,
 * «los que tienen una entrega fallida»): lo que cambia son los filtros. Son datos personales: exige
 * la capability de ver envíos y no devuelve ni la IP ni el navegador del visitante.
 */
final class Get_Submissions_Ability implements Ability {

	private const DEFAULT_PER_PAGE = 20;
	private const MAX_PER_PAGE     = 100;

	/**
	 * Constructor.
	 *
	 * @param Submission_Service $submissions Servicio de envíos guardados.
	 */
	public function __construct( private readonly Submission_Service $submissions ) {}

	/**
	 * Nombre con el que se registra.
	 */
	public function get_name(): string {
		return 'formulario-mensaje/get-submissions';
	}

	/**
	 * Nombre legible, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Get submissions', 'formulario-mensaje' );
	}

	/**
	 * Qué hace y cuándo usarla, ya traducido.
	 */
	public function get_description(): string {
		return __( 'Searches the stored submissions and returns what each visitor sent, newest first. It can be limited to one form, to a text, to a date range or to the submissions with a failed delivery. The submissions contain personal data.', 'formulario-mensaje' );
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
				'form_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Only the submissions of this form.', 'formulario-mensaje' ),
				),
				'search'      => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Text to look for in the reference number and in the values sent.', 'formulario-mensaje' ),
				),
				'only_failed' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Only the submissions with a notification or webhook that could not be delivered.', 'formulario-mensaje' ),
				),
				'after'       => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Only the submissions received at or after this moment (ISO 8601).', 'formulario-mensaje' ),
				),
				'before'      => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Only the submissions received at or before this moment (ISO 8601).', 'formulario-mensaje' ),
				),
				'page'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Page of results, starting at 1.', 'formulario-mensaje' ),
				),
				'per_page'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_PER_PAGE,
					'default'     => self::DEFAULT_PER_PAGE,
					'description' => __( 'Submissions per page.', 'formulario-mensaje' ),
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
			'type'       => 'object',
			'properties' => array(
				'total' => array(
					'type'        => 'integer',
					'description' => __( 'Number of submissions that match, in all the pages.', 'formulario-mensaje' ),
				),
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'             => array( 'type' => 'integer' ),
							'form_id'        => array( 'type' => 'integer' ),
							'reference'      => array( 'type' => 'string' ),
							'created_at'     => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
							'page_url'       => array( 'type' => 'string' ),
							'failed_actions' => array( 'type' => 'integer' ),
							'entries'        => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'field_id' => array( 'type' => 'string' ),
										'label'    => array( 'type' => 'string' ),
										'value'    => array( 'type' => 'string' ),
									),
								),
							),
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
	 * Busca los envíos.
	 *
	 * @param array<string, mixed> $input Entrada ya validada.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $input ): array|WP_Error {
		$result = $this->submissions->search(
			new Submission_Query(
				is_int( $input['form_id'] ?? null ) ? max( 0, $input['form_id'] ) : 0,
				is_string( $input['search'] ?? null ) ? mb_substr( sanitize_text_field( $input['search'] ), 0, 200 ) : '',
				true === ( $input['only_failed'] ?? false ),
				$this->to_gmt( $input['after'] ?? null ),
				$this->to_gmt( $input['before'] ?? null ),
				is_int( $input['page'] ?? null ) ? $input['page'] : 1,
				// El esquema declara el valor por defecto, pero la API no lo pone en la entrada.
				is_int( $input['per_page'] ?? null ) ? min( self::MAX_PER_PAGE, $input['per_page'] ) : self::DEFAULT_PER_PAGE
			)
		);

		return array(
			'total' => $result['total'],
			'items' => array_map( array( $this, 'item' ), array_values( $result['items'] ) ),
		);
	}

	/**
	 * Datos de un envío.
	 *
	 * @param Stored_Submission $submission Envío guardado.
	 * @return array<string, mixed>
	 */
	private function item( Stored_Submission $submission ): array {
		$entries = array();

		foreach ( $submission->entries as $entry ) {
			$entries[] = array(
				'field_id' => $entry->field_id,
				'label'    => $entry->label,
				'value'    => $entry->formatted,
			);
		}

		return array(
			'id'             => $submission->id,
			'form_id'        => $submission->form_id,
			'reference'      => $submission->reference,
			'created_at'     => gmdate( 'Y-m-d\TH:i:s\Z', $submission->created_at ),
			'page_url'       => $submission->page_url,
			'failed_actions' => $submission->failed_actions,
			'entries'        => $entries,
		);
	}

	/**
	 * Convierte un momento en ISO 8601 a una fecha GMT en formato MySQL.
	 *
	 * @param mixed $moment Momento recibido.
	 * @return string Cadena vacía si no hay o no es una fecha.
	 */
	private function to_gmt( mixed $moment ): string {
		$timestamp = is_string( $moment ) && '' !== $moment ? strtotime( $moment ) : false;

		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
