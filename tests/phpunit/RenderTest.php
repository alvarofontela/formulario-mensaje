<?php
/**
 * HTML del formulario: shortcode, bloque, accesibilidad y compatibilidad con caché de página.
 */

class RenderTest extends Caso_Formulario {

	public function test_el_shortcode_pinta_el_formulario_publicado_y_encola_sus_ficheros() {
		$formulario = $this->crear_formulario();

		$html = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertStringContainsString( '<form class="fm-form"', $html );
		$this->assertStringContainsString( 'aria-label="Contacto"', $html );
		$this->assertStringContainsString( "data-fm-form=\"$formulario\"", $html );
		$this->assertStringContainsString( "/formulario-mensaje/v1/forms/$formulario\"", $html );
		$this->assertStringContainsString( 'name="fm[nombre]"', $html );
		$this->assertStringContainsString( 'name="fm_website"', $html, 'Campo trampa.' );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertTrue( wp_style_is( 'formulario-mensaje-form', 'enqueued' ) );
	}

	public function test_los_estilos_van_en_la_cabecera_solo_en_las_paginas_con_formulario() {
		$formulario = $this->crear_formulario();
		$con        = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => "[formulario_mensaje id=\"$formulario\"]" ) );
		$sin        = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => 'Sin formulario.' ) );

		wp_dequeue_style( 'formulario-mensaje-form' );
		$this->go_to( get_permalink( $sin ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_style_is( 'formulario-mensaje-form', 'enqueued' ) );

		$this->go_to( get_permalink( $con ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_style_is( 'formulario-mensaje-form', 'enqueued' ) );

		wp_dequeue_style( 'formulario-mensaje-form' );
	}

	public function test_el_html_no_lleva_nada_que_caduque() {
		$formulario = $this->crear_formulario();

		// El contador de instancias es lo único que cambia entre dos pintados.
		$normalizar = static fn ( string $html ): string => (string) preg_replace( '/(formulario-mensaje|fm)-(\d+)-\d+/', '$1-$2-N', $html );
		$html       = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertStringNotContainsString( 'nonce', $html );
		$this->assertStringNotContainsString( 'token', $html );
		$this->assertSame( $normalizar( $html ), $normalizar( do_shortcode( "[formulario_mensaje id=\"$formulario\"]" ) ) );
	}

	public function test_dos_formularios_iguales_en_la_misma_pagina_no_repiten_identificadores() {
		$formulario = $this->crear_formulario();

		$html = do_shortcode( "[formulario_mensaje id=\"$formulario\"][formulario_mensaje id=\"$formulario\"]" );

		preg_match_all( '/ id="([^"]+)"/', $html, $ids );

		$this->assertNotEmpty( $ids[1] );
		$this->assertSame( count( $ids[1] ), count( array_unique( $ids[1] ) ) );
	}

	public function test_cada_formulario_numera_sus_apariciones_aunque_comparta_pagina_con_otros() {
		$primero = $this->crear_formulario();
		$segundo = $this->crear_formulario( array(), 'Presupuesto' );

		$html = do_shortcode( "[formulario_mensaje id=\"$primero\"][formulario_mensaje id=\"$segundo\"][formulario_mensaje id=\"$segundo\"]" );

		// La vuelta de un envío sin JavaScript apunta a la primera aparición de su formulario, vaya o no detrás de otros.
		$this->assertStringContainsString( "id=\"formulario-mensaje-$primero-1\"", $html );
		$this->assertStringContainsString( "id=\"formulario-mensaje-$segundo-1\"", $html );
		$this->assertStringContainsString( "id=\"formulario-mensaje-$segundo-2\"", $html );
	}

	public function test_un_formulario_inexistente_o_en_la_papelera_no_pinta_nada_al_visitante() {
		$formulario = $this->crear_formulario();
		wp_trash_post( $formulario );

		$this->assertSame( '', do_shortcode( "[formulario_mensaje id=\"$formulario\"]" ) );
		$this->assertSame( '', do_shortcode( '[formulario_mensaje id="999999"]' ) );
		$this->assertSame( '', do_shortcode( '[formulario_mensaje]' ) );

		$this->como_administrador();

		$this->assertStringContainsString( 'fm-unavailable', do_shortcode( "[formulario_mensaje id=\"$formulario\"]" ), 'Quien gestiona formularios ve un aviso.' );
	}

	public function test_el_shortcode_no_pinta_contenidos_que_no_son_formularios() {
		$entrada = self::factory()->post->create( array( 'post_title' => 'Entrada', 'post_status' => 'publish' ) );

		$this->assertSame( '', do_shortcode( "[formulario_mensaje id=\"$entrada\"]" ) );
	}

	public function test_el_bloque_esta_registrado_y_pinta_el_mismo_formulario() {
		$formulario = $this->crear_formulario();

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'formulario-mensaje/form' ), 'Hay que compilar antes: rt build.' );

		$html = do_blocks( "<!-- wp:formulario-mensaje/form {\"formId\":$formulario} /-->" );

		$this->assertStringContainsString( 'wp-block-formulario-mensaje-form', $html );
		$this->assertStringContainsString( 'name="fm[nombre]"', $html );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:formulario-mensaje/form /-->' ) ) );
	}

	public function test_los_textos_configurados_se_escapan_en_el_formulario() {
		$formulario = $this->crear_formulario( array( 'settings' => array( 'submit_label' => 'Enviar "ya" <b>!' ) ), 'Contacto "urgente" <script>' );

		$html = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'Enviar &quot;ya&quot; !', $html );
	}

	public function test_tras_un_envio_sin_javascript_la_pagina_muestra_el_mensaje_de_exito() {
		$formulario = $this->crear_formulario( array( 'settings' => array( 'success_message' => 'Recibido. Referencia {reference}. Hola {field:nombre}' ) ) );

		// El shortcode lee los parámetros de la URL a través de un servicio; aquí se simula la petición.
		$_GET['formulario_mensaje_sent'] = (string) $formulario;
		$_GET['formulario_mensaje_ref']  = 'ABCD-2345';
		$html                            = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );
		$_GET['formulario_mensaje_ref']  = '<script>alert(1)</script>';
		$manipulado                      = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );
		unset( $_GET['formulario_mensaje_sent'], $_GET['formulario_mensaje_ref'] );

		$this->assertStringContainsString( 'Recibido. Referencia ABCD-2345. Hola </div>', $html );
		$this->assertStringNotContainsString( '<form', $html, 'Por defecto el formulario se oculta tras el envío.' );
		$this->assertStringNotContainsString( 'alert(1)', $manipulado, 'Una referencia que no tiene el formato esperado se descarta.' );
	}
}
