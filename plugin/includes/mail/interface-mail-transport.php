<?php
/**
 * Contrato de un transporte de correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Entrega un correo del plugin. Quien lo usa no sabe si detrás hay `wp_mail`, SMTP o una API.
 */
interface Mail_Transport {

	/**
	 * Envía un correo.
	 *
	 * @param Email_Message $message Correo ya compuesto.
	 * @return true|WP_Error True si el transporte lo aceptó; el error no contiene el cuerpo del correo.
	 */
	public function send( Email_Message $message ): bool|WP_Error;
}
