<?php
/**
 * Contrato del almacén de ajustes.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lee y guarda los ajustes generales del plugin.
 */
interface Settings_Repository {

	/**
	 * Ajustes actuales; los valores por defecto si no se ha guardado ninguno.
	 */
	public function get(): Settings;

	/**
	 * Guarda los ajustes.
	 *
	 * @param Settings $settings Ajustes ya saneados.
	 */
	public function save( Settings $settings ): void;
}
