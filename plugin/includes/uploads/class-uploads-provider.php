<?php
/**
 * Proveedor de la subida de ficheros.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use Formulario_Mensaje\Mail\Attachment_Source;
use Formulario_Mensaje\Service_Provider;
use Formulario_Mensaje\Support\Clock;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Declara el almacén de ficheros de los envíos y sus descargas.
 */
final class Uploads_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( File_Types::class, static fn (): File_Types => new File_Types() );
		$container->set( Upload_Directory::class, static fn (): Upload_Directory => new Upload_Directory() );
		$container->set( Upload_Mover::class, static fn (): Upload_Mover => new Wp_Upload_Mover() );
		$container->set( Download_Links::class, static fn ( Container $c ): Download_Links => new Download_Links( $c->get( Clock::class ) ) );

		$container->set(
			File_Repository::class,
			static fn ( Container $c ): File_Repository => new Wpdb_File_Repository( $c->get( wpdb::class ), $c->get( Schema::class ) )
		);

		$container->set(
			Upload_Collector::class,
			static fn ( Container $c ): Upload_Collector => new Upload_Collector( $c->get( Field_Type_Registry::class ) )
		);

		$container->set(
			Upload_Store::class,
			static fn ( Container $c ): Upload_Store => new Upload_Store(
				$c->get( File_Repository::class ),
				$c->get( Upload_Mover::class ),
				$c->get( Upload_Directory::class ),
				$c->get( Field_Type_Registry::class ),
				$c->get( Clock::class )
			)
		);

		$container->set(
			Submission_Uploads::class,
			static fn ( Container $c ): Submission_Uploads => new Submission_Uploads(
				$c->get( Upload_Collector::class ),
				$c->get( Upload_Store::class ),
				$c->get( Download_Links::class ),
				$c->get( Form_Repository::class )
			)
		);

		// Las notificaciones piden sus adjuntos sin saber de dónde salen.
		$container->set( Attachment_Source::class, static fn ( Container $c ): Attachment_Source => $c->get( Upload_Store::class ) );

		$container->set(
			Download_Controller::class,
			static fn ( Container $c ): Download_Controller => new Download_Controller( $c->get( Upload_Store::class ), $c->get( Download_Links::class ) )
		);

		// La descarga la piden tanto quien tiene sesión como quien llega con un enlace firmado.
		$download = static function () use ( $container ): void {
			$container->get( Download_Controller::class )->handle();
		};

		add_action( 'admin_post_' . Download_Links::ACTION, $download );
		add_action( 'admin_post_nopriv_' . Download_Links::ACTION, $download );

		// Al borrarse un envío, por la vía que sea, se borran sus ficheros.
		add_action(
			'formulario_mensaje_submissions_deleted',
			static function ( array $ids ) use ( $container ): void {
				$container->get( Upload_Store::class )->delete_for_submissions( $ids );
			}
		);

		// Cuando un envío ya no tiene entregas fallidas, sobran los ficheros que se guardaban solo para reintentarlas.
		add_action(
			'formulario_mensaje_submission_settled',
			static function ( int $submission_id ) use ( $container ): void {
				$container->get( Submission_Uploads::class )->settle( $submission_id );
			}
		);

		add_action(
			Maintenance_Schedule::HOOK,
			static function () use ( $container ): void {
				$container->get( Upload_Store::class )->delete_orphans();
			}
		);
	}
}
