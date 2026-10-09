<?php
/**
 * Proveedor de los secretos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Secrets;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el cifrado y el almacén de secretos.
 */
final class Secrets_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Secret_Cipher::class, static fn (): Secret_Cipher => new Secret_Cipher() );
		$container->set( Secret_Vault::class, static fn ( Container $c ): Secret_Vault => new Secret_Vault( $c->get( Secret_Cipher::class ) ) );
	}
}
