<?php
/**
 * Captcha: proveedores, comprobación en el envío, ajustes y marcado del formulario.
 */

use Formulario_Mensaje\Captcha\Captcha_Check;
use Formulario_Mensaje\Captcha\Captcha_Config;
use Formulario_Mensaje\Captcha\Captcha_Config_Repository;
use Formulario_Mensaje\Captcha\Captcha_Config_Sanitizer;
use Formulario_Mensaje\Captcha\Captcha_Provider_Registry;
use Formulario_Mensaje\Captcha\Captcha_Service;
use Formulario_Mensaje\Captcha\Hcaptcha_Provider;
use Formulario_Mensaje\Captcha\Recaptcha_Provider;
use Formulario_Mensaje\Captcha\Siteverify_Client;
use Formulario_Mensaje\Captcha\Turnstile_Provider;
use Formulario_Mensaje\Forms\Post_Form_Repository;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Secrets\Secret_Cipher;
use Formulario_Mensaje\Secrets\Secret_Vault;
use Formulario_Mensaje\Submissions\Submission_Channel;
use Formulario_Mensaje\Submissions\Submission_Request;

class CaptchaTest extends Caso_Formulario {

	/** Peticiones de verificación que el plugin ha hecho. */
	private array $peticiones = array();

	/** Respuesta del servicio simulado: un array con el JSON, o un WP_Error. */
	private $respuesta = array( 'success' => true );

	public function set_up() {
		parent::set_up();

		$this->peticiones = array();
		$this->respuesta  = array( 'success' => true );

		add_filter(
			'pre_http_request',
			function ( $previo, array $argumentos, string $url ) {
				$this->peticiones[] = array( 'url' => $url, 'argumentos' => $argumentos );

				if ( is_wp_error( $this->respuesta ) ) {
					return $this->respuesta;
				}

				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $this->respuesta ), 'headers' => array(), 'cookies' => array() );
			},
			10,
			3
		);
	}

	/** Deja el captcha configurado para todo el sitio. */
	private function configurar( string $proveedor = 'turnstile', array $ajustes = array() ): void {
		$this->como_administrador();

		$respuesta = $this->rest(
			'PUT',
			'/settings/captcha',
			array( 'settings' => array_merge( array( 'provider' => $proveedor, 'site_key' => 'clave-publica' ), $ajustes ), 'secret' => 'clave-secreta' )
		);

		$this->assertSame( 200, $respuesta->get_status() );

		wp_set_current_user( 0 );
	}

	private function valores(): array {
		return array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' );
	}

	public function proveedores(): array {
		return array(
			'turnstile' => array( Turnstile_Provider::class, 'https://challenges.cloudflare.com/turnstile/v0/siteverify' ),
			'hcaptcha'  => array( Hcaptcha_Provider::class, 'https://api.hcaptcha.com/siteverify' ),
			'recaptcha' => array( Recaptcha_Provider::class, 'https://www.google.com/recaptcha/api/siteverify' ),
		);
	}

	/** @dataProvider proveedores */
	public function test_cada_proveedor_consulta_a_su_servicio_y_distingue_rechazo_de_servicio_caido( string $clase, string $direccion ) {
		$proveedor = new $clase( new Siteverify_Client() );
		$ajustes   = new Captcha_Config( $proveedor->get_id(), 'clave-publica' );

		$this->respuesta = array( 'success' => true, 'score' => 0.9, 'action' => Recaptcha_Provider::ACTION );
		$this->assertTrue( $proveedor->verify( 'testigo', 'clave-secreta', '203.0.113.7', $ajustes ) );

		$peticion = $this->peticiones[0];
		$this->assertSame( $direccion, $peticion['url'] );
		$this->assertSame( 'POST', $peticion['argumentos']['method'] );
		$this->assertSame( array( 'secret' => 'clave-secreta', 'response' => 'testigo', 'remoteip' => '203.0.113.7' ), $peticion['argumentos']['body'] );
		$this->assertTrue( $peticion['argumentos']['reject_unsafe_urls'] );

		// Sin IP conocida, no se envía el parámetro.
		$proveedor->verify( 'testigo', 'clave-secreta', '', $ajustes );
		$this->assertArrayNotHasKey( 'remoteip', $this->peticiones[1]['argumentos']['body'] );

		$this->respuesta = array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) );
		$rechazo         = $proveedor->verify( 'testigo', 'clave-secreta', '', $ajustes );
		$this->assertSame( 'formulario_mensaje_captcha_rejected', $rechazo->get_error_code() );
		$this->assertSame( 'invalid-input-response', $rechazo->get_error_message() );

		$this->respuesta = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertSame( Siteverify_Client::ERROR_UNAVAILABLE, $proveedor->verify( 'testigo', 'clave-secreta', '', $ajustes )->get_error_code() );

		// Una respuesta que no es la del servicio (una página de error, por ejemplo) tampoco se da por buena.
		$this->respuesta = array( 'mensaje' => 'Mantenimiento' );
		$this->assertSame( Siteverify_Client::ERROR_UNAVAILABLE, $proveedor->verify( 'testigo', 'clave-secreta', '', $ajustes )->get_error_code() );
	}

	public function test_recaptcha_exige_la_puntuacion_minima_y_la_accion_del_formulario() {
		$recaptcha = new Recaptcha_Provider( new Siteverify_Client() );
		$ajustes   = new Captcha_Config( 'recaptcha', 'clave-publica', 0.7 );

		$this->respuesta = array( 'success' => true, 'score' => 0.7, 'action' => Recaptcha_Provider::ACTION );
		$this->assertTrue( $recaptcha->verify( 't', 's', '', $ajustes ) );

		$this->respuesta = array( 'success' => true, 'score' => 0.6, 'action' => Recaptcha_Provider::ACTION );
		$this->assertSame( 'score 0.6', $recaptcha->verify( 't', 's', '', $ajustes )->get_error_message() );

		// Un testigo válido, pero pedido en otra página del sitio para otra cosa.
		$this->respuesta = array( 'success' => true, 'score' => 0.9, 'action' => 'login' );
		$this->assertSame( 'action-mismatch', $recaptcha->verify( 't', 's', '', $ajustes )->get_error_message() );

		// Sin puntuación en la respuesta no se da por buena.
		$this->respuesta = array( 'success' => true, 'action' => Recaptcha_Provider::ACTION );
		$this->assertWPError( $recaptcha->verify( 't', 's', '', $ajustes ) );
	}

	public function test_un_formulario_con_captcha_exige_un_testigo_valido() {
		$formulario = $this->crear_formulario();
		$this->configurar();

		$sin_testigo = $this->enviar( $formulario, $this->valores() );
		$this->assertSame( 400, $sin_testigo->get_status() );
		$this->assertSame( 'formulario_mensaje_captcha_missing', $sin_testigo->get_data()['code'] );
		$this->assertSame( array(), $this->peticiones, 'Sin testigo no se consulta al servicio.' );

		$this->respuesta = array( 'success' => false, 'error-codes' => array( 'timeout-or-duplicate' ) );
		$rechazado       = $this->enviar( $formulario, $this->valores(), array( 'captcha' => 'testigo-usado' ) );
		$this->assertSame( 400, $rechazado->get_status() );
		$this->assertSame( 'formulario_mensaje_captcha_failed', $rechazado->get_data()['code'] );
		$this->assertStringNotContainsString( 'timeout-or-duplicate', wp_json_encode( $rechazado->get_data() ), 'El visitante no ve el motivo interno.' );

		$this->respuesta = array( 'success' => true );
		$aceptado        = $this->enviar( $formulario, $this->valores(), array( 'captcha' => 'testigo-bueno' ) );
		$this->assertSame( 200, $aceptado->get_status() );
		$this->assertSame( 'testigo-bueno', end( $this->peticiones )['argumentos']['body']['response'] );
		$this->assertSame( 'clave-secreta', end( $this->peticiones )['argumentos']['body']['secret'] );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent, 'Solo el envío aceptado manda la notificación.' );
	}

	public function test_si_el_servicio_no_responde_el_envio_se_rechaza_en_vez_de_aceptarse_sin_comprobar() {
		$formulario = $this->crear_formulario();
		$this->configurar();

		$this->respuesta = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		$respuesta       = $this->enviar( $formulario, $this->valores(), array( 'captcha' => 'testigo' ) );

		$this->assertSame( 503, $respuesta->get_status() );
		$this->assertSame( 'formulario_mensaje_captcha_unavailable', $respuesta->get_data()['code'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_los_intentos_que_el_captcha_rechaza_gastan_el_limite_por_ip() {
		$formulario      = $this->crear_formulario();
		$this->respuesta = array( 'success' => false );
		$this->configurar();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 'formulario_mensaje_captcha_failed', $this->enviar( $formulario, $this->valores(), array( 'captcha' => "intento-$i" ) )->get_data()['code'] );
		}

		// El sexto ya no llega a consultar al servicio.
		$this->assertSame( 429, $this->enviar( $formulario, $this->valores(), array( 'captcha' => 'intento-6' ) )->get_status() );
		$this->assertCount( 5, $this->peticiones );
	}

	public function test_un_formulario_con_captcha_no_admite_el_envio_sin_javascript() {
		$formulario = $this->crear_formulario();
		$this->configurar();

		$comprobacion = new Captcha_Check( $this->servicio() );
		$sin_js       = new Submission_Request( $formulario, array(), '', '', Submission_Channel::Fallback, '203.0.113.7', '', '', 'testigo' );

		$this->assertSame( 'formulario_mensaje_captcha_requires_js', $comprobacion->check( $sin_js, ( new Post_Form_Repository() )->find( $formulario ) )->get_error_code() );
		$this->assertSame( array(), $this->peticiones );
	}

	public function test_un_formulario_puede_renunciar_al_captcha() {
		$formulario = $this->crear_formulario( array( 'settings' => array( 'captcha_enabled' => false ) ) );
		$this->configurar();

		$this->assertSame( 200, $this->enviar( $formulario, $this->valores() )->get_status() );
		$this->assertSame( array(), $this->peticiones );
		$this->assertStringNotContainsString( 'data-fm-captcha', do_shortcode( "[formulario_mensaje id=\"$formulario\"]" ) );
	}

	public function test_el_formulario_solo_lleva_el_captcha_cuando_esta_configurado_del_todo() {
		$formulario = $this->crear_formulario();

		$this->assertStringNotContainsString( 'data-fm-captcha', do_shortcode( "[formulario_mensaje id=\"$formulario\"]" ), 'Sin captcha configurado no hay nada de terceros.' );

		$this->configurar( 'hcaptcha' );
		$html = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertStringContainsString( 'data-fm-captcha="hcaptcha"', $html );
		$this->assertStringContainsString( 'data-fm-captcha-key="clave-publica"', $html );
		$this->assertMatchesRegularExpression( '#data-fm-captcha-module="[^"]+/assets/js/captcha\.js\?ver=[^"]+"#', $html );
		$this->assertStringContainsString( '<div class="fm-captcha" data-fm-captcha-slot></div>', $html );
		$this->assertStringNotContainsString( 'clave-secreta', $html );
		// El script del servicio no va en la página: lo carga el módulo cuando el visitante usa el formulario.
		$this->assertStringNotContainsString( 'hcaptcha.com', $html );

		// A medio configurar (sin clave secreta) no se activa: el formulario no se podría enviar.
		$this->como_administrador();
		$this->rest( 'PUT', '/settings/captcha', array( 'settings' => array( 'provider' => 'hcaptcha', 'site_key' => 'clave-publica' ), 'clear_secret' => true ) );
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'data-fm-captcha', do_shortcode( "[formulario_mensaje id=\"$formulario\"]" ) );
		$this->assertSame( 200, $this->enviar( $formulario, $this->valores() )->get_status() );
	}

	public function test_los_ajustes_del_captcha_exigen_su_capability_y_nunca_devuelven_la_clave_secreta() {
		$gestor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $gestor );
		wp_get_current_user()->add_cap( Capabilities::MANAGE_FORMS );

		$this->assertSame( 403, $this->rest( 'GET', '/settings/captcha' )->get_status() );
		$this->assertSame( 403, $this->rest( 'PUT', '/settings/captcha', array( 'settings' => array( 'provider' => 'turnstile' ) ) )->get_status() );

		$this->como_administrador();

		$inicial = $this->rest( 'GET', '/settings/captcha' )->get_data();
		$this->assertSame( '', $inicial['settings']['provider'] );
		$this->assertSame( array( 'turnstile', 'recaptcha', 'hcaptcha' ), array_column( $inicial['providers'], 'id' ) );
		$this->assertSame( 'none', $inicial['secret']['source'] );

		$guardado = $this->rest( 'PUT', '/settings/captcha', array( 'settings' => array( 'provider' => 'recaptcha', 'site_key' => ' 6Lc_clave-publica ', 'score_threshold' => 0.74 ), 'secret' => 'clave-muy-secreta' ) )->get_data();

		$this->assertSame( array( 'provider' => 'recaptcha', 'site_key' => '6Lc_clave-publica', 'score_threshold' => 0.7 ), $guardado['settings'] );
		$this->assertSame( array( 'source' => 'stored', 'constant' => 'FORMULARIO_MENSAJE_CAPTCHA_SECRET' ), $guardado['secret'] );
		$this->assertStringNotContainsString( 'clave-muy-secreta', wp_json_encode( $guardado ) . wp_json_encode( $this->rest( 'GET', '/settings/captcha' )->get_data() ) );
		$this->assertStringNotContainsString( 'clave-muy-secreta', wp_json_encode( get_option( Captcha_Config_Repository::OPTION ) ) . wp_json_encode( get_option( Secret_Vault::OPTION ) ) );

		// Guardar sin escribir la clave la conserva.
		$this->assertFalse( $this->rest( 'PUT', '/settings/captcha', array( 'settings' => array( 'score_threshold' => 0.3 ) + $guardado['settings'] ) )->get_data()['discarded'] );
		$this->assertSame( 'clave-muy-secreta', ( new Secret_Vault( new Secret_Cipher() ) )->get( Captcha_Config::SECRET ) );

		// Cambiar de servicio sin escribir su clave no le envía la del anterior.
		$cambio = $this->rest( 'PUT', '/settings/captcha', array( 'settings' => array( 'provider' => 'turnstile' ) + $guardado['settings'] ) )->get_data();
		$this->assertTrue( $cambio['discarded'] );
		$this->assertSame( 'none', $cambio['secret']['source'] );
	}

	public function test_los_ajustes_del_captcha_se_sanean() {
		$registro = new Captcha_Provider_Registry();
		$registro->register( new Turnstile_Provider( new Siteverify_Client() ) );

		$saneador = new Captcha_Config_Sanitizer( $registro );

		$ajustes = $saneador->sanitize( array( 'provider' => 'inventado', 'site_key' => '"><script>alert(1)</script>', 'score_threshold' => 7 ) );
		$this->assertSame( '', $ajustes->provider );
		$this->assertSame( '', $ajustes->site_key );
		$this->assertSame( 0.9, $ajustes->score_threshold );

		$ajustes = $saneador->sanitize( array( 'provider' => 'turnstile', 'site_key' => '0x4AAAAAAA_b-C', 'score_threshold' => 'alto' ) );
		$this->assertSame( 'turnstile', $ajustes->provider );
		$this->assertSame( '0x4AAAAAAA_b-C', $ajustes->site_key );
		$this->assertSame( 0.5, $ajustes->score_threshold );

		$this->assertSame( 0.1, $saneador->sanitize( array( 'score_threshold' => 0 ) )->score_threshold );
		$this->assertSame( '', $saneador->sanitize( 'basura' )->provider );
	}

	public function test_un_tercero_puede_registrar_su_proveedor_de_captcha() {
		$registro = new Captcha_Provider_Registry();
		$propio   = new class() implements Formulario_Mensaje\Captcha\Captcha_Provider {
			public function get_id(): string {
				return 'propio';
			}

			public function get_label(): string {
				return 'Captcha propio';
			}

			public function verify( string $token, string $secret, string $remote_ip, Captcha_Config $config ): bool|WP_Error {
				return 'abracadabra' === $token ? true : new WP_Error( 'formulario_mensaje_captcha_rejected', 'no' );
			}
		};

		$registro->register( $propio );
		update_option( Captcha_Config_Repository::OPTION, array( 'provider' => 'propio', 'site_key' => 'k' ) );

		$almacen = new Secret_Vault( new Secret_Cipher() );
		$almacen->set( Captcha_Config::SECRET, 's' );

		$servicio = new Captcha_Service( new Captcha_Config_Repository( new Captcha_Config_Sanitizer( $registro ), $almacen ), $registro );

		$this->assertTrue( $servicio->verify( 'abracadabra', '' ) );
		$this->assertWPError( $servicio->verify( 'otra cosa', '' ) );
	}

	/** Servicio de captcha con los proveedores del plugin, sobre los ajustes guardados. */
	private function servicio(): Captcha_Service {
		$cliente  = new Siteverify_Client();
		$registro = new Captcha_Provider_Registry();

		$registro->register( new Turnstile_Provider( $cliente ) );
		$registro->register( new Recaptcha_Provider( $cliente ) );
		$registro->register( new Hcaptcha_Provider( $cliente ) );

		return new Captcha_Service( new Captcha_Config_Repository( new Captcha_Config_Sanitizer( $registro ), new Secret_Vault( new Secret_Cipher() ) ), $registro );
	}
}
