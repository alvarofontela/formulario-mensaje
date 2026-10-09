<?php
/**
 * Proveedor del ciclo de vida.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Declara las capabilities y el instalador, y actualiza los datos cuando cambia la versión del código.
 */
final class Lifecycle_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Capabilities::class, static fn (): Capabilities => new Capabilities() );

		$container->set(
			Installer::class,
			static fn ( Container $c ): Installer => new Installer( $c->get( Capabilities::class ), FORMULARIO_MENSAJE_VERSION )
		);

		$container->set(
			Translations::class,
			static fn (): Translations => new Translations( plugin_basename( FORMULARIO_MENSAJE_FILE ) )
		);

		// Una actualización por zip no pasa por el hook de activación: se detecta en la administración.
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( Installer::class )->maybe_upgrade();
			}
		);

		add_action(
			'init',
			static function () use ( $container ): void {
				$container->get( Translations::class )->register();
			},
			1
		);
	}
}
