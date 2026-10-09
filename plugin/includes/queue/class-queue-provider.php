<?php
/**
 * Proveedor de la cola.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Queue;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Storage\Delivery_Executor;
use Formulario_Mensaje\Storage\Delivery_Repository;
use Formulario_Mensaje\Storage\Failure_Counter;
use Formulario_Mensaje\Storage\Submission_Repository;
use Formulario_Mensaje\Support\Clock;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Declara la cola de las acciones en segundo plano y la conecta con WP-Cron.
 */
final class Queue_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Delivery_Queue::class,
			static fn ( Container $c ): Delivery_Queue => new Wpdb_Delivery_Queue( $c->get( wpdb::class ), $c->get( Schema::class ), $c->get( Delivery_Repository::class ) )
		);

		$container->set( Retry_Policy::class, static fn (): Retry_Policy => new Retry_Policy() );
		$container->set( Queue_Schedule::class, static fn ( Container $c ): Queue_Schedule => new Queue_Schedule( $c->get( Clock::class ) ) );

		$container->set(
			Queue_Dispatcher::class,
			static fn ( Container $c ): Queue_Dispatcher => new Queue_Dispatcher(
				$c->get( Delivery_Queue::class ),
				$c->get( Queue_Schedule::class ),
				$c->get( Clock::class )
			)
		);

		$container->set(
			Queue_Processor::class,
			static fn ( Container $c ): Queue_Processor => new Queue_Processor(
				$c->get( Delivery_Queue::class ),
				$c->get( Delivery_Executor::class ),
				$c->get( Delivery_Repository::class ),
				$c->get( Submission_Repository::class ),
				$c->get( Retry_Policy::class ),
				$c->get( Queue_Schedule::class ),
				$c->get( Failure_Counter::class ),
				$c->get( Clock::class )
			)
		);

		$process = static function () use ( $container ): void {
			$container->get( Queue_Processor::class )->process();
		};

		add_action( Queue_Schedule::HOOK, $process );

		// El mantenimiento diario, y su botón en los ajustes, recogen lo que WP-Cron haya dejado atrás.
		add_action( Maintenance_Schedule::HOOK, $process );
	}
}
