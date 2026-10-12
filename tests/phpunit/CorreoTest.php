<?php
/**
 * Correo avanzado: condiciones, plantilla HTML, secretos cifrados, transportes y correos de prueba.
 */

use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Mail\Email_Message;
use Formulario_Mensaje\Mail\Email_Template;
use Formulario_Mensaje\Mail\Mail_Settings;
use Formulario_Mensaje\Mail\Mail_Settings_Sanitizer;
use Formulario_Mensaje\Mail\Option_Mail_Settings_Repository;
use Formulario_Mensaje\Mail\Resend_Transport;
use Formulario_Mensaje\Mail\Selected_Transport;
use Formulario_Mensaje\Mail\Smtp_Transport;
use Formulario_Mensaje\Mail\Wp_Mail_Transport;
use Formulario_Mensaje\Rules\Condition_Sanitizer;
use Formulario_Mensaje\Rules\Rule_Evaluator;
use Formulario_Mensaje\Secrets\Secret_Cipher;
use Formulario_Mensaje\Secrets\Secret_Vault;

class CorreoTest extends Caso_Formulario {

	/** Formulario con un desplegable de departamento y una notificación por cada uno. */
	private function formulario_por_departamento(): int {
		return $this->crear_formulario(
			array(
				'fields'  => array(
					array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre', 'required' => true ) ),
					array(
						'id'       => 'dpto',
						'type'     => 'select',
						'settings' => array( 'label' => 'Departamento', 'options' => array( array( 'label' => 'Ventas', 'value' => 'ventas' ), array( 'label' => 'Soporte', 'value' => 'soporte' ) ) ),
					),
				),
				'actions' => array(
					array(
						'id'        => 'a_ventas',
						'type'      => 'email',
						'enabled'   => true,
						'name'      => 'Aviso a ventas',
						'condition' => array( 'match' => 'all', 'rules' => array( array( 'field' => 'dpto', 'operator' => 'equals', 'value' => 'ventas' ) ) ),
						'settings'  => array( 'to' => array( 'ventas@example.org' ), 'subject' => 'Ventas', 'body' => '{all_fields}', 'format' => 'text' ),
					),
					array(
						'id'        => 'a_soporte',
						'type'      => 'email',
						'enabled'   => true,
						'name'      => 'Aviso a soporte',
						'condition' => array( 'match' => 'all', 'rules' => array( array( 'field' => 'dpto', 'operator' => 'equals', 'value' => 'soporte' ) ) ),
						'settings'  => array( 'to' => array( 'soporte@example.org' ), 'subject' => 'Soporte', 'body' => '{all_fields}', 'format' => 'text' ),
					),
				),
			)
		);
	}

	public function operadores(): array {
		return array(
			'igual, sin distinguir mayúsculas ni espacios' => array( 'equals', 'Ventas', ' ventas ', true ),
			'igual, distinto valor'                        => array( 'equals', 'ventas', 'soporte', false ),
			'igual en un campo con varios valores'         => array( 'equals', 'b', array( 'a', 'b' ), true ),
			'distinto'                                     => array( 'not_equals', 'ventas', 'soporte', true ),
			'distinto en varios valores que lo incluyen'   => array( 'not_equals', 'a', array( 'a', 'b' ), false ),
			'contiene'                                     => array( 'contains', 'urgent', 'Es URGENTE de verdad', true ),
			'contiene un texto vacío'                      => array( 'contains', '', 'algo', false ),
			'no contiene'                                  => array( 'not_contains', 'spam', 'mensaje normal', true ),
			'vacío'                                        => array( 'is_empty', '', '', true ),
			'vacío con lista vacía'                        => array( 'is_empty', '', array(), true ),
			'vacío con dato'                               => array( 'is_empty', '', 'x', false ),
			'no vacío'                                     => array( 'is_not_empty', '', 'x', true ),
			'mayor que'                                    => array( 'greater_than', '10', '10,5', true ),
			'mayor que, sin serlo'                         => array( 'greater_than', '10', '9', false ),
			'menor que'                                    => array( 'less_than', '10', '-3', true ),
			'mayor que con texto no numérico'              => array( 'greater_than', '10', 'muchos', false ),
			'operador desconocido'                         => array( 'matches_regex', '.*', 'x', false ),
		);
	}

	/** @dataProvider operadores */
	public function test_el_motor_de_reglas_evalua_cada_operador( string $operador, string $esperado, $valor, bool $resultado ) {
		$condicion = array( 'match' => 'all', 'rules' => array( array( 'field' => 'campo', 'operator' => $operador, 'value' => $esperado ) ) );

		$this->assertSame( $resultado, ( new Rule_Evaluator() )->passes( $condicion, array( 'campo' => $valor ) ) );
	}

	public function test_el_motor_de_reglas_combina_todas_o_alguna_y_una_condicion_vacia_se_cumple() {
		$motor  = new Rule_Evaluator();
		$reglas = array(
			array( 'field' => 'a', 'operator' => 'equals', 'value' => '1' ),
			array( 'field' => 'b', 'operator' => 'equals', 'value' => '2' ),
		);

		$this->assertTrue( $motor->passes( array( 'match' => 'all', 'rules' => $reglas ), array( 'a' => '1', 'b' => '2' ) ) );
		$this->assertFalse( $motor->passes( array( 'match' => 'all', 'rules' => $reglas ), array( 'a' => '1', 'b' => 'x' ) ) );
		$this->assertTrue( $motor->passes( array( 'match' => 'any', 'rules' => $reglas ), array( 'a' => '1', 'b' => 'x' ) ) );
		$this->assertFalse( $motor->passes( array( 'match' => 'any', 'rules' => $reglas ), array( 'a' => 'x', 'b' => 'x' ) ) );
		$this->assertTrue( $motor->passes( array(), array() ) );
		$this->assertTrue( $motor->passes( array( 'match' => 'any', 'rules' => array() ), array( 'a' => '1' ) ) );
		// Un campo que ya no existe en el formulario cuenta como vacío.
		$this->assertTrue( $motor->passes( array( 'rules' => array( array( 'field' => 'borrado', 'operator' => 'is_empty' ) ) ), array() ) );
	}

	public function test_las_condiciones_se_sanean_y_las_reglas_no_validas_se_descartan() {
		$limpia = ( new Condition_Sanitizer() )->sanitize(
			array(
				'match' => 'cualquiera',
				'rules' => array(
					array( 'field' => 'dpto', 'operator' => 'equals', 'value' => ' <b>ventas</b> ' ),
					array( 'field' => 'dpto', 'operator' => 'ejecutar', 'value' => 'x' ),
					array( 'field' => 'DROP TABLE', 'operator' => 'equals', 'value' => 'x' ),
					array( 'field' => 'dpto', 'operator' => 'is_empty', 'value' => 'sobra' ),
					'basura',
				),
			)
		);

		$this->assertSame( 'all', $limpia['match'] );
		$this->assertSame(
			array(
				array( 'field' => 'dpto', 'operator' => 'equals', 'value' => 'ventas' ),
				array( 'field' => 'dpto', 'operator' => 'is_empty', 'value' => '' ),
			),
			$limpia['rules']
		);
	}

	public function test_cada_notificacion_se_envia_solo_si_el_envio_cumple_su_condicion() {
		$formulario = $this->formulario_por_departamento();

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'dpto' => 'soporte' ) );
		$mailer = tests_retrieve_phpmailer_instance();

		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertSame( 'soporte@example.org', $mailer->get_sent()->to[0][0] );

		// Solo se anota la notificación que se ejecutó: la otra no ha fallado, no le tocaba.
		$this->como_administrador();
		$envio = $this->rest( 'GET', '/submissions' )->get_data()[0]['id'];

		$this->assertSame( array( 'a_soporte' ), array_map( static fn ( $entrega ) => $entrega['action_label'] ? 'a_soporte' : '', $this->rest( 'GET', "/submissions/$envio" )->get_data()['deliveries'] ) );
	}

	public function test_un_envio_que_no_cumple_ninguna_condicion_se_guarda_sin_notificar() {
		$formulario = $this->formulario_por_departamento();

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'dpto' => '' ) );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_el_nombre_y_la_condicion_de_cada_accion_se_guardan_con_el_formulario() {
		$formulario = $this->formulario_por_departamento();

		$this->como_administrador();
		$acciones = $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['actions'];

		$this->assertSame( array( 'Aviso a ventas', 'Aviso a soporte' ), array_column( $acciones, 'name' ) );
		$this->assertSame( 'ventas', $acciones[0]['condition']['rules'][0]['value'] );
		$this->assertSame( 'text', $acciones[0]['settings']['format'] );
	}

	public function test_un_secreto_cifrado_solo_se_recupera_con_las_mismas_salts_y_sin_manipular() {
		$cifrado  = new Secret_Cipher();
		$guardado = $cifrado->encrypt( 'contraseña-súper-secreta' );

		$this->assertStringStartsWith( 'fm1:', $guardado );
		$this->assertStringNotContainsString( 'secreta', $guardado );
		$this->assertNotSame( $guardado, $cifrado->encrypt( 'contraseña-súper-secreta' ), 'Cada cifrado usa un nonce distinto.' );
		$this->assertSame( 'contraseña-súper-secreta', $cifrado->decrypt( $guardado ) );

		// Un valor manipulado no se descifra: el cifrado es autenticado.
		$manipulado = substr( $guardado, 0, -2 ) . ( str_ends_with( $guardado, '00' ) ? '11' : '00' );
		$this->assertNull( $cifrado->decrypt( $manipulado ) );
		$this->assertNull( $cifrado->decrypt( 'texto en claro' ) );
		$this->assertNull( $cifrado->decrypt( 'fm1:zz' ) );

		// Con otras salts (otra instalación, o una copia de la base de datos) tampoco.
		$otras_salts = static fn (): string => 'otra-sal-completamente-distinta';
		add_filter( 'salt', $otras_salts );
		$this->assertNull( $cifrado->decrypt( $guardado ) );
		remove_filter( 'salt', $otras_salts );
	}

	public function test_el_almacen_de_secretos_guarda_cifrado_y_una_constante_tiene_prioridad() {
		$almacen = new Secret_Vault( new Secret_Cipher() );

		$this->assertSame( Secret_Vault::SOURCE_NONE, $almacen->source( 'clave_prueba' ) );
		$this->assertSame( '', $almacen->get( 'clave_prueba' ) );

		$almacen->set( 'clave_prueba', 're_123456' );

		$this->assertSame( 're_123456', $almacen->get( 'clave_prueba' ) );
		$this->assertSame( Secret_Vault::SOURCE_STORED, $almacen->source( 'clave_prueba' ) );
		$this->assertStringNotContainsString( 're_123456', wp_json_encode( get_option( Secret_Vault::OPTION ) ), 'En la base de datos solo está el valor cifrado.' );

		$almacen->set( 'clave_prueba', '' );
		$this->assertSame( Secret_Vault::SOURCE_NONE, $almacen->source( 'clave_prueba' ) );

		define( 'FORMULARIO_MENSAJE_CLAVE_FIJA', 'desde-wp-config' );
		$almacen->set( 'clave_fija', 'desde-la-base-de-datos' );

		$this->assertSame( 'FORMULARIO_MENSAJE_CLAVE_FIJA', $almacen->constant_name( 'clave_fija' ) );
		$this->assertSame( 'desde-wp-config', $almacen->get( 'clave_fija' ) );
		$this->assertSame( Secret_Vault::SOURCE_CONSTANT, $almacen->source( 'clave_fija' ) );
	}

	public function test_los_ajustes_de_correo_exigen_su_capability_y_nunca_devuelven_las_credenciales() {
		$gestor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $gestor );
		wp_get_current_user()->add_cap( Capabilities::MANAGE_FORMS );

		$this->assertSame( 403, $this->rest( 'GET', '/settings/mail' )->get_status() );
		$this->assertSame( 403, $this->rest( 'PUT', '/settings/mail', array( 'settings' => array( 'transport' => 'smtp' ) ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/settings/mail/test', array( 'to' => 'yo@example.org' ) )->get_status() );

		$this->como_administrador();

		$respuesta = $this->rest(
			'PUT',
			'/settings/mail',
			array(
				'settings' => array( 'transport' => 'smtp', 'smtp_host' => 'smtp.example.org', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'smtp_username' => 'usuario', 'from_email' => 'web@example.org' ),
				'secrets'  => array( 'smtp_password' => 'p4ssw0rd-secreta', 'resend_api_key' => '', 'otro' => 'se ignora' ),
			)
		);
		$datos     = $respuesta->get_data();

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertSame( 'smtp', $datos['settings']['transport'] );
		$this->assertSame( 465, $datos['settings']['smtp_port'] );
		$this->assertSame( array( 'source' => 'stored', 'constant' => 'FORMULARIO_MENSAJE_SMTP_PASSWORD' ), $datos['secrets']['smtp_password'] );
		$this->assertSame( 'none', $datos['secrets']['resend_api_key']['source'] );
		$this->assertStringNotContainsString( 'p4ssw0rd', wp_json_encode( $datos ) );
		$this->assertStringNotContainsString( 'p4ssw0rd', wp_json_encode( $this->rest( 'GET', '/settings/mail' )->get_data() ) );
		$this->assertStringNotContainsString( 'p4ssw0rd', wp_json_encode( get_option( Option_Mail_Settings_Repository::OPTION ) ) . wp_json_encode( get_option( Secret_Vault::OPTION ) ) );

		// Guardar de nuevo sin escribir la contraseña la conserva; pedir su borrado la quita.
		$this->rest( 'PUT', '/settings/mail', array( 'settings' => $datos['settings'], 'secrets' => array( 'smtp_password' => '' ) ) );
		$this->assertSame( 'p4ssw0rd-secreta', ( new Secret_Vault( new Secret_Cipher() ) )->get( 'smtp_password' ) );

		$borrado = $this->rest( 'PUT', '/settings/mail', array( 'settings' => $datos['settings'], 'clear' => array( 'smtp_password' ) ) )->get_data();
		$this->assertSame( 'none', $borrado['secrets']['smtp_password']['source'] );
	}

	public function test_al_cambiar_de_servidor_smtp_la_contrasena_guardada_se_descarta() {
		$this->como_administrador();

		$ajustes = array( 'transport' => 'smtp', 'smtp_host' => 'smtp.example.org' );
		$almacen = new Secret_Vault( new Secret_Cipher() );

		$this->rest( 'PUT', '/settings/mail', array( 'settings' => $ajustes, 'secrets' => array( 'smtp_password' => 'secreta' ) ) );

		// Guardar otra cosa sin tocar el servidor la conserva, aunque cambien las mayúsculas.
		$respuesta = $this->rest( 'PUT', '/settings/mail', array( 'settings' => array( 'smtp_host' => 'SMTP.Example.org', 'smtp_port' => 465 ) + $ajustes ) )->get_data();
		$this->assertSame( array(), $respuesta['discarded'] );
		$this->assertSame( 'secreta', $almacen->get( 'smtp_password' ) );

		// Apuntar a otro servidor sin escribir su contraseña no le entrega la del anterior.
		$respuesta = $this->rest( 'PUT', '/settings/mail', array( 'settings' => array( 'smtp_host' => 'smtp.atacante.example' ) + $ajustes ) )->get_data();
		$this->assertSame( array( 'smtp_password' ), $respuesta['discarded'] );
		$this->assertSame( 'none', $respuesta['secrets']['smtp_password']['source'] );
		$this->assertSame( '', $almacen->get( 'smtp_password' ) );

		// Con la contraseña del servidor nuevo en la misma petición, se guarda esa.
		$respuesta = $this->rest( 'PUT', '/settings/mail', array( 'settings' => array( 'smtp_host' => 'smtp.nuevo.example' ) + $ajustes, 'secrets' => array( 'smtp_password' => 'otra' ) ) )->get_data();
		$this->assertSame( array(), $respuesta['discarded'] );
		$this->assertSame( 'otra', $almacen->get( 'smtp_password' ) );
	}

	public function test_las_pruebas_de_correo_tienen_un_limite_por_usuario() {
		$this->como_administrador();

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 200, $this->rest( 'POST', '/settings/mail/test', array( 'to' => 'yo@example.org' ) )->get_status() );
		}

		$this->assertSame( 429, $this->rest( 'POST', '/settings/mail/test', array( 'to' => 'yo@example.org' ) )->get_status() );
		$this->assertSame( 429, $this->rest( 'POST', '/forms/test-email', array( 'definition' => $this->definicion(), 'action_id' => 'aviso', 'to' => 'yo@example.org' ) )->get_status(), 'El límite es común a las dos pruebas.' );
		$this->assertCount( 10, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_los_ajustes_de_correo_se_sanean() {
		$saneador = new Mail_Settings_Sanitizer( array( 'wp_mail', 'smtp', 'resend' ) );
		$ajustes  = $saneador->sanitize(
			array(
				'transport'       => 'sendmail',
				'from_email'      => 'no-es-correo',
				'from_name'       => "Mi \"Tienda\"\r\nBcc: victima@example.org <x>",
				'smtp_host'       => 'smtp.example.org:25/ruta',
				'smtp_port'       => 99999,
				'smtp_encryption' => 'ninguno',
				'brand_logo_url'  => 'javascript:alert(1)',
				'brand_color'     => 'rojo',
				'brand_footer'    => "Línea 1\n<script>alert(1)</script>Línea 2",
			)
		);

		$this->assertSame( 'wp_mail', $ajustes->transport );
		$this->assertSame( '', $ajustes->from_email );
		$this->assertStringNotContainsString( "\n", $ajustes->from_name );
		$this->assertStringNotContainsString( '"', $ajustes->from_name );
		$this->assertStringNotContainsString( '<', $ajustes->from_name );
		$this->assertSame( '', $ajustes->smtp_host, 'Un servidor con puerto o ruta no es un nombre de servidor.' );
		$this->assertSame( 587, $ajustes->smtp_port );
		$this->assertSame( 'tls', $ajustes->smtp_encryption );
		$this->assertSame( '', $ajustes->brand_logo_url );
		$this->assertSame( Mail_Settings::DEFAULT_BRAND_COLOR, $ajustes->brand_color );
		$this->assertStringNotContainsString( '<script>', $ajustes->brand_footer );

		$this->assertSame( '192.0.2.10', $saneador->sanitize( array( 'smtp_host' => '192.0.2.10' ) )->smtp_host );
		$this->assertSame( 'mail.example.org', $saneador->sanitize( array( 'smtp_host' => ' Mail.Example.org ' ) )->smtp_host );
	}

	public function test_la_notificacion_en_html_lleva_la_marca_y_escapa_los_datos_del_visitante() {
		update_option(
			Option_Mail_Settings_Repository::OPTION,
			array( 'brand_color' => '#ff6600', 'brand_logo_url' => 'https://example.org/logo.png', 'brand_footer' => "Mi Tienda S.L.\nCalle Mayor 1 & 2" )
		);

		$formulario = $this->crear_formulario(
			array(
				'actions' => array(
					array( 'id' => 'aviso', 'type' => 'email', 'enabled' => true, 'settings' => array( 'to' => array( 'ventas@example.org' ), 'subject' => 'Nuevo de {field:nombre}', 'body' => "Hola equipo & cía:\n{all_fields}\nRef {reference}", 'format' => 'html' ) ),
				),
			)
		);

		$this->enviar( $formulario, array( 'nombre' => 'Ana & "Cía"', 'correo' => 'ana@example.org', 'mensaje' => "Línea 1\nLínea 2" ) );
		$correo = tests_retrieve_phpmailer_instance()->get_sent();
		$cuerpo = $correo->body;

		$this->assertStringContainsString( 'Content-Type: multipart/alternative', $correo->header );
		// Parte en texto: los datos tal cual.
		$this->assertStringContainsString( 'Nombre: Ana & "Cía"', $cuerpo );
		// Parte en HTML: marca del sitio, datos escapados y saltos de línea conservados.
		$this->assertStringContainsString( 'border-top:4px solid #ff6600', $cuerpo );
		$this->assertStringContainsString( '<img src="https://example.org/logo.png"', $cuerpo );
		$this->assertStringContainsString( 'Calle Mayor 1 &amp; 2', $cuerpo );
		$this->assertStringContainsString( 'Ana &amp; &quot;Cía&quot;', $cuerpo );
		$this->assertStringContainsString( 'Hola equipo &amp; cía:', $cuerpo, 'El texto de quien configura también se escapa en el correo.' );
		$this->assertMatchesRegularExpression( '#Línea 1<br ?/?>\s*Línea 2#', $cuerpo );
		$this->assertStringContainsString( '<table role="presentation" width="100%"', $cuerpo );
	}

	public function test_una_notificacion_en_texto_no_lleva_html_y_el_remitente_sale_de_los_ajustes() {
		update_option( Option_Mail_Settings_Repository::OPTION, array( 'from_email' => 'web@example.org', 'from_name' => 'Mi Tienda' ) );

		$formulario = $this->crear_formulario(
			array( 'actions' => array( array( 'id' => 'aviso', 'type' => 'email', 'enabled' => true, 'settings' => array( 'to' => array( 'ventas@example.org' ), 'subject' => 's', 'body' => '{all_fields}', 'format' => 'text' ) ) ) )
		);

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );
		$correo = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertStringContainsString( 'Content-Type: text/plain', $correo->header );
		$this->assertStringNotContainsString( '<table', $correo->body );
		$this->assertStringContainsString( 'From: Mi Tienda <web@example.org>', $correo->header );
	}

	public function test_la_plantilla_escapa_el_titulo_y_muestra_el_nombre_del_sitio_si_no_hay_logotipo() {
		update_option( 'blogname', 'Tienda <Ana> & Hijos' );

		$html = ( new Email_Template( new Ajustes_Correo_En_Memoria() ) )->render( '<p>Contenido</p>', 'Asunto <script>alert(1)</script>' );

		$this->assertStringContainsString( '<title>Asunto &lt;script&gt;alert(1)&lt;/script&gt;</title>', $html );
		$this->assertStringContainsString( 'Tienda &lt;Ana&gt; &amp; Hijos', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '<p>Contenido</p>', $html );
	}

	public function test_el_transporte_elegido_en_los_ajustes_recibe_el_correo() {
		$ajustes = new Ajustes_Correo_En_Memoria();
		$uno     = new Transporte_Espia();
		$dos     = new Transporte_Espia();
		$elegido = new Selected_Transport( $ajustes, array( 'wp_mail' => $uno, 'smtp' => $dos ) );
		$mensaje = new Email_Message( array( 'a@example.org' ), 'Asunto', 'Cuerpo' );

		$this->assertTrue( $elegido->send( $mensaje ) );
		$this->assertCount( 1, $uno->enviados );

		$ajustes->save( new Mail_Settings( transport: 'smtp' ) );
		$elegido->send( $mensaje );
		$this->assertCount( 1, $dos->enviados );

		$ajustes->save( new Mail_Settings( transport: 'de_otro_plugin_desactivado' ) );
		$this->assertSame( 'formulario_mensaje_transport_missing', $elegido->send( $mensaje )->get_error_code() );
	}

	public function test_resend_recibe_el_correo_por_su_api_con_la_clave_en_la_cabecera() {
		$almacen = new Secret_Vault( new Secret_Cipher() );
		$almacen->set( Mail_Settings::SECRET_RESEND_API_KEY, 're_clave_secreta' );

		$peticion = null;
		$simular  = static function ( $respuesta, array $argumentos, string $url ) use ( &$peticion ) {
			$peticion = array( 'url' => $url, 'argumentos' => $argumentos );

			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '{"id":"49a3999c"}', 'headers' => array(), 'cookies' => array() );
		};

		add_filter( 'pre_http_request', $simular, 10, 3 );

		$resend    = new Resend_Transport( new Ajustes_Correo_En_Memoria( new Mail_Settings( transport: 'resend', from_email: 'web@example.org', from_name: 'Mi Tienda' ) ), $almacen );
		$resultado = $resend->send( new Email_Message( array( 'ventas@example.org', 'no-valido' ), "Asunto\r\nBcc: x@example.org", 'Texto', 'ana@example.org', '<p>HTML</p>' ) );

		remove_filter( 'pre_http_request', $simular );

		$cuerpo = json_decode( $peticion['argumentos']['body'], true );

		$this->assertTrue( $resultado );
		$this->assertSame( 'https://api.resend.com/emails', $peticion['url'] );
		$this->assertSame( 'Bearer re_clave_secreta', $peticion['argumentos']['headers']['Authorization'] );
		$this->assertTrue( $peticion['argumentos']['reject_unsafe_urls'], 'Se usa la variante segura de la HTTP API.' );
		$this->assertSame( 0, $peticion['argumentos']['redirection'] );
		$this->assertSame( 'Mi Tienda <web@example.org>', $cuerpo['from'] );
		$this->assertSame( array( 'ventas@example.org' ), $cuerpo['to'] );
		$this->assertSame( 'Asunto Bcc: x@example.org', $cuerpo['subject'] );
		$this->assertSame( 'Texto', $cuerpo['text'] );
		$this->assertSame( '<p>HTML</p>', $cuerpo['html'] );
		$this->assertSame( 'ana@example.org', $cuerpo['reply_to'] );
	}

	public function test_un_error_de_resend_se_devuelve_sin_la_clave_ni_el_contenido_del_correo() {
		$almacen = new Secret_Vault( new Secret_Cipher() );
		$resend  = new Resend_Transport( new Ajustes_Correo_En_Memoria( new Mail_Settings( transport: 'resend', from_email: 'web@example.org' ) ), $almacen );
		$mensaje = new Email_Message( array( 'ventas@example.org' ), 'Asunto', 'Dato personal del visitante' );

		$this->assertSame( 'formulario_mensaje_resend_not_configured', $resend->send( $mensaje )->get_error_code(), 'Sin clave no se intenta.' );

		$almacen->set( Mail_Settings::SECRET_RESEND_API_KEY, 're_clave_secreta' );

		$rechazar = static fn () => array( 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'body' => '{"statusCode":403,"name":"validation_error","message":"The example.org domain is not verified."}', 'headers' => array(), 'cookies' => array() );

		add_filter( 'pre_http_request', $rechazar );
		$error = $resend->send( $mensaje );
		remove_filter( 'pre_http_request', $rechazar );

		$this->assertSame( 'formulario_mensaje_resend_failed', $error->get_error_code() );
		$this->assertStringContainsString( '403', $error->get_error_message() );
		$this->assertStringContainsString( 'domain is not verified', $error->get_error_message() );
		$this->assertStringNotContainsString( 're_clave_secreta', $error->get_error_message() );
		$this->assertStringNotContainsString( 'Dato personal', $error->get_error_message() );

		$sin_red = static fn () => new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' );

		add_filter( 'pre_http_request', $sin_red );
		$this->assertStringContainsString( 'timed out', $resend->send( $mensaje )->get_error_message() );
		remove_filter( 'pre_http_request', $sin_red );
	}

	public function test_smtp_sin_servidor_configurado_no_intenta_conectar() {
		$smtp = new Smtp_Transport( new Ajustes_Correo_En_Memoria( new Mail_Settings( transport: 'smtp' ) ), new Secret_Vault( new Secret_Cipher() ) );

		$this->assertSame( 'formulario_mensaje_smtp_not_configured', $smtp->send( new Email_Message( array( 'a@example.org' ), 's', 'b' ) )->get_error_code() );
	}

	public function test_smtp_pide_un_remitente_si_el_del_sitio_no_es_una_direccion_valida() {
		$smtp    = new Smtp_Transport( new Ajustes_Correo_En_Memoria( new Mail_Settings( transport: 'smtp', smtp_host: 'smtp.example.org' ) ), new Secret_Vault( new Secret_Cipher() ) );
		$sin_dom = static fn (): string => 'wordpress@localhost';

		// Un sitio en «localhost» no tiene un remitente por defecto que un servidor SMTP acepte.
		add_filter( 'wp_mail_from', $sin_dom );
		$error = $smtp->send( new Email_Message( array( 'a@example.org' ), 's', 'b' ) );
		remove_filter( 'wp_mail_from', $sin_dom );

		$this->assertSame( 'formulario_mensaje_smtp_no_sender', $error->get_error_code() );
	}

	public function test_wp_mail_usa_el_nombre_del_remitente_aunque_no_haya_direccion_propia() {
		$transporte = new Wp_Mail_Transport( new Ajustes_Correo_En_Memoria( new Mail_Settings( from_name: 'Mi Tienda' ) ) );

		$transporte->send( new Email_Message( array( 'a@example.org' ), 'Asunto', 'Cuerpo' ) );
		$cabeceras = tests_retrieve_phpmailer_instance()->get_sent()->header;

		// Sin dirección propia va la del sitio, pero con el nombre de los ajustes y no «WordPress».
		$this->assertMatchesRegularExpression( '/^From: Mi Tienda <[^>]+>/m', $cabeceras );
		$this->assertFalse( has_filter( 'wp_mail_from_name' ), 'El filtro solo dura lo que el envío: el resto del correo del sitio no cambia.' );

		// Sin nombre en los ajustes, el plugin no toca el remitente del sitio.
		reset_phpmailer_instance();
		( new Wp_Mail_Transport( new Ajustes_Correo_En_Memoria() ) )->send( new Email_Message( array( 'a@example.org' ), 'Asunto', 'Cuerpo' ) );

		$this->assertMatchesRegularExpression( '/^From: WordPress </m', tests_retrieve_phpmailer_instance()->get_sent()->header );
	}

	public function test_wp_mail_no_admite_una_direccion_de_respuesta_que_no_sea_un_correo() {
		$transporte = new Wp_Mail_Transport( new Ajustes_Correo_En_Memoria() );

		$transporte->send( new Email_Message( array( 'a@example.org' ), 'Asunto', 'Cuerpo', "ana@example.org\r\nBcc: victima@example.org" ) );
		$cabeceras = tests_retrieve_phpmailer_instance()->get_sent()->header;

		$this->assertStringNotContainsString( 'Reply-To', $cabeceras );
		$this->assertDoesNotMatchRegularExpression( '/^Bcc:/mi', $cabeceras );
	}

	public function test_la_prueba_de_una_notificacion_va_solo_a_la_direccion_indicada_con_datos_de_ejemplo() {
		$this->como_administrador();

		$definicion = $this->definicion();
		$definicion['actions'][0]['settings']['format'] = 'text';

		$respuesta = $this->rest( 'POST', '/forms/test-email', array( 'title' => 'En edición', 'definition' => $definicion, 'action_id' => 'aviso', 'to' => 'yo@example.org' ) );
		$mailer    = tests_retrieve_phpmailer_instance();
		$correo    = $mailer->get_sent();

		$this->assertSame( 200, $respuesta->get_status(), wp_json_encode( $respuesta->get_data() ) );
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertSame( array( 'yo@example.org' ), array_column( $correo->to, 0 ), 'Los destinatarios reales no reciben la prueba.' );
		$this->assertSame( '[Test] Nuevo envío: En edición', $correo->subject );
		$this->assertStringContainsString( 'Correo: name@example.com', $correo->body );
		$this->assertStringContainsString( 'Nombre: Sample text', $correo->body );

		$this->assertSame( 404, $this->rest( 'POST', '/forms/test-email', array( 'definition' => $definicion, 'action_id' => 'no_existe', 'to' => 'yo@example.org' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/forms/test-email', array( 'definition' => $definicion, 'action_id' => 'aviso', 'to' => 'no-es-correo' ) )->get_status() );

		$this->como( 'editor' );
		$this->assertSame( 403, $this->rest( 'POST', '/forms/test-email', array( 'definition' => $definicion, 'action_id' => 'aviso', 'to' => 'yo@example.org' ) )->get_status() );
	}

	public function test_el_correo_de_prueba_de_los_ajustes_informa_del_error_del_transporte() {
		$this->como_administrador();

		$this->assertTrue( $this->rest( 'POST', '/settings/mail/test', array( 'to' => 'yo@example.org' ) )->get_data()['sent'] );
		$this->assertSame( array( 'yo@example.org' ), array_column( tests_retrieve_phpmailer_instance()->get_sent()->to, 0 ) );

		// Con Resend elegido y sin clave, la prueba dice qué falta.
		$this->rest( 'PUT', '/settings/mail', array( 'settings' => array( 'transport' => 'resend' ) ) );
		$fallo = $this->rest( 'POST', '/settings/mail/test', array( 'to' => 'yo@example.org' ) );

		$this->assertSame( 502, $fallo->get_status() );
		$this->assertSame( 'formulario_mensaje_resend_not_configured', $fallo->get_data()['code'] );
	}
}
