<?php
/**
 * Definición inicial de un formulario nuevo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Forms;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Mail\Email_Action;

defined( 'ABSPATH' ) || exit;

/**
 * Propone el punto de partida de un formulario nuevo: un formulario de contacto con su notificación.
 */
final class Default_Definition {

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
	 * Definición de partida, ya saneada.
	 */
	public function create(): Form_Definition {
		$email = $this->action_types->get( Email_Action::TYPE );

		return $this->sanitizer->sanitize(
			array(
				'fields'   => array(
					array(
						'id'       => 'name',
						'type'     => 'text',
						'settings' => array(
							'label'        => __( 'Name', 'formulario-mensaje' ),
							'required'     => true,
							'autocomplete' => 'name',
						),
					),
					array(
						'id'       => 'email',
						'type'     => 'email',
						'settings' => array(
							'label'        => __( 'Email', 'formulario-mensaje' ),
							'required'     => true,
							'autocomplete' => 'email',
						),
					),
					array(
						'id'       => 'message',
						'type'     => 'textarea',
						'settings' => array(
							'label'    => __( 'Message', 'formulario-mensaje' ),
							'required' => true,
						),
					),
				),
				'settings' => array(),
				'actions'  => null === $email ? array() : array(
					array(
						'id'       => 'notification',
						'type'     => $email->get_type(),
						'enabled'  => true,
						'name'     => __( 'Notification', 'formulario-mensaje' ),
						'settings' => array_merge( $email->default_settings(), array( 'reply_to_field' => 'email' ) ),
					),
				),
			)
		);
	}
}
