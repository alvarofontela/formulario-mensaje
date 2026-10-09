<?php
/**
 * Ajustes de correo guardados en una opción de WordPress.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Almacén de ajustes de correo sobre la Options API.
 */
final class Option_Mail_Settings_Repository implements Mail_Settings_Repository {

	/**
	 * Nombre de la opción.
	 */
	public const OPTION = 'formulario_mensaje_mail';

	/**
	 * Constructor.
	 *
	 * @param Mail_Settings_Sanitizer $sanitizer Saneado de los ajustes de correo.
	 */
	public function __construct( private readonly Mail_Settings_Sanitizer $sanitizer ) {}

	/**
	 * Ajustes actuales; los valores por defecto si no se ha guardado ninguno.
	 */
	public function get(): Mail_Settings {
		// Lo guardado se vuelve a sanear: la opción puede haberse editado por otra vía.
		return $this->sanitizer->sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Guarda los ajustes.
	 *
	 * @param Mail_Settings $settings Ajustes ya saneados.
	 */
	public function save( Mail_Settings $settings ): void {
		// Sin carga automática: solo hacen falta al enviar un correo o en la administración.
		update_option( self::OPTION, $settings->to_array(), false );
	}
}
