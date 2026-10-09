<?php
/**
 * Activación del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Atiende el hook de activación.
 */
final class Activator {

	/**
	 * Constructor.
	 *
	 * @param Installer $installer Instalador de los datos del plugin.
	 */
	public function __construct( private readonly Installer $installer ) {}

	/**
	 * Activa el plugin en el sitio actual.
	 *
	 * El plugin no soporta multisitio: la activación para toda la red se rechaza. WordPress marca el
	 * plugin como activo después de ejecutar este hook, así que detener la ejecución aquí la impide.
	 *
	 * @param bool $network_wide Si se está activando para toda la red.
	 */
	public function activate( bool $network_wide ): void {
		if ( $network_wide ) {
			wp_die(
				esc_html__( 'Formulario Mensaje cannot be network activated. Activate it on each site instead.', 'formulario-mensaje' ),
				esc_html__( 'Plugin activation error', 'formulario-mensaje' ),
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}

		$this->installer->install();
	}
}
