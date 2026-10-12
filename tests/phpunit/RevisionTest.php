<?php
/**
 * Casos que salieron de la revisión de todo el código del plugin: cada uno fija un fallo corregido.
 */

use Formulario_Mensaje\Antispam\Rate_Limiter;
use Formulario_Mensaje\Elementor\Form_Choices;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Forms\Post_Form_Repository;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use Formulario_Mensaje\Plugin;
use Formulario_Mensaje\Support\Php_Server_Vars;
use Formulario_Mensaje\Support\Plain_Text;

class RevisionTest extends Caso_Formulario {

	public function tear_down() {
		unset( $_SERVER['HTTP_REFERER'] );

		parent::tear_down();
	}

	// --- Texto y direcciones con «%» ------------------------------------------------------------

	public function test_un_porcentaje_seguido_de_letras_no_se_pierde() {
		// «%de», «%fa» o «%ca» son, para WordPress, un carácter codificado, y los quita.
		$this->assertSame( 'un 50%de descuento', Plain_Text::line( 'un 50%de descuento' ) );
		$this->assertSame( "20%fa\n100%ca", Plain_Text::block( "20%fa\n100%ca" ) );
		$this->assertSame( 'a<b y 5%ab', Plain_Text::line( 'a<b y <i>5%ab</i>' ) );
		$this->assertSame( '100% seguro, 5 % y %', Plain_Text::line( '100% seguro, 5 % y %' ) );
	}

	public function test_las_direcciones_conservan_sus_caracteres_codificados() {
		$direccion = 'https://ejemplo.com/mi%20informe%20final.pdf?q=a%2Bb&utm_campaign=rebajas%20verano';

		$this->assertSame( $direccion, Plain_Text::line( $direccion ) );

		// En un campo de dirección web.
		$formulario = $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'web', 'type' => 'url', 'settings' => array( 'label' => 'Web' ) ),
					array( 'id' => 'nota', 'type' => 'textarea', 'settings' => array( 'label' => 'Nota' ) ),
					array( 'id' => 'origen', 'type' => 'hidden', 'settings' => array( 'label' => 'Origen', 'source' => 'page_url' ) ),
				),
			)
		);
		$pagina     = home_url( '/%D0%BA%D0%BE%D0%BD%D1%82%D0%B0%D0%BA%D1%82%D1%8B/?utm_campaign=rebajas%20verano' );

		$this->assertSame( 200, $this->enviar( $formulario, array( 'web' => $direccion, 'nota' => "Mira $direccion\ny un 50%de descuento", 'origen' => $pagina ) )->get_status() );

		$this->como_administrador();
		$envio   = $this->rest( 'GET', '/submissions', array( 'form' => $formulario ) )->get_data()[0];
		$valores = array_column( $this->rest( 'GET', '/submissions/' . $envio['id'] )->get_data()['entries'], 'value', 'field_id' );

		$this->assertSame( $direccion, $valores['web'] );
		$this->assertSame( "Mira $direccion\ny un 50%de descuento", $valores['nota'] );
		$this->assertSame( $pagina, $valores['origen'] );
	}

	public function test_la_pagina_de_procedencia_llega_entera_desde_la_cabecera_de_la_peticion() {
		$pagina = home_url( '/%D0%BA%D0%BE/?utm_campaign=rebajas%20verano' );

		$_SERVER['HTTP_REFERER'] = wp_slash( $pagina );

		$this->assertSame( $pagina, ( new Php_Server_Vars() )->get( 'HTTP_REFERER' ) );

		// Y sigue sin dejar pasar etiquetas ni saltos de línea.
		$_SERVER['HTTP_REFERER'] = "http://example.org/<script>alert(1)</script>\r\nX-Colado: 1";

		$this->assertSame( 'http://example.org/ X-Colado: 1', ( new Php_Server_Vars() )->get( 'HTTP_REFERER' ) );
	}

	// --- Papelera de formularios ----------------------------------------------------------------

	public function test_un_formulario_en_la_papelera_no_lo_borra_wordpress_por_su_cuenta() {
		$formulario = $this->crear_formulario();

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );

		$this->como_administrador();
		$this->assertSame( 200, $this->rest( 'DELETE', "/forms/$formulario" )->get_status() );

		$this->assertSame( 'trash', get_post_status( $formulario ) );
		// WordPress vacía su papelera buscando esta marca de tiempo: sin ella, el formulario no es suyo.
		$this->assertSame( '', get_post_meta( $formulario, '_wp_trash_meta_time', true ) );

		wp_scheduled_delete();

		$this->assertSame( 'trash', get_post_status( $formulario ), 'Sigue en la papelera del plugin.' );
		$this->assertCount( 1, $this->rest( 'GET', '/submissions', array( 'form' => $formulario ) )->get_data(), 'Y sus envíos, con él.' );

		// Enviarlo otra vez a la papelera no hace nada, y se puede restaurar.
		$this->assertTrue( Plugin::service( Form_Repository::class )->trash( $formulario ) );
		$this->assertSame( 200, $this->rest( 'POST', "/forms/$formulario/restore" )->get_status() );
		$this->assertSame( Form::STATUS_PUBLISHED, get_post_status( $formulario ) );
		$this->assertSame( '', get_post_meta( $formulario, '_wp_trash_meta_status', true ) );
	}

	// --- Opciones guardadas con el saneado anterior ---------------------------------------------

	public function test_una_opcion_guardada_con_la_entidad_antigua_se_puede_seguir_eligiendo() {
		$opciones   = array( array( 'label' => 'Edad X18', 'value' => 'Edad X18' ), array( 'label' => 'Adulto', 'value' => 'Adulto' ) );
		$formulario = $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'tramo', 'type' => 'radio', 'settings' => array( 'label' => 'Tramo', 'required' => true, 'options' => $opciones ) ),
					array( 'id' => 'varios', 'type' => 'checkboxes', 'settings' => array( 'label' => 'Varios', 'options' => $opciones ) ),
				),
			)
		);

		// Así quedaba guardada, hasta la versión 0.11, una opción escrita como «Edad <18».
		$guardada = (string) get_post_meta( $formulario, Post_Form_Repository::DEFINITION_META, true );
		update_post_meta( $formulario, Post_Form_Repository::DEFINITION_META, wp_slash( str_replace( 'Edad X18', 'Edad &lt;18', $guardada ) ) );
		clean_post_cache( $formulario );

		// El navegador envía el signo, no la entidad.
		$respuesta = $this->enviar( $formulario, array( 'tramo' => 'Edad <18', 'varios' => array( 'Edad <18', 'Adulto' ) ) );

		$this->assertSame( 200, $respuesta->get_status(), wp_json_encode( $respuesta->get_data() ) );

		// Una opción que no existe sigue sin valer.
		$this->assertSame( 422, $this->enviar( $formulario, array( 'tramo' => 'Edad <19' ) )->get_status() );
	}

	// --- Actualizaciones parciales --------------------------------------------------------------

	public function test_actualizar_solo_el_nombre_de_un_formulario_no_vacia_sus_campos() {
		$formulario = $this->crear_formulario();

		$this->como_administrador();

		$renombrado = $this->rest( 'PATCH', "/forms/$formulario", array( 'title' => 'Contacto 2026' ) )->get_data();

		$this->assertSame( 'Contacto 2026', $renombrado['title'] );
		$this->assertSame( array( 'nombre', 'correo', 'mensaje' ), array_column( $renombrado['definition']['fields'], 'id' ) );
		$this->assertCount( 1, $renombrado['definition']['actions'] );
		$this->assertSame( array( 'ventas@example.org' ), $renombrado['definition']['actions'][0]['settings']['to'] );

		// Y al revés: cambiar la definición no toca el nombre.
		$sin_mensaje = $this->definicion();
		array_pop( $sin_mensaje['fields'] );

		$recortado = $this->rest( 'PUT', "/forms/$formulario", array( 'definition' => $sin_mensaje ) )->get_data();

		$this->assertSame( 'Contacto 2026', $recortado['title'] );
		$this->assertSame( array( 'nombre', 'correo' ), array_column( $recortado['definition']['fields'], 'id' ) );

		// Una definición vacía enviada a propósito sí vacía el formulario: es lo que se ha pedido.
		$vaciado = $this->rest( 'PUT', "/forms/$formulario", array( 'definition' => array( 'fields' => array(), 'actions' => array() ) ) )->get_data();

		$this->assertSame( array(), $vaciado['definition']['fields'] );
	}

	public function test_cambiar_un_ajuste_no_devuelve_los_demas_a_su_valor_de_fabrica() {
		$this->como_administrador();

		$this->rest(
			'PUT',
			'/settings',
			array(
				'trusted_proxy_mode'       => 'custom',
				'trusted_proxies'          => array( '10.0.0.1' ),
				'allowed_redirect_hosts'   => array( 'pagos.example.org' ),
				'accept_no_js'             => false,
				'delete_data_on_uninstall' => true,
				'failure_alerts'           => false,
				'failure_alert_email'      => 'avisos@example.org',
				'delivery_log_days'        => 15,
			)
		);

		$antes   = $this->rest( 'GET', '/settings' )->get_data();
		$despues = $this->rest( 'PATCH', '/settings', array( 'delivery_log_days' => 30 ) )->get_data();

		$this->assertSame( 30, $despues['delivery_log_days'] );

		foreach ( array( 'trusted_proxy_mode', 'trusted_proxies', 'allowed_redirect_hosts', 'accept_no_js', 'delete_data_on_uninstall', 'failure_alerts', 'failure_alert_email' ) as $clave ) {
			$this->assertSame( $antes[ $clave ], $despues[ $clave ], $clave );
		}

		$this->assertSame( array( '10.0.0.1' ), $despues['trusted_proxies'] );
		$this->assertFalse( $despues['accept_no_js'] );

		// Un valor enviado a propósito, aunque sea «falso» o una lista vacía, sí cuenta.
		$vaciado = $this->rest( 'PATCH', '/settings', array( 'trusted_proxies' => array(), 'delete_data_on_uninstall' => false ) )->get_data();

		$this->assertSame( array(), $vaciado['trusted_proxies'] );
		$this->assertFalse( $vaciado['delete_data_on_uninstall'] );
	}

	// --- Campo oculto de valor fijo en condiciones ----------------------------------------------

	public function test_un_campo_oculto_de_valor_fijo_se_imprime_solo_si_una_condicion_depende_de_el() {
		$condicion  = array( 'match' => 'all', 'rules' => array( array( 'field' => 'plan', 'operator' => 'equals', 'value' => 'pro' ) ) );
		$formulario = $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'plan', 'type' => 'hidden', 'settings' => array( 'label' => 'Plan', 'source' => 'fixed', 'value' => 'pro' ) ),
					array( 'id' => 'clave', 'type' => 'hidden', 'settings' => array( 'label' => 'Clave', 'source' => 'fixed', 'value' => 'no-se-publica' ) ),
					array( 'id' => 'empresa', 'type' => 'text', 'settings' => array( 'label' => 'Empresa', 'required' => true ), 'condition' => $condicion ),
				),
			)
		);
		$html       = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		// El navegador necesita el valor para mostrar «Empresa»; el otro campo fijo no hace falta en la página.
		$this->assertMatchesRegularExpression( '#<input type="hidden" id="[^"]+" name="fm\[plan\]" data-fm-field="plan" value="pro">#', $html );
		$this->assertStringNotContainsString( 'no-se-publica', $html );
		$this->assertStringNotContainsString( 'fm[clave]', $html );

		// Lo que llegue en la petición para un valor fijo sigue sin contar: manda la configuración.
		$sin_empresa = $this->enviar( $formulario, array( 'plan' => 'básico', 'clave' => 'otra' ) );

		$this->assertSame( 422, $sin_empresa->get_status() );
		$this->assertSame( array( 'empresa' ), array_keys( $sin_empresa->get_data()['data']['errors'] ) );
		$this->assertSame( 200, $this->enviar( $formulario, array( 'plan' => 'básico', 'empresa' => 'ACME' ) )->get_status() );

		$this->como_administrador();
		$envio   = $this->rest( 'GET', '/submissions', array( 'form' => $formulario ) )->get_data()[0];
		$valores = array_column( $this->rest( 'GET', '/submissions/' . $envio['id'] )->get_data()['entries'], 'value', 'field_id' );

		$this->assertSame( 'pro', $valores['plan'] );
		$this->assertSame( 'no-se-publica', $valores['clave'] );
	}

	// --- Mantenimiento --------------------------------------------------------------------------

	public function test_el_mantenimiento_diario_borra_los_contadores_de_limite_caducados() {
		global $wpdb;

		$tabla    = $this->esquema()->rate_limits_table();
		$limitador = Plugin::service( Rate_Limiter::class );

		$limitador->hit( 'revision-caducado', 600 );
		$limitador->hit( 'revision-vigente', 600 );

		// Un contador cuya ventana terminó ayer: su visitante no ha vuelto y nadie lo va a reutilizar.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s ORDER BY expires_at ASC, bucket ASC LIMIT 1', $tabla, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );

		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tabla ) ) );

		do_action( Maintenance_Schedule::HOOK );

		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tabla ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE expires_at <= %s', $tabla, gmdate( 'Y-m-d H:i:s' ) ) ) );
	}

	// --- Elementor ------------------------------------------------------------------------------

	public function test_los_nombres_de_formulario_llegan_escapados_al_desplegable_del_widget() {
		// Sin «>» no es una etiqueta para el saneado de texto, pero un navegador sí la abriría.
		$peligroso = $this->crear_formulario( array(), '<img src=x onerror=alert(document.cookie)//' );
		$normal    = $this->crear_formulario( array(), 'Contacto & "ventas"' );
		$borrado   = $this->crear_formulario( array(), 'En la papelera' );

		$this->como_administrador();
		$this->rest( 'DELETE', "/forms/$borrado" );

		$opciones = ( new Form_Choices( Plugin::service( Form_Repository::class ) ) )->options();

		$this->assertSame( '&lt;img src=x onerror=alert(document.cookie)//', $opciones[ (string) $peligroso ] );
		$this->assertSame( 'Contacto &amp; &quot;ventas&quot;', $opciones[ (string) $normal ] );
		$this->assertArrayHasKey( '0', $opciones );
		$this->assertArrayNotHasKey( (string) $borrado, $opciones, 'Solo se ofrecen los publicados.' );

		foreach ( $opciones as $texto ) {
			$this->assertStringNotContainsString( '<', $texto );
		}
	}

	// --- Acciones -------------------------------------------------------------------------------

	public function test_una_accion_se_busca_por_su_identificador_y_su_tipo() {
		$formulario = Plugin::service( Form_Repository::class )->find( $this->crear_formulario() );

		$this->assertSame( 'aviso', $formulario->definition->get_action( 'aviso', 'email' )['id'] );
		$this->assertNull( $formulario->definition->get_action( 'aviso', 'webhook' ), 'Mismo identificador, otro tipo.' );
		$this->assertNull( $formulario->definition->get_action( 'otra', 'email' ) );
		$this->assertSame( array(), $formulario->definition->condition_sources() );
	}
}
