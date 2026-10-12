<?php
/**
 * Proveedor del almacenamiento de envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Container;
use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Support\Clock;
use Formulario_Mensaje\Uploads\Submission_Uploads;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Declara los envíos guardados, el registro de entregas, la exportación y la retención.
 */
final class Storage_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Submission_Repository::class,
			static fn ( Container $c ): Submission_Repository => new Wpdb_Submission_Repository( $c->get( wpdb::class ), $c->get( Schema::class ) )
		);

		$container->set(
			Delivery_Repository::class,
			static fn ( Container $c ): Delivery_Repository => new Wpdb_Delivery_Repository( $c->get( wpdb::class ), $c->get( Schema::class ) )
		);

		$container->set( Ip_Anonymizer::class, static fn (): Ip_Anonymizer => new Ip_Anonymizer() );

		$container->set(
			Failure_Counter::class,
			static fn ( Container $c ): Failure_Counter => new Failure_Counter( $c->get( Delivery_Repository::class ), $c->get( Submission_Repository::class ) )
		);

		$container->set(
			Submission_Journal::class,
			static fn ( Container $c ): Submission_Journal => new Submission_Journal(
				$c->get( Submission_Repository::class ),
				$c->get( Delivery_Repository::class ),
				$c->get( Ip_Anonymizer::class ),
				$c->get( Failure_Counter::class ),
				$c->get( Clock::class )
			)
		);

		$container->set(
			Submission_Service::class,
			static fn ( Container $c ): Submission_Service => new Submission_Service(
				$c->get( Submission_Repository::class ),
				$c->get( Delivery_Repository::class ),
				$c->get( Failure_Counter::class ),
				$c->get( Clock::class )
			)
		);

		$container->set(
			Delivery_Executor::class,
			static fn ( Container $c ): Delivery_Executor => new Delivery_Executor(
				$c->get( Form_Repository::class ),
				$c->get( Action_Type_Registry::class ),
				$c->get( Submission_Uploads::class )
			)
		);

		$container->set(
			Action_Retrier::class,
			static fn ( Container $c ): Action_Retrier => new Action_Retrier(
				$c->get( Delivery_Repository::class ),
				$c->get( Submission_Repository::class ),
				$c->get( Delivery_Executor::class ),
				$c->get( Failure_Counter::class ),
				$c->get( Clock::class )
			)
		);

		$container->set(
			Csv_Exporter::class,
			static fn ( Container $c ): Csv_Exporter => new Csv_Exporter( $c->get( Submission_Repository::class ) )
		);

		$container->set(
			Retention_Service::class,
			static fn ( Container $c ): Retention_Service => new Retention_Service(
				$c->get( Form_Repository::class ),
				$c->get( Submission_Service::class ),
				$c->get( Submission_Repository::class ),
				$c->get( Delivery_Repository::class ),
				$c->get( Failure_Counter::class ),
				$c->get( Settings_Repository::class ),
				$c->get( Clock::class )
			)
		);

		add_action(
			Maintenance_Schedule::HOOK,
			static function () use ( $container ): void {
				$container->get( Retention_Service::class )->purge();
			}
		);
	}
}
