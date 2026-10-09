<?php
/**
 * Contrato del almacén de ajustes de correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Lee y guarda los ajustes de correo del plugin.
 */
interface Mail_Settings_Repository {

	/**
	 * Ajustes actuales; los valores por defecto si no se ha guardado ninguno.
	 */
	public function get(): Mail_Settings;

	/**
	 * Guarda los ajustes.
	 *
	 * @param Mail_Settings $settings Ajustes ya saneados.
	 */
	public function save( Mail_Settings $settings ): void;
}
