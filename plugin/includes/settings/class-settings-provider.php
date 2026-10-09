<?php
/**
 * Proveedor de los ajustes.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Settings;

use Formulario_Mensaje\Antispam\Ip_Matcher;
use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el almacén de ajustes y su saneado.
 */
final class Settings_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Settings_Sanitizer::class,
			static fn ( Container $c ): Settings_Sanitizer => new Settings_Sanitizer( $c->get( Ip_Matcher::class ) )
		);

		$container->set(
			Settings_Repository::class,
			static fn ( Container $c ): Settings_Repository => new Option_Settings_Repository( $c->get( Settings_Sanitizer::class ) )
		);
	}
}
