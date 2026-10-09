<?php
/**
 * Arranque del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje;

defined( 'ABSPATH' ) || exit;

/**
 * Punto único de composición: entrega el contenedor a los proveedores de cada módulo.
 */
final class Plugin {

	/**
	 * Contenedor del plugin ya arrancado.
	 *
	 * Es el único estado estático del plugin, y existe solo para service(): ver su comentario.
	 *
	 * @var Container|null
	 */
	private static ?Container $booted = null;

	/**
	 * Constructor.
	 *
	 * @param Container $container Contenedor de servicios.
	 */
	public function __construct( private readonly Container $container ) {}

	/**
	 * Registra los servicios y los hooks de todos los módulos.
	 */
	public function boot(): void {
		foreach ( $this->providers() as $provider ) {
			$provider->register( $this->container );
		}

		self::$booted = $this->container;
	}

	/**
	 * Entrega un servicio a una clase que el plugin no construye y que por eso no puede recibirlo por
	 * su constructor. El caso es el widget de Elementor, que Elementor instancia por su cuenta.
	 *
	 * No es un localizador de servicios de uso general: ningún servicio del plugin lo llama.
	 *
	 * @param string $id Identificador del servicio: el nombre de su clase o interfaz.
	 * @throws \LogicException Si el plugin todavía no ha arrancado.
	 */
	public static function service( string $id ): mixed {
		if ( null === self::$booted ) {
			throw new \LogicException( 'Formulario Mensaje is not initialized yet.' );
		}

		return self::$booted->get( $id );
	}

	/**
	 * Proveedores del plugin.
	 *
	 * @return Service_Provider[]
	 */
	private function providers(): array {
		return array(
			new Database\Database_Provider(),
			new Lifecycle\Lifecycle_Provider(),
			new Secrets\Secrets_Provider(),
			new Rules\Rules_Provider(),
			new Settings\Settings_Provider(),
			new Fields\Fields_Provider(),
			new Actions\Actions_Provider(),
			new Forms\Forms_Provider(),
			new Captcha\Captcha_Module_Provider(),
			new Antispam\Antispam_Provider(),
			new Uploads\Uploads_Provider(),
			new Mail\Mail_Provider(),
			new Storage\Storage_Provider(),
			new Queue\Queue_Provider(),
			new Webhooks\Webhooks_Provider(),
			new Alerts\Alerts_Provider(),
			new Submissions\Submissions_Provider(),
			new Frontend\Frontend_Provider(),
			new Blocks\Blocks_Provider(),
			new Elementor\Elementor_Provider(),
			new Rest\Rest_Provider(),
			new Admin\Admin_Provider(),
		);
	}
}
