<?php
/**
 * Ajustes guardados en una opción de WordPress.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Almacén de ajustes sobre la Options API.
 */
final class Option_Settings_Repository implements Settings_Repository {

	/**
	 * Nombre de la opción.
	 */
	public const OPTION = 'formulario_mensaje_settings';

	/**
	 * Ajustes ya leídos en esta petición.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Constructor.
	 *
	 * @param Settings_Sanitizer $sanitizer Saneado de los ajustes.
	 */
	public function __construct( private readonly Settings_Sanitizer $sanitizer ) {}

	/**
	 * Ajustes actuales; los valores por defecto si no se ha guardado ninguno.
	 */
	public function get(): Settings {
		if ( null === $this->settings ) {
			// Lo guardado se vuelve a sanear: la opción puede haberse editado por otra vía.
			$this->settings = $this->sanitizer->sanitize( get_option( self::OPTION, array() ) );
		}

		return $this->settings;
	}

	/**
	 * Guarda los ajustes.
	 *
	 * @param Settings $settings Ajustes ya saneados.
	 */
	public function save( Settings $settings ): void {
		// Sin carga automática: solo hacen falta al recibir un envío o en la administración.
		update_option( self::OPTION, $settings->to_array(), false );

		$this->settings = $settings;
	}
}
