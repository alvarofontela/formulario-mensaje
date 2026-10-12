<?php
/**
 * Proveedor de la integración con Elementor.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Elementor;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Frontend\Frontend_Assets;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Registra el widget del formulario en Elementor.
 *
 * Todo cuelga de hooks de Elementor: si no está activo, no se dispara ninguno y la clase del widget,
 * que extiende una de las suyas, ni siquiera se carga.
 */
final class Elementor_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Form_Choices::class, static fn ( Container $c ): Form_Choices => new Form_Choices( $c->get( Form_Repository::class ) ) );

		add_action(
			'elementor/widgets/register',
			static function ( $widgets_manager ) use ( $container ): void {
				// Elementor pide las hojas de estilo del widget por su nombre: tienen que estar registradas ya.
				$container->get( Frontend_Assets::class )->register();

				$widgets_manager->register( new Form_Widget() );
			}
		);
	}
}
