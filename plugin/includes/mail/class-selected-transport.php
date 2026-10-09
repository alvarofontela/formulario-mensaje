<?php
/**
 * Transporte de correo elegido en los ajustes.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Entrega cada correo al transporte elegido en los ajustes.
 *
 * Quien envía correo depende de este transporte y no sabe cuál hay detrás. Los transportes se
 * registran por su identificador: añadir uno nuevo no cambia esta clase.
 */
final class Selected_Transport implements Mail_Transport {

	/**
	 * Constructor.
	 *
	 * @param Mail_Settings_Repository      $settings   Ajustes de correo.
	 * @param array<string, Mail_Transport> $transports Transportes disponibles, por identificador.
	 */
	public function __construct(
		private readonly Mail_Settings_Repository $settings,
		private readonly array $transports
	) {}

	/**
	 * Envía un correo con el transporte elegido.
	 *
	 * @param Email_Message $message Correo ya compuesto.
	 * @return true|WP_Error
	 */
	public function send( Email_Message $message ): bool|WP_Error {
		$transport = $this->transports[ $this->settings->get()->transport ] ?? null;

		if ( null === $transport ) {
			return new WP_Error( 'formulario_mensaje_transport_missing', __( 'The email transport chosen in the settings is not available.', 'formulario-mensaje' ) );
		}

		return $transport->send( $message );
	}
}
