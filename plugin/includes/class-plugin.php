<?php
/**
 * Arranque del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje;

defined( 'ABSPATH' ) || exit;

/**
 * Punto único de composición: entrega el contenedor a los proveedores de cada módulo.
 */
final class Plugin {

	/**
	 * Constructor.
	 *
	 * @param Container $container Contenedor de servicios.
	 */
	public function __construct( private readonly Container $container ) {}

	/**
	 * Registra los servicios y los hooks de todos los módulos.
	 */
	public function boot(): void {
		foreach ( $this->providers() as $provider ) {
			$provider->register( $this->container );
		}
	}

	/**
	 * Proveedores del plugin.
	 *
	 * @return Service_Provider[]
	 */
	private function providers(): array {
		return array(
			new Database\Database_Provider(),
			new Lifecycle\Lifecycle_Provider(),
			new Settings\Settings_Provider(),
			new Fields\Fields_Provider(),
			new Actions\Actions_Provider(),
			new Forms\Forms_Provider(),
			new Antispam\Antispam_Provider(),
			new Mail\Mail_Provider(),
			new Storage\Storage_Provider(),
			new Submissions\Submissions_Provider(),
			new Frontend\Frontend_Provider(),
			new Blocks\Blocks_Provider(),
			new Rest\Rest_Provider(),
			new Admin\Admin_Provider(),
		);
	}
}
