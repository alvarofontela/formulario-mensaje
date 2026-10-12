<?php
/**
 * Resultado de guardar los ajustes de correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que ha quedado guardado y lo que el guardado ha decidido por su cuenta, para contárselo a
 * quien lo pidió: los ajustes pueden no ser exactamente los que llegaron.
 */
final class Mail_Settings_Update {

	/**
	 * Constructor.
	 *
	 * @param Mail_Settings $settings  Ajustes tal como han quedado guardados.
	 * @param string[]      $discarded Nombres de las credenciales descartadas sin que nadie lo pidiera.
	 * @param string[]      $locked    Nombres de los ajustes que no se han cambiado porque están bloqueados.
	 */
	public function __construct(
		public readonly Mail_Settings $settings,
		public readonly array $discarded = array(),
		public readonly array $locked = array()
	) {}
}
