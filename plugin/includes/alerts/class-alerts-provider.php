<?php
/**
 * Proveedor de los avisos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Alerts;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use Formulario_Mensaje\Mail\Mail_Transport;
use Formulario_Mensaje\Mail\Wp_Mail_Transport;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el aviso de entregas fallidas y lo conecta con los hooks del plugin.
 */
final class Alerts_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Failure_Alert::class,
			static fn ( Container $c ): Failure_Alert => new Failure_Alert(
				$c->get( Settings_Repository::class ),
				$c->get( Mail_Transport::class ),
				$c->get( Wp_Mail_Transport::class ),
				$c->get( Clock::class )
			)
		);

		add_action(
			'formulario_mensaje_delivery_failed',
			static function () use ( $container ): void {
				$container->get( Failure_Alert::class )->report();
			}
		);

		add_action(
			Maintenance_Schedule::HOOK,
			static function () use ( $container ): void {
				$container->get( Failure_Alert::class )->flush();
			}
		);
	}
}
