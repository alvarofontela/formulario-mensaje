<?php
/**
 * Desinstalación de los datos del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Forms\Form_Post_Type;
use Formulario_Mensaje\Mail\Option_Mail_Settings_Repository;
use Formulario_Mensaje\Secrets\Secret_Vault;
use Formulario_Mensaje\Settings\Option_Settings_Repository;
use Formulario_Mensaje\Storage\Failure_Counter;

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
	 */
	public function __construct(
		private readonly Capabilities $capabilities,
		private readonly Schema $schema,
		private readonly Maintenance_Schedule $maintenance
	) {}

	/**
	 * Desinstala el plugin.
	 */
	public function uninstall(): void {
		$this->capabilities->remove_from_all_roles();
		$this->maintenance->clear();
		delete_option( Secret_Vault::OPTION );

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

		delete_option( Option_Settings_Repository::OPTION );
		delete_option( Option_Mail_Settings_Repository::OPTION );
		delete_option( Failure_Counter::OPTION );
	}
}
