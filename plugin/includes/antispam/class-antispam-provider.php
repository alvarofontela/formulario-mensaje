<?php
/**
 * Proveedor del antispam.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Support\Clock;
use Formulario_Mensaje\Support\Php_Server_Vars;
use Formulario_Mensaje\Support\Server_Vars;
use Formulario_Mensaje\Support\System_Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Declara las defensas antispam y la cadena que las aplica.
 */
final class Antispam_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Clock::class, static fn (): Clock => new System_Clock() );
		$container->set( Server_Vars::class, static fn (): Server_Vars => new Php_Server_Vars() );
		$container->set( Ip_Matcher::class, static fn (): Ip_Matcher => new Ip_Matcher() );
		$container->set( Cloudflare_Ranges::class, static fn (): Cloudflare_Ranges => new Cloudflare_Ranges() );
		$container->set( Token_Service::class, static fn ( Container $c ): Token_Service => new Token_Service( $c->get( Clock::class ) ) );
		$container->set( Rate_Limiter::class, static fn ( Container $c ): Rate_Limiter => new Transient_Rate_Limiter( $c->get( Clock::class ) ) );

		$container->set(
			Client_Ip_Resolver::class,
			static fn ( Container $c ): Client_Ip_Resolver => new Client_Ip_Resolver(
				$c->get( Settings_Repository::class ),
				$c->get( Server_Vars::class ),
				$c->get( Ip_Matcher::class ),
				$c->get( Cloudflare_Ranges::class )
			)
		);

		$container->set(
			Spam_Guard::class,
			static function ( Container $c ): Spam_Guard {
				$rate_limit = new Rate_Limit_Check( $c->get( Rate_Limiter::class ) );

				// De la más barata a la más cara: el límite por IP es la única que consulta el almacenamiento.
				return new Spam_Guard(
					array( new Honeypot_Check(), new Token_Check( $c->get( Token_Service::class ) ), $rate_limit ),
					array( $rate_limit )
				);
			}
		);
	}
}
