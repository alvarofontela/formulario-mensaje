<?php
/**
 * Proveedor de los webhooks.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Actions\Actions_Provider;
use Formulario_Mensaje\Antispam\Rate_Limiter;
use Formulario_Mensaje\Container;
use Formulario_Mensaje\Secrets\Secret_Cipher;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Submissions\Sample_Submission_Factory;
use Formulario_Mensaje\Submissions\Tag_Replacer;
use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Declara la acción de webhook y sus piezas.
 */
final class Webhooks_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Webhook_Payload_Builder::class,
			static fn ( Container $c ): Webhook_Payload_Builder => new Webhook_Payload_Builder( $c->get( Tag_Replacer::class ) )
		);

		$container->set( Webhook_Signer::class, static fn (): Webhook_Signer => new Webhook_Signer() );

		$container->set( Host_Resolver::class, static fn (): Host_Resolver => new Dns_Host_Resolver() );

		$container->set(
			Destination_Guard::class,
			static fn ( Container $c ): Destination_Guard => new Destination_Guard( $c->get( Host_Resolver::class ) )
		);

		$container->set(
			Webhook_Sender::class,
			static fn ( Container $c ): Webhook_Sender => new Webhook_Sender(
				$c->get( Webhook_Payload_Builder::class ),
				$c->get( Webhook_Signer::class ),
				$c->get( Secret_Cipher::class ),
				$c->get( Clock::class ),
				$c->get( Destination_Guard::class ),
				FORMULARIO_MENSAJE_VERSION
			)
		);

		$container->set(
			Webhook_Action::class,
			static fn ( Container $c ): Webhook_Action => new Webhook_Action( $c->get( Webhook_Sender::class ), $c->get( Secret_Cipher::class ) )
		);

		$container->set(
			Webhook_Tester::class,
			static fn ( Container $c ): Webhook_Tester => new Webhook_Tester(
				$c->get( Webhook_Sender::class ),
				$c->get( Sample_Submission_Factory::class ),
				$c->get( Rate_Limiter::class )
			)
		);

		add_action(
			Actions_Provider::REGISTER_ACTION,
			static function ( Action_Type_Registry $registry ) use ( $container ): void {
				$registry->register( $container->get( Webhook_Action::class ) );
			},
			5
		);
	}
}
