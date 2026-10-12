<?php
/**
 * Desinstalación de los datos del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

use Formulario_Mensaje\Alerts\Failure_Alert;
use Formulario_Mensaje\Captcha\Captcha_Config_Repository;
use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Forms\Form_Placements;
use Formulario_Mensaje\Forms\Form_Post_Type;
use Formulario_Mensaje\Mail\Option_Mail_Settings_Repository;
use Formulario_Mensaje\Queue\Queue_Schedule;
use Formulario_Mensaje\Secrets\Secret_Vault;
use Formulario_Mensaje\Settings\Option_Settings_Repository;
use Formulario_Mensaje\Storage\Failure_Counter;
use Formulario_Mensaje\Uploads\Upload_Directory;

defined( 'ABSPATH' ) || exit;

/**
 * Limpia lo que el plugin dejó en el sitio.
 *
 * Siempre retira las capabilities, la tarea programada y las credenciales guardadas (contraseña de
 * SMTP, claves de API): no deben quedar en la base de datos de un sitio que ya no usa el plugin. Los
 * formularios, los envíos y los ajustes solo se borran si el usuario activó expresamente ese ajuste:
 * por defecto se conservan, para que reinstalar el plugin no suponga perderlos.
 */
final class Uninstaller {

	/**
	 * Formularios que se borran en cada consulta.
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param Capabilities         $capabilities Capabilities del plugin.
	 * @param Schema               $schema       Esquema de las tablas del plugin.
	 * @param Maintenance_Schedule $maintenance  Programación del mantenimiento diario.
	 * @param Queue_Schedule       $queue        Programación de la cola.
	 * @param Upload_Directory     $uploads      Almacén privado de ficheros.
	 */
	public function __construct(
		private readonly Capabilities $capabilities,
		private readonly Schema $schema,
		private readonly Maintenance_Schedule $maintenance,
		private readonly Queue_Schedule $queue,
		private readonly Upload_Directory $uploads
	) {}

	/**
	 * Desinstala el plugin.
	 *
	 * En una red, el plugin se activa sitio a sitio y cada sitio tiene sus tablas, sus opciones y sus
	 * ficheros, pero WordPress solo ejecuta la desinstalación una vez: se recorren todos.
	 */
	public function uninstall(): void {
		if ( ! is_multisite() ) {
			$this->uninstall_site();

			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$this->uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Desinstala el plugin del sitio actual. Sus datos solo se borran si ese sitio lo tiene pedido en sus ajustes.
	 */
	private function uninstall_site(): void {
		$this->capabilities->remove_from_all_roles();
		$this->maintenance->clear();
		$this->queue->clear();
		delete_option( Secret_Vault::OPTION );
		delete_option( Failure_Alert::OPTION );
		// Lo averiguado sobre dónde está cada formulario: sin el plugin no significa nada.
		delete_transient( Form_Placements::CACHE_KEY );

		$settings = get_option( Option_Settings_Repository::OPTION, array() );

		if ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) ) {
			$this->delete_data();
		}

		delete_option( Installer::VERSION_OPTION );
	}

	/**
	 * Borra formularios, envíos, registro de entregas y opciones.
	 */
	private function delete_data(): void {
		do {
			$form_ids = get_posts(
				array(
					'post_type'      => Form_Post_Type::NAME,
					'post_status'    => 'any,trash',
					'posts_per_page' => self::BATCH_SIZE,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			foreach ( $form_ids as $form_id ) {
				wp_delete_post( (int) $form_id, true );
			}

			$found = count( $form_ids );
		} while ( self::BATCH_SIZE === $found );

		$this->schema->drop();
		$this->uploads->remove();

		delete_option( Option_Settings_Repository::OPTION );
		delete_option( Option_Mail_Settings_Repository::OPTION );
		delete_option( Captcha_Config_Repository::OPTION );
		delete_option( Failure_Counter::OPTION );
	}
}
