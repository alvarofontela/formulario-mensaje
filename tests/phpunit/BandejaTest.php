<?php
/**
 * Bandeja de mensajes: leídos y sin leer, datos de quien escribe, actividad de cada formulario,
 * páginas en las que está puesto y menú de administración.
 */

use Formulario_Mensaje\Admin\Admin_Menu;
use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Forms\Form_Placements;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Storage\Failure_Counter;
use Formulario_Mensaje\Storage\Stored_Submission;
use Formulario_Mensaje\Storage\Submission_Service;
use Formulario_Mensaje\Storage\Wpdb_Delivery_Repository;
use Formulario_Mensaje\Storage\Wpdb_Submission_Repository;
use Formulario_Mensaje\Submissions\Submission_Entry;

class BandejaTest extends Caso_Formulario {

	private Wpdb_Submission_Repository $envios;

	public function set_up() {
		parent::set_up();

		global $wpdb;

		$this->envios = new Wpdb_Submission_Repository( $wpdb, $this->esquema() );

		// El resultado de buscar los formularios en las páginas se guarda: cada test parte sin él.
		delete_transient( Form_Placements::CACHE_KEY );
	}

	private function servicio( Reloj_Falso $reloj ): Submission_Service {
		global $wpdb;

		$entregas = new Wpdb_Delivery_Repository( $wpdb, $this->esquema() );

		return new Submission_Service( $this->envios, $entregas, new Failure_Counter( $entregas, $this->envios ), $reloj );
	}

	/**
	 * Guarda directamente un envío.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string}> $entradas Tipo, etiqueta y valor de cada dato.
	 */
	private function guardar( int $formulario, int $cuando, array $entradas, string $referencia = 'ABCD-2345' ): int {
		$datos = array();

		foreach ( $entradas as $posicion => $entrada ) {
			$datos[] = new Submission_Entry( "campo$posicion", $entrada[0], $entrada[1], $entrada[2], $entrada[2] );
		}

		return $this->envios->insert( new Stored_Submission( 0, $formulario, $referencia, $cuando, '', '', '', $datos ) );
	}

	public function test_un_mensaje_llega_sin_leer_y_se_puede_marcar_como_leido_y_como_no_leido() {
		$formulario = $this->crear_formulario();
		$primero    = $this->guardar( $formulario, time() - 60, array( array( 'text', 'Nombre', 'Ana' ) ), 'AAAA-2222' );
		$segundo    = $this->guardar( $formulario, time(), array( array( 'text', 'Nombre', 'Luis' ) ), 'BBBB-3333' );

		$this->como_administrador();

		$this->assertSame( array( false, false ), array_column( $this->rest( 'GET', '/submissions' )->get_data(), 'read' ) );
		$this->assertSame( 2, $this->rest( 'GET', '/submissions/counts' )->get_data()['unread'] );

		$marcado = $this->rest( 'POST', '/submissions/read', array( 'ids' => array( $primero ) ) );

		$this->assertSame( 200, $marcado->get_status() );
		$this->assertSame( 1, $marcado->get_data()['updated'] );
		$this->assertSame( 1, $marcado->get_data()['unread'], 'La respuesta trae ya el recuento nuevo.' );
		$this->assertTrue( $this->envios->find( $primero )->is_read() );
		$this->assertFalse( $this->envios->find( $segundo )->is_read() );

		$sin_leer = $this->rest( 'GET', '/submissions', array( 'unread' => true ) );

		$this->assertSame( array( 'BBBB-3333' ), array_column( $sin_leer->get_data(), 'reference' ) );
		$this->assertSame( '1', $sin_leer->get_headers()['X-WP-Total'] );

		$this->rest( 'POST', '/submissions/read', array( 'ids' => array( $primero ), 'read' => false ) );

		$this->assertFalse( $this->envios->find( $primero )->is_read(), 'Se puede dejar otra vez pendiente.' );
		$this->assertSame( 2, $this->envios->count_unread() );
	}

	public function test_se_pueden_marcar_todos_como_leidos_de_una_vez() {
		$formulario = $this->crear_formulario();

		$this->guardar( $formulario, time() - 60, array( array( 'text', 'Nombre', 'Ana' ) ), 'AAAA-2222' );
		$this->guardar( $formulario, time(), array( array( 'text', 'Nombre', 'Luis' ) ), 'BBBB-3333' );

		$this->como_administrador();

		$respuesta = $this->rest( 'POST', '/submissions/read', array( 'all' => true ) );

		$this->assertSame( 2, $respuesta->get_data()['updated'] );
		$this->assertSame( 0, $respuesta->get_data()['unread'] );
		$this->assertSame( 0, $this->envios->count_unread() );
	}

	public function test_el_estado_de_lectura_solo_lo_cambia_quien_puede_ver_los_envios() {
		$formulario = $this->crear_formulario();
		$id         = $this->guardar( $formulario, time(), array( array( 'text', 'Nombre', 'Ana' ) ) );

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->rest( 'POST', '/submissions/read', array( 'ids' => array( $id ) ) )->get_status() );
		$this->assertSame( 401, $this->rest( 'GET', '/submissions/counts' )->get_status() );

		$gestor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $gestor )->add_cap( Capabilities::MANAGE_FORMS );
		wp_set_current_user( $gestor );

		$this->assertSame( 403, $this->rest( 'POST', '/submissions/read', array( 'all' => true ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/submissions/counts' )->get_status() );
		$this->assertFalse( $this->envios->find( $id )->is_read(), 'Nada ha cambiado.' );
	}

	public function test_el_listado_presenta_cada_envio_como_un_mensaje() {
		$formulario = $this->crear_formulario();

		// El listado va del más reciente al más antiguo: el segundo que se guarda sale el primero.
		$this->guardar( $formulario, time() - 60, array( array( 'select', 'Motivo', 'Revisión' ), array( 'email', 'Correo', 'no es un correo' ) ), 'BBBB-3333' );
		$this->guardar(
			$formulario,
			time(),
			array(
				array( 'email', 'Correo', 'marta@example.org' ),
				array( 'text', 'Nombre', 'Marta Ruiz' ),
				array( 'tel', 'Teléfono', '612 345 078' ),
				array( 'textarea', 'Mensaje', "Me duele una muela.\nPuedo ir por la tarde." ),
				array( 'file', 'Radiografía', 'radiografia.pdf (77,0 B)' ),
			)
		);

		$this->como_administrador();

		$mensajes = $this->rest( 'GET', '/submissions' )->get_data();

		$this->assertSame( 'Marta Ruiz', $mensajes[0]['sender'], 'Quien escribe es el primer dato de un campo de texto.' );
		$this->assertSame( 'marta@example.org', $mensajes[0]['email'] );
		$this->assertSame( '612 345 078', $mensajes[0]['phone'] );
		$this->assertSame( 'Me duele una muela. Puedo ir por la tarde.', $mensajes[0]['excerpt'], 'El comienzo del mensaje, en una línea.' );
		$this->assertTrue( $mensajes[0]['has_files'] );

		// El detalle dice qué campos van ya en la cabecera, para no repetirlos: aquí, el nombre, el correo y el teléfono.
		$detalle = $this->rest( 'GET', '/submissions/' . $mensajes[0]['id'] )->get_data();

		$this->assertSame( array( 'campo1', 'campo0', 'campo2' ), $detalle['header_fields'] );
		$this->assertSame( array(), $this->rest( 'GET', '/submissions/' . $mensajes[1]['id'] )->get_data()['header_fields'], 'Un correo que no vale se queda con los demás datos.' );

		$this->assertSame( '', $mensajes[1]['sender'] );
		$this->assertSame( '', $mensajes[1]['email'], 'Una dirección que no es válida no se ofrece para responder.' );
		$this->assertSame( '', $mensajes[1]['phone'] );
		$this->assertSame( 'Revisión · no es un correo', $mensajes[1]['excerpt'], 'Sin texto largo, el resumen de siempre.' );
		$this->assertFalse( $mensajes[1]['has_files'] );
	}

	public function test_la_actividad_de_un_formulario_cuenta_los_mensajes_del_mes_y_los_que_estan_sin_leer() {
		$formulario = $this->crear_formulario();
		$otro       = $this->crear_formulario( array(), 'Otro' );
		// 15 de noviembre de 2023, a mediodía.
		$reloj      = new Reloj_Falso( 1_700_049_600 );

		$viejo = $this->guardar( $formulario, $reloj->now() - 40 * DAY_IN_SECONDS, array( array( 'text', 'Nombre', 'Ana' ) ), 'AAAA-2222' );
		$this->guardar( $formulario, $reloj->now() - 2 * DAY_IN_SECONDS, array( array( 'text', 'Nombre', 'Luis' ) ), 'BBBB-3333' );
		$this->guardar( $formulario, $reloj->now() - 3600, array( array( 'text', 'Nombre', 'Marta' ) ), 'CCCC-4444' );

		$servicio = $this->servicio( $reloj );

		$servicio->mark_read( array( $viejo ), true );

		$actividad = $servicio->activity_by_form();

		$this->assertSame(
			array(
				'total'  => 3,
				'recent' => 2,
				'unread' => 2,
				'last'   => $reloj->now() - 3600,
			),
			$actividad[ $formulario ]
		);
		$this->assertArrayNotHasKey( $otro, $actividad, 'Un formulario sin mensajes no aparece.' );
	}

	public function test_al_actualizar_desde_una_version_sin_estado_de_lectura_los_mensajes_antiguos_quedan_leidos() {
		$formulario = $this->crear_formulario();
		$antiguo    = $this->guardar( $formulario, time() - 3600, array( array( 'text', 'Nombre', 'Ana' ) ), 'AAAA-2222' );

		update_option( Schema::VERSION_OPTION, 3 );
		$this->esquema()->install();

		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( $this->envios->find( $antiguo )->is_read(), 'Lo que ya estaba no aparece de golpe como pendiente.' );

		// Con el esquema ya al día, repetir la instalación no toca los mensajes que llegan después.
		$nuevo = $this->guardar( $formulario, time(), array( array( 'text', 'Nombre', 'Luis' ) ), 'BBBB-3333' );
		$this->esquema()->install();

		$this->assertFalse( $this->envios->find( $nuevo )->is_read() );
	}

	public function test_se_averigua_en_que_paginas_publicadas_esta_cada_formulario() {
		global $wpdb;

		$contacto = $this->crear_formulario();
		$cita     = $this->crear_formulario( array(), 'Pedir cita' );
		$suelto   = $this->crear_formulario( array(), 'Sin colocar' );

		$con_bloque = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Contacto',
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:formulario-mensaje/form {"formId":' . $contacto . ',"formStyle":{}} /--></div><!-- /wp:group -->',
			)
		);
		$con_codigo = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Pide tu <em>cita</em>',
				'post_content' => 'Rellena esto: [formulario_mensaje id="' . $cita . '"] y también [formulario_mensaje id=' . $contacto . ']',
			)
		);

		// Nada de esto cuenta: un borrador, un shortcode escrito para enseñarlo y un contenido que no se puede visitar.
		self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => '[formulario_mensaje id="' . $suelto . '"]' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => 'Se escribe así: [[formulario_mensaje id="' . $suelto . '"]]' ) );
		self::factory()->post->create( array( 'post_type' => 'wp_block', 'post_content' => '[formulario_mensaje id="' . $suelto . '"]' ) );

		$con_elementor = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Hecha con Elementor' ) );

		update_post_meta(
			$con_elementor,
			'_elementor_data',
			wp_slash(
				wp_json_encode(
					array(
						array(
							'elType'   => 'container',
							'elements' => array(
								array(
									'elType'     => 'widget',
									'widgetType' => 'formulario-mensaje',
									'settings'   => array( 'form_id' => (string) $cita ),
								),
							),
						),
					)
				)
			)
		);

		// Guardar contenido ha vaciado lo averiguado, así que se busca de nuevo.
		$paginas = ( new Form_Placements( $wpdb ) )->all();

		$this->assertEqualsCanonicalizing( array( $con_bloque, $con_codigo ), array_column( $paginas[ $contacto ], 'id' ) );
		$this->assertEqualsCanonicalizing( array( $con_codigo, $con_elementor ), array_column( $paginas[ $cita ], 'id' ) );
		$this->assertArrayNotHasKey( $suelto, $paginas );
		$this->assertContains( 'Pide tu cita', array_column( $paginas[ $cita ], 'title' ), 'El título va sin etiquetas.' );

		$this->como_administrador();

		$listado = array_column( $this->rest( 'GET', '/forms' )->get_data(), 'placements', 'id' );

		$this->assertCount( 2, $listado[ $contacto ] );
		$this->assertSame( array( 'title', 'url' ), array_keys( $listado[ $contacto ][0] ), 'Solo lo que ya es público.' );
		$this->assertSame( array(), $listado[ $suelto ] );
		$this->assertCount( 2, $this->rest( 'GET', "/forms/$cita" )->get_data()['placements'] );
	}

	public function test_lo_averiguado_se_olvida_al_guardar_o_borrar_contenido() {
		global $wpdb;

		$formulario = $this->crear_formulario();
		$paginas    = new Form_Placements( $wpdb );

		$this->assertSame( array(), $paginas->all() );
		$this->assertIsArray( get_transient( Form_Placements::CACHE_KEY ), 'El resultado se guarda para no repetir la búsqueda.' );

		$pagina = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '[formulario_mensaje id="' . $formulario . '"]' ) );

		$this->assertSame( array( $pagina ), array_column( $paginas->all()[ $formulario ], 'id' ) );

		wp_delete_post( $pagina, true );

		$this->assertSame( array(), $paginas->all() );
	}

	public function test_el_menu_abre_por_los_mensajes_y_avisa_de_los_que_estan_sin_leer() {
		global $menu, $submenu, $wpdb;

		$formulario = $this->crear_formulario();

		$this->guardar( $formulario, time(), array( array( 'text', 'Nombre', 'Ana' ) ), 'AAAA-2222' );
		$this->guardar( $formulario, time(), array( array( 'text', 'Nombre', 'Luis' ) ), 'BBBB-3333' );

		$this->como_administrador();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- El test parte de un menú vacío.
		$menu    = array();
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		( new Admin_Menu( $this->servicio( new Reloj_Falso() ) ) )->register();

		$paginas = $submenu[ Admin_Menu::SUBMISSIONS_PAGE ];

		$this->assertSame(
			array( Admin_Menu::SUBMISSIONS_PAGE, Admin_Menu::FORMS_PAGE, Admin_Menu::SETTINGS_PAGE ),
			array_column( $paginas, 2 ),
			'Mensajes, formularios y ajustes, por ese orden.'
		);
		$this->assertStringContainsString( 'count-2', $paginas[0][0] );
		$this->assertStringNotContainsString( 'awaiting-mod', $paginas[1][0] );

		// Quien solo gestiona formularios no ve ni la pantalla de mensajes ni cuántos hay.
		$gestor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $gestor )->add_cap( Capabilities::MANAGE_FORMS );
		wp_set_current_user( $gestor );

		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		( new Admin_Menu( $this->servicio( new Reloj_Falso() ) ) )->register();

		$this->assertSame( array( Admin_Menu::FORMS_PAGE ), array_column( $submenu[ Admin_Menu::FORMS_PAGE ], 2 ) );
		$this->assertStringNotContainsString( 'awaiting-mod', wp_json_encode( $menu ) );
	}
}
