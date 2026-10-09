<?php
/**
 * Proveedor de servicios del captcha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Secrets\Secret_Vault;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Declara los servicios del captcha.
 *
 * No se llama `Captcha_Provider`, como los proveedores de los demás módulos, porque ese es el nombre
 * del contrato de un servicio de captcha.
 */
final class Captcha_Module_Provider implements Service_Provider {

	/**
	 * Acción en la que el plugin y terceros registran sus proveedores de captcha.
	 */
	public const REGISTER_ACTION = 'formulario_mensaje_register_captcha_providers';

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Siteverify_Client::class, static fn (): Siteverify_Client => new Siteverify_Client() );

		$container->set(
			Captcha_Provider_Registry::class,
			static function (): Captcha_Provider_Registry {
				$registry = new Captcha_Provider_Registry();

				/**
				 * Registra los proveedores de captcha.
				 *
				 * Un proveedor de terceros necesita además su adaptador en el navegador, con el mismo
				 * identificador, en `window.formularioMensajeCaptchaAdapters`.
				 *
				 * @since 0.5.0
				 *
				 * @param Captcha_Provider_Registry $registry Registro de proveedores de captcha.
				 */
				do_action( 'formulario_mensaje_register_captcha_providers', $registry );

				return $registry;
			}
		);

		$container->set(
			Captcha_Config_Sanitizer::class,
			static fn ( Container $c ): Captcha_Config_Sanitizer => new Captcha_Config_Sanitizer( $c->get( Captcha_Provider_Registry::class ) )
		);

		$container->set(
			Captcha_Config_Repository::class,
			static fn ( Container $c ): Captcha_Config_Repository => new Captcha_Config_Repository(
				$c->get( Captcha_Config_Sanitizer::class ),
				$c->get( Secret_Vault::class )
			)
		);

		$container->set(
			Captcha_Service::class,
			static fn ( Container $c ): Captcha_Service => new Captcha_Service(
				$c->get( Captcha_Config_Repository::class ),
				$c->get( Captcha_Provider_Registry::class )
			)
		);

		$container->set(
			Captcha_Check::class,
			static fn ( Container $c ): Captcha_Check => new Captcha_Check( $c->get( Captcha_Service::class ) )
		);

		// Añadir un servicio de captcha es añadir una clase y una línea aquí, más su adaptador en `captcha.js`.
		add_action(
			self::REGISTER_ACTION,
			static function ( Captcha_Provider_Registry $registry ) use ( $container ): void {
				$client = $container->get( Siteverify_Client::class );

				$registry->register( new Turnstile_Provider( $client ) );
				$registry->register( new Recaptcha_Provider( $client ) );
				$registry->register( new Hcaptcha_Provider( $client ) );
			},
			5
		);
	}
}
