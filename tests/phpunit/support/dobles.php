<?php
/**
 * Dobles de prueba y caso base para los tests del plugin.
 */

use Formulario_Mensaje\Mail\Email_Message;
use Formulario_Mensaje\Mail\Mail_Transport;
use Formulario_Mensaje\Settings\Settings;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Support\Clock;
use Formulario_Mensaje\Support\Request_Query;
use Formulario_Mensaje\Support\Server_Vars;

/** Reloj que se puede adelantar a voluntad. */
final class Reloj_Falso implements Clock {
	public function __construct( public int $ahora = 1_700_000_000 ) {}

	public function now(): int {
		return $this->ahora;
	}

	public function avanzar( int $segundos ): void {
		$this->ahora += $segundos;
	}
}

/** Variables del servidor fijadas por el test. */
final class Servidor_Falso implements Server_Vars {
	/** @param array<string, string> $valores */
	public function __construct( public array $valores = array() ) {}

	public function get( string $name ): string {
		return $this->valores[ $name ] ?? '';
	}
}

/** Parámetros de la URL fijados por el test. */
final class Consulta_Falsa implements Request_Query {
	/** @param array<string, string> $valores */
	public function __construct( public array $valores = array() ) {}

	public function get( string $name ): string {
		return $this->valores[ $name ] ?? '';
	}
}

/** Ajustes en memoria. */
final class Ajustes_En_Memoria implements Settings_Repository {
	public function __construct( public Settings $ajustes = new Settings() ) {}

	public function get(): Settings {
		return $this->ajustes;
	}

	public function save( Settings $settings ): void {
		$this->ajustes = $settings;
	}
}

/** Transporte que guarda los correos en vez de enviarlos, y puede simular un fallo. */
final class Transporte_Espia implements Mail_Transport {
	/** @var Email_Message[] */
	public array $enviados = array();

	public bool $falla = false;

	public function send( Email_Message $message ): bool|WP_Error {
		if ( $this->falla ) {
			return new WP_Error( 'fallo_simulado', 'Fallo simulado.' );
		}

		$this->enviados[] = $message;

		return true;
	}
}

/**
 * Caso base: utilidades para crear usuarios con las capabilities del plugin, formularios y peticiones REST.
 */
abstract class Caso_Formulario extends WP_UnitTestCase {

	protected const RUTA = '/formulario-mensaje/v1';

	public function set_up() {
		parent::set_up();

		// Los tests no esperan el tiempo mínimo de relleno salvo los que lo comprueban expresamente.
		add_filter( 'formulario_mensaje_min_fill_seconds', '__return_zero' );
		reset_phpmailer_instance();
	}

	/** Esquema de las tablas del plugin sobre la base de datos de tests. */
	protected function esquema(): Formulario_Mensaje\Database\Schema {
		global $wpdb;

		return new Formulario_Mensaje\Database\Schema( $wpdb );
	}

	/** Instalador del plugin con una versión dada. */
	protected function instalador( string $version ): Formulario_Mensaje\Lifecycle\Installer {
		return new Formulario_Mensaje\Lifecycle\Installer(
			new Formulario_Mensaje\Lifecycle\Capabilities(),
			$this->esquema(),
			new Formulario_Mensaje\Lifecycle\Maintenance_Schedule(),
			$version
		);
	}

	/** Inicia sesión como un administrador, que tiene todas las capabilities del plugin. */
	protected function como_administrador(): int {
		// El rol las recibe al activar el plugin; en los tests se conceden aquí.
		( new Formulario_Mensaje\Lifecycle\Capabilities() )->grant_to_administrator();

		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );

		return $id;
	}

	/** Inicia sesión con un rol sin capabilities del plugin. */
	protected function como( string $rol ): int {
		$id = self::factory()->user->create( array( 'role' => $rol ) );
		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * Lanza una petición REST.
	 *
	 * @param array<string, mixed> $parametros
	 */
	protected function rest( string $metodo, string $ruta, array $parametros = array() ): WP_REST_Response {
		$peticion = new WP_REST_Request( $metodo, self::RUTA . $ruta );

		foreach ( $parametros as $clave => $valor ) {
			$peticion->set_param( $clave, $valor );
		}

		return rest_get_server()->dispatch( $peticion );
	}

	/**
	 * Definición de un formulario de contacto con una notificación.
	 *
	 * @param array<string, mixed> $cambios Claves de la definición que se sustituyen.
	 * @return array<string, mixed>
	 */
	protected function definicion( array $cambios = array() ): array {
		return array_merge(
			array(
				'fields'   => array(
					array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre', 'required' => true ) ),
					array( 'id' => 'correo', 'type' => 'email', 'settings' => array( 'label' => 'Correo', 'required' => true ) ),
					array( 'id' => 'mensaje', 'type' => 'textarea', 'settings' => array( 'label' => 'Mensaje' ) ),
				),
				'settings' => array(),
				'actions'  => array(
					array(
						'id'       => 'aviso',
						'type'     => 'email',
						'enabled'  => true,
						'settings' => array(
							'to'             => array( 'ventas@example.org' ),
							'subject'        => 'Nuevo envío: {form_title}',
							'body'           => '{all_fields}',
							'reply_to_field' => 'correo',
						),
					),
				),
			),
			$cambios
		);
	}

	/**
	 * Crea un formulario como administrador y deja la sesión cerrada, como un visitante.
	 *
	 * @param array<string, mixed> $cambios Claves de la definición que se sustituyen.
	 * @return int Identificador del formulario.
	 */
	protected function crear_formulario( array $cambios = array(), string $titulo = 'Contacto' ): int {
		$this->como_administrador();

		$respuesta = $this->rest( 'POST', '/forms', array( 'title' => $titulo, 'definition' => $this->definicion( $cambios ) ) );

		$this->assertSame( 201, $respuesta->get_status(), wp_json_encode( $respuesta->get_data() ) );

		wp_set_current_user( 0 );

		return $respuesta->get_data()['id'];
	}

	/**
	 * Envía un formulario por REST como lo hace el script: pide el token y lo adjunta.
	 *
	 * @param array<string, mixed> $valores Valores de los campos.
	 * @param array<string, mixed> $extra   Parámetros adicionales de la petición.
	 */
	protected function enviar( int $formulario, array $valores, array $extra = array() ): WP_REST_Response {
		$token = $this->rest( 'POST', "/forms/$formulario/token" )->get_data()['token'] ?? '';

		return $this->rest( 'POST', "/forms/$formulario/submissions", array_merge( array( 'fm' => $valores, 'token' => $token ), $extra ) );
	}
}
