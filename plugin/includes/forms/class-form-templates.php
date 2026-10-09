<?php
/**
 * Plantillas de partida.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Mail\Email_Action;

defined( 'ABSPATH' ) || exit;

/**
 * Formularios ya montados entre los que elegir al crear uno nuevo.
 *
 * Una plantilla es solo un punto de partida: una vez elegida, el formulario se edita como cualquier
 * otro y no guarda relación con ella. La primera es la que se usa si no se elige ninguna.
 */
final class Form_Templates {

	/**
	 * Constructor.
	 *
	 * @param Form_Definition_Sanitizer $sanitizer    Saneado de definiciones.
	 * @param Action_Type_Registry      $action_types Tipos de acción.
	 */
	public function __construct(
		private readonly Form_Definition_Sanitizer $sanitizer,
		private readonly Action_Type_Registry $action_types
	) {}

	/**
	 * Plantillas disponibles.
	 *
	 * @return array<int, array{id: string, label: string, description: string, definition: Form_Definition}>
	 */
	public function all(): array {
		/**
		 * Filtra las plantillas entre las que se elige al crear un formulario.
		 *
		 * Cada plantilla es un array con `id`, `label`, `description` y `definition`, esta última con
		 * la misma forma que la definición de un formulario (`fields`, `settings`, `actions`).
		 *
		 * @since 0.6.0
		 *
		 * @param array<int, array<string, mixed>> $templates Plantillas del plugin.
		 */
		$templates = apply_filters( 'formulario_mensaje_form_templates', $this->built_in() );
		$valid     = array();

		foreach ( is_array( $templates ) ? $templates : array() as $template ) {
			if ( ! is_array( $template ) || ! is_string( $template['id'] ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $template['id'] ) ) {
				continue;
			}

			$valid[ $template['id'] ] = array(
				'id'          => $template['id'],
				'label'       => is_string( $template['label'] ?? null ) ? $template['label'] : $template['id'],
				'description' => is_string( $template['description'] ?? null ) ? $template['description'] : '',
				// Las de terceros pasan por el mismo saneado que un formulario guardado desde el constructor.
				'definition'  => $this->sanitizer->sanitize( $template['definition'] ?? array() ),
			);
		}

		return array_values( $valid );
	}

	/**
	 * Plantillas del plugin.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function built_in(): array {
		$name    = $this->field( 'name', 'text', __( 'Name', 'formulario-mensaje' ), array( 'autocomplete' => 'name' ) );
		$email   = $this->field( 'email', 'email', __( 'Email', 'formulario-mensaje' ), array( 'autocomplete' => 'email' ) );
		$phone   = $this->field( 'phone', 'tel', __( 'Phone', 'formulario-mensaje' ), array( 'autocomplete' => 'tel' ) );
		$consent = $this->field( 'consent', 'consent', __( 'Privacy', 'formulario-mensaje' ) );

		return array(
			array(
				'id'          => 'contact',
				'label'       => __( 'Contact', 'formulario-mensaje' ),
				'description' => __( 'Name, email and message.', 'formulario-mensaje' ),
				'definition'  => $this->definition(
					array(
						$name,
						$email,
						$this->field( 'message', 'textarea', __( 'Message', 'formulario-mensaje' ) ),
					)
				),
			),
			array(
				'id'          => 'quote',
				'label'       => __( 'Quote request', 'formulario-mensaje' ),
				'description' => __( 'Contact details in two columns, what the visitor needs and privacy consent.', 'formulario-mensaje' ),
				'definition'  => $this->definition(
					array(
						array( 'width' => 50 ) + $name,
						$this->field( 'company', 'text', __( 'Company', 'formulario-mensaje' ), array( 'autocomplete' => 'organization' ), false, 50 ),
						array( 'width' => 50 ) + $email,
						array( 'width' => 50 ) + $phone,
						$this->field( 'message', 'textarea', __( 'What do you need?', 'formulario-mensaje' ) ),
						$consent,
					)
				),
			),
			array(
				'id'          => 'callback',
				'label'       => __( 'Call me back', 'formulario-mensaje' ),
				'description' => __( 'Name, phone and the best time to call.', 'formulario-mensaje' ),
				'definition'  => $this->definition(
					array(
						$name,
						$phone,
						$this->field(
							'best_time',
							'select',
							__( 'Best time to call', 'formulario-mensaje' ),
							array(
								'options' => array(
									array(
										'label' => __( 'Morning', 'formulario-mensaje' ),
										'value' => 'morning',
									),
									array(
										'label' => __( 'Afternoon', 'formulario-mensaje' ),
										'value' => 'afternoon',
									),
									array(
										'label' => __( 'Any time', 'formulario-mensaje' ),
										'value' => 'any',
									),
								),
							),
							false
						),
						$consent,
					)
				),
			),
			array(
				'id'          => 'newsletter',
				'label'       => __( 'Newsletter signup', 'formulario-mensaje' ),
				'description' => __( 'Only the email address and privacy consent.', 'formulario-mensaje' ),
				'definition'  => $this->definition( array( $email, $consent ) ),
			),
			array(
				'id'          => 'blank',
				'label'       => __( 'Blank form', 'formulario-mensaje' ),
				'description' => __( 'No fields: start from scratch.', 'formulario-mensaje' ),
				'definition'  => $this->definition( array() ),
			),
		);
	}

	/**
	 * Campo de una plantilla.
	 *
	 * @param string               $id       Identificador del campo.
	 * @param string               $type     Tipo de campo.
	 * @param string               $label    Etiqueta, ya traducida.
	 * @param array<string, mixed> $settings Otros ajustes del campo.
	 * @param bool                 $required Si es obligatorio.
	 * @param int                  $width    Ancho, en tanto por ciento.
	 * @return array<string, mixed>
	 */
	private function field( string $id, string $type, string $label, array $settings = array(), bool $required = true, int $width = 100 ): array {
		return array(
			'id'       => $id,
			'type'     => $type,
			'width'    => $width,
			'settings' => array(
				'label'    => $label,
				'required' => $required,
			) + $settings,
		);
	}

	/**
	 * Definición de una plantilla: sus campos y una notificación al administrador del sitio.
	 *
	 * @param array<int, array<string, mixed>> $fields Campos.
	 * @return array<string, mixed>
	 */
	private function definition( array $fields ): array {
		$notification = $this->action_types->get( Email_Action::TYPE );
		$has_email    = in_array( 'email', array_column( $fields, 'id' ), true );

		return array(
			'fields'   => $fields,
			'settings' => array(),
			'actions'  => null === $notification ? array() : array(
				array(
					'id'       => 'notification',
					'type'     => $notification->get_type(),
					'enabled'  => true,
					'name'     => __( 'Notification', 'formulario-mensaje' ),
					'settings' => array_merge( $notification->default_settings(), array( 'reply_to_field' => $has_email ? 'email' : '' ) ),
				),
			),
		);
	}
}
