<?php
/**
 * Gestión de formularios por REST: permisos, alta, edición, papelera y saneado de la definición.
 */

use Formulario_Mensaje\Forms\Form_Post_Type;
use Formulario_Mensaje\Forms\Post_Form_Repository;

class FormulariosRestTest extends Caso_Formulario {

	public function test_un_visitante_anonimo_no_puede_usar_ninguna_ruta_de_gestion() {
		$formulario = $this->crear_formulario();

		$this->assertSame( 401, $this->rest( 'GET', '/forms' )->get_status() );
		$this->assertSame( 401, $this->rest( 'GET', "/forms/$formulario" )->get_status() );
		$this->assertSame( 401, $this->rest( 'POST', '/forms', array( 'title' => 'x' ) )->get_status() );
		$this->assertSame( 401, $this->rest( 'PUT', "/forms/$formulario", array( 'title' => 'x' ) )->get_status() );
		$this->assertSame( 401, $this->rest( 'DELETE', "/forms/$formulario" )->get_status() );
		$this->assertSame( 401, $this->rest( 'POST', "/forms/$formulario/restore" )->get_status() );
		$this->assertSame( 401, $this->rest( 'POST', '/forms/preview', array( 'definition' => array() ) )->get_status() );
		$this->assertSame( 401, $this->rest( 'GET', '/settings' )->get_status() );
	}

	public function test_un_usuario_sin_la_capability_no_puede_gestionar_formularios() {
		$formulario = $this->crear_formulario();
		$this->como( 'editor' );

		$this->assertSame( 403, $this->rest( 'GET', "/forms/$formulario" )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/forms', array( 'title' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'PUT', "/forms/$formulario", array( 'title' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'DELETE', "/forms/$formulario" )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/forms/preview', array( 'definition' => array() ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/settings' )->get_status() );
		$this->assertSame( 'Contacto', get_post( $formulario )->post_title, 'Nada ha cambiado.' );
	}

	public function test_quien_edita_contenidos_solo_ve_nombre_e_identificador_de_los_publicados() {
		$publicado = $this->crear_formulario();
		$borrado   = $this->crear_formulario( array(), 'Antiguo' );
		wp_trash_post( $borrado );

		$this->como( 'editor' );
		$respuesta = $this->rest( 'GET', '/forms', array( 'status' => 'trash' ) );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertSame( array( array( 'id' => $publicado, 'title' => 'Contacto' ) ), $respuesta->get_data(), 'Ni papelera ni destinatarios.' );

		$this->como( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', '/forms' )->get_status() );
	}

	public function test_crear_un_formulario_guarda_la_definicion_saneada() {
		$this->como_administrador();

		$respuesta = $this->rest(
			'POST',
			'/forms',
			array(
				'title'      => '  Presupuesto <script>alert(1)</script> ',
				'definition' => $this->definicion(
					array(
						'fields' => array(
							array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => '<b>Nombre</b>', 'required' => 1, 'inventada' => 'x' ) ),
							array( 'id' => 'nombre', 'type' => 'email', 'settings' => array( 'label' => 'Correo' ) ),
							array( 'id' => 'DROP TABLE', 'type' => 'textarea', 'settings' => array() ),
							array( 'id' => 'raro', 'type' => 'tipo_inexistente', 'settings' => array() ),
							'basura',
						),
					)
				),
			)
		);

		$datos  = $respuesta->get_data();
		$campos = $datos['definition']['fields'];

		$this->assertSame( 201, $respuesta->get_status() );
		$this->assertSame( 'Presupuesto', $datos['title'] );
		$this->assertCount( 3, $campos, 'Se descartan el tipo no registrado y lo que no es un campo.' );
		$this->assertSame( 'Nombre', $campos[0]['settings']['label'] );
		$this->assertTrue( $campos[0]['settings']['required'] );
		$this->assertArrayNotHasKey( 'inventada', $campos[0]['settings'] );
		$this->assertSame( 'nombre', $campos[0]['id'] );
		$this->assertNotSame( 'nombre', $campos[1]['id'], 'Un identificador repetido se sustituye.' );
		$this->assertMatchesRegularExpression( '/^f_[a-z0-9]{8}$/', $campos[2]['id'], 'Un identificador no válido se sustituye.' );
		$this->assertSame( sprintf( '[formulario_mensaje id="%d"]', $datos['id'] ), $datos['shortcode'] );

		$guardado = json_decode( get_post_meta( $datos['id'], Post_Form_Repository::DEFINITION_META, true ), true );

		$this->assertSame( $datos['definition'], $guardado );
		$this->assertSame( Form_Post_Type::NAME, get_post_type( $datos['id'] ) );
	}

	public function test_la_definicion_conserva_barras_comillas_y_acentos() {
		$this->como_administrador();

		$texto     = 'Ruta C:\\datos\\"año" — «camión» \' fin';
		$respuesta = $this->rest(
			'POST',
			'/forms',
			array(
				'title'      => 'Barras',
				'definition' => $this->definicion( array( 'fields' => array( array( 'id' => 'a', 'type' => 'text', 'settings' => array( 'label' => $texto ) ) ) ) ),
			)
		);

		$releido = $this->rest( 'GET', '/forms/' . $respuesta->get_data()['id'] )->get_data();

		$this->assertSame( $texto, $releido['definition']['fields'][0]['settings']['label'] );
	}

	public function test_las_notificaciones_solo_guardan_destinatarios_validos() {
		$formulario = $this->crear_formulario(
			array(
				'actions' => array(
					array(
						'id'       => 'aviso',
						'type'     => 'email',
						'enabled'  => true,
						'settings' => array( 'to' => "ventas@example.org, no-es-correo\nVENTAS@example.org; jefe@example.org", 'subject' => "Asunto\r\nBcc: x@example.org", 'body' => 'Hola', 'reply_to_field' => '../../etc' ),
					),
					array( 'id' => 'otra', 'type' => 'tipo_inexistente', 'enabled' => true, 'settings' => array() ),
				),
			)
		);

		$this->como_administrador();
		$acciones = $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['actions'];

		$this->assertCount( 1, $acciones );
		$this->assertSame( array( 'ventas@example.org', 'jefe@example.org' ), $acciones[0]['settings']['to'] );
		$this->assertStringNotContainsString( "\n", $acciones[0]['settings']['subject'] );
		$this->assertSame( '', $acciones[0]['settings']['reply_to_field'] );
	}

	public function test_editar_un_formulario_sustituye_su_definicion() {
		$formulario = $this->crear_formulario();
		$this->como_administrador();

		$respuesta = $this->rest(
			'PUT',
			"/forms/$formulario",
			array(
				'title'      => 'Contacto comercial',
				'definition' => $this->definicion( array( 'fields' => array( array( 'id' => 'telefono', 'type' => 'tel', 'settings' => array( 'label' => 'Teléfono' ) ) ) ) ),
			)
		);

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertSame( 'Contacto comercial', $respuesta->get_data()['title'] );
		$this->assertSame( array( 'telefono' ), array_column( $respuesta->get_data()['definition']['fields'], 'id' ) );
		$this->assertSame( 404, $this->rest( 'PUT', '/forms/999999', array( 'title' => 'x' ) )->get_status() );
	}

	public function test_el_identificador_debe_ser_de_un_formulario_y_no_de_otro_contenido() {
		$this->como_administrador();
		$entrada = self::factory()->post->create( array( 'post_title' => 'Entrada privada', 'post_status' => 'private' ) );

		$this->assertSame( 404, $this->rest( 'GET', "/forms/$entrada" )->get_status() );
		$this->assertSame( 404, $this->rest( 'PUT', "/forms/$entrada", array( 'title' => 'Robada' ) )->get_status() );
		$this->assertSame( 404, $this->rest( 'DELETE', "/forms/$entrada", array( 'force' => true ) )->get_status() );
		$this->assertSame( 'Entrada privada', get_post( $entrada )->post_title );
	}

	public function test_la_papelera_conserva_el_formulario_y_se_puede_recuperar_o_vaciar() {
		$formulario = $this->crear_formulario();
		$this->como_administrador();

		$this->assertTrue( $this->rest( 'DELETE', "/forms/$formulario" )->get_data()['trashed'] );
		$this->assertSame( 'trash', get_post_status( $formulario ) );
		$this->assertSame( array(), $this->rest( 'GET', '/forms' )->get_data() );
		$this->assertSame( array( $formulario ), array_column( $this->rest( 'GET', '/forms', array( 'status' => 'trash' ) )->get_data(), 'id' ) );

		$this->assertSame( 200, $this->rest( 'POST', "/forms/$formulario/restore" )->get_status() );
		$this->assertSame( 'publish', get_post_status( $formulario ) );
		$this->assertCount( 3, $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['fields'], 'La definición sobrevive a la papelera.' );

		$this->assertTrue( $this->rest( 'DELETE', "/forms/$formulario", array( 'force' => true ) )->get_data()['deleted'] );
		$this->assertNull( get_post( $formulario ) );
	}

	public function test_una_redireccion_a_un_dominio_no_permitido_no_se_puede_guardar() {
		$this->como_administrador();

		$externa = $this->rest( 'POST', '/forms', array( 'title' => 'x', 'definition' => $this->definicion( array( 'settings' => array( 'redirect_enabled' => true, 'redirect_url' => 'https://malo.example/gracias' ) ) ) ) );

		$this->assertSame( 400, $externa->get_status() );
		$this->assertSame( 'formulario_mensaje_redirect_not_allowed', $externa->get_data()['code'] );

		$propia = $this->rest( 'POST', '/forms', array( 'title' => 'x', 'definition' => $this->definicion( array( 'settings' => array( 'redirect_enabled' => true, 'redirect_url' => '/gracias/' ) ) ) ) );

		$this->assertSame( 201, $propia->get_status() );

		$this->rest( 'PUT', '/settings', array( 'allowed_redirect_hosts' => array( 'malo.example' ) ) );

		$permitida = $this->rest( 'POST', '/forms', array( 'title' => 'x', 'definition' => $this->definicion( array( 'settings' => array( 'redirect_enabled' => true, 'redirect_url' => 'https://malo.example/gracias' ) ) ) ) );

		$this->assertSame( 201, $permitida->get_status() );
	}

	public function test_la_vista_previa_devuelve_el_html_sin_guardar_nada_y_sin_poder_enviarse() {
		$this->como_administrador();
		$antes = wp_count_posts( Form_Post_Type::NAME )->publish;

		$respuesta = $this->rest( 'POST', '/forms/preview', array( 'title' => 'Borrador', 'definition' => $this->definicion() ) );
		$html      = $respuesta->get_data()['html'];

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertStringContainsString( 'name="fm[nombre]"', $html );
		$this->assertStringNotContainsString( 'data-fm-endpoint', $html );
		$this->assertStringNotContainsString( 'admin-post.php', $html );
		$this->assertSame( $antes, wp_count_posts( Form_Post_Type::NAME )->publish );
	}

	public function test_los_ajustes_se_sanean_y_exigen_su_propia_capability() {
		$this->como_administrador();

		$respuesta = $this->rest(
			'PUT',
			'/settings',
			array(
				'trusted_proxy_mode'     => 'custom',
				'trusted_proxies'        => array( '10.0.0.0/8', 'no-es-ip', '2001:db8::/32', '10.0.0.0/8' ),
				'allowed_redirect_hosts' => array( 'Pagos.Example.com', 'https://otro.example.org/ruta', 'javascript:alert(1)', 'sin punto' ),
			)
		);

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertSame( 'custom', $respuesta->get_data()['trusted_proxy_mode'] );
		$this->assertSame( array( '10.0.0.0/8', '2001:db8::/32' ), $respuesta->get_data()['trusted_proxies'] );
		$this->assertSame( array( 'pagos.example.com', 'otro.example.org' ), $respuesta->get_data()['allowed_redirect_hosts'] );
		$this->assertSame( 400, $this->rest( 'PUT', '/settings', array( 'trusted_proxy_mode' => 'inventado' ) )->get_status() );

		// Gestionar formularios no da acceso a los ajustes.
		$usuario = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $usuario )->add_cap( Formulario_Mensaje\Lifecycle\Capabilities::MANAGE_FORMS );
		wp_set_current_user( $usuario );

		$this->assertSame( 403, $this->rest( 'GET', '/settings' )->get_status() );
		$this->assertSame( 403, $this->rest( 'PUT', '/settings', array( 'allowed_redirect_hosts' => array( 'malo.example' ) ) )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', '/forms' )->get_status() );
	}
}
