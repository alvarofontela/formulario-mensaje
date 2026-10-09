<?php
/**
 * Envíos guardados: metadatos, listado, detalle, borrado, registro de entregas, reintento,
 * exportación, retención y desinstalación.
 */

use Formulario_Mensaje\Admin\Export_Controller;
use Formulario_Mensaje\Forms\Form_Post_Type;
use Formulario_Mensaje\Forms\Post_Form_Repository;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use Formulario_Mensaje\Lifecycle\Uninstaller;
use Formulario_Mensaje\Queue\Queue_Schedule;
use Formulario_Mensaje\Settings\Option_Settings_Repository;
use Formulario_Mensaje\Storage\Csv_Exporter;
use Formulario_Mensaje\Storage\Failure_Counter;
use Formulario_Mensaje\Storage\Ip_Anonymizer;
use Formulario_Mensaje\Storage\Retention_Service;
use Formulario_Mensaje\Storage\Stored_Submission;
use Formulario_Mensaje\Storage\Submission_Service;
use Formulario_Mensaje\Storage\Wpdb_Delivery_Repository;
use Formulario_Mensaje\Storage\Wpdb_Submission_Repository;
use Formulario_Mensaje\Submissions\Submission_Entry;

class AlmacenTest extends Caso_Formulario {

	private Wpdb_Submission_Repository $envios;
	private Wpdb_Delivery_Repository $entregas;

	public function set_up() {
		parent::set_up();

		global $wpdb;

		$this->envios   = new Wpdb_Submission_Repository( $wpdb, $this->esquema() );
		$this->entregas = new Wpdb_Delivery_Repository( $wpdb, $this->esquema() );
	}

	/** Envía un formulario y devuelve el identificador del envío guardado más reciente. */
	private function enviar_y_guardar( int $formulario, array $valores ): int {
		$respuesta = $this->enviar( $formulario, $valores );

		$this->assertSame( 200, $respuesta->get_status(), wp_json_encode( $respuesta->get_data() ) );

		return $this->envios->ids_before( $formulario, '', 100 )[ count( $this->envios->ids_before( $formulario, '', 100 ) ) - 1 ];
	}

	private function contador(): Failure_Counter {
		return new Failure_Counter( $this->entregas, $this->envios );
	}

	private function servicio( ?Reloj_Falso $reloj = null ): Submission_Service {
		return new Submission_Service( $this->envios, $this->entregas, $this->contador(), $reloj ?? new Reloj_Falso() );
	}

	/** Guarda directamente un envío con una fecha dada. */
	private function guardar( int $formulario, int $cuando, array $valores = array( 'nombre' => 'Ana' ), string $referencia = 'ABCD-2345' ): int {
		$entradas = array();

		foreach ( $valores as $id => $valor ) {
			$entradas[] = new Submission_Entry( $id, 'text', ucfirst( $id ), $valor, $valor );
		}

		return $this->envios->insert( new Stored_Submission( 0, $formulario, $referencia, $cuando, 'https://example.org/contacto/', '', '', $entradas ) );
	}

	public function test_un_envio_se_guarda_con_las_etiquetas_de_ese_momento_y_sin_ip_ni_navegador_por_defecto() {
		$formulario = $this->crear_formulario();

		$_SERVER['HTTP_USER_AGENT'] = 'Navegador de prueba';
		$_SERVER['HTTP_REFERER']    = home_url( '/contacto/?utm=1' );
		$id                         = $this->enviar_y_guardar( $formulario, array( 'nombre' => 'Ana Pérez', 'correo' => 'ana@example.org', 'mensaje' => "Hola\nmundo" ) );
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_REFERER'] );

		$envio = $this->envios->find( $id );

		$this->assertSame( $formulario, $envio->form_id );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $envio->reference );
		$this->assertSame( home_url( '/contacto/?utm=1' ), $envio->page_url );
		$this->assertSame( '', $envio->ip, 'La IP no se guarda si el formulario no lo pide.' );
		$this->assertSame( '', $envio->user_agent );
		$this->assertSame( array( 'Nombre', 'Correo', 'Mensaje' ), array_map( static fn ( $entrada ) => $entrada->label, $envio->entries ) );
		$this->assertSame( "Hola\nmundo", $envio->entries[2]->value );
		$this->assertEqualsWithDelta( time(), $envio->created_at, 5 );

		// El formulario cambia después: el envío conserva sus etiquetas.
		$this->como_administrador();
		$this->rest( 'PUT', "/forms/$formulario", array( 'title' => 'Contacto', 'definition' => $this->definicion( array( 'fields' => array( array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre completo' ) ) ) ) ) ) );

		$this->assertSame( 'Nombre', $this->envios->find( $id )->entries[0]->label );
	}

	public function test_la_ip_se_guarda_completa_o_anonimizada_solo_si_el_formulario_lo_activa() {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.77';
		$_SERVER['HTTP_USER_AGENT'] = 'Navegador de prueba';

		$anonima  = $this->crear_formulario( array( 'settings' => array( 'store_ip' => 'anonymized', 'store_user_agent' => true ) ) );
		$completa = $this->crear_formulario( array( 'settings' => array( 'store_ip' => 'full' ) ) );
		$valores  = array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' );

		$envio_anonimo  = $this->envios->find( $this->enviar_y_guardar( $anonima, $valores ) );
		$envio_completo = $this->envios->find( $this->enviar_y_guardar( $completa, $valores ) );

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		unset( $_SERVER['HTTP_USER_AGENT'] );

		$this->assertSame( '203.0.113.0', $envio_anonimo->ip );
		$this->assertSame( 'Navegador de prueba', $envio_anonimo->user_agent );
		$this->assertSame( '203.0.113.77', $envio_completo->ip );
		$this->assertSame( '', $envio_completo->user_agent );
	}

	public function test_la_anonimizacion_recorta_ipv4_e_ipv6() {
		$anonimizador = new Ip_Anonymizer();

		$this->assertSame( '198.51.100.0', $anonimizador->anonymize( '198.51.100.213' ) );
		$this->assertSame( '2001:db8:abcd::', $anonimizador->anonymize( '2001:db8:abcd:12:34:56:78:9a' ) );
		$this->assertSame( '', $anonimizador->anonymize( 'no-es-una-ip' ) );
	}

	public function test_un_formulario_puede_no_guardar_sus_envios() {
		$formulario = $this->crear_formulario( array( 'settings' => array( 'store_enabled' => false ) ) );

		$this->assertSame( 200, $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) )->get_status() );
		$this->assertSame( array(), $this->envios->ids_before( $formulario, '', 10 ) );
		$this->assertNotFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'La notificación sí se envía.' );
	}

	public function test_los_envios_solo_los_ve_quien_tiene_la_capability() {
		$formulario = $this->crear_formulario();
		$id         = $this->enviar_y_guardar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );

		$rutas = array(
			array( 'GET', '/submissions' ),
			array( 'GET', "/submissions/$id" ),
			array( 'DELETE', "/submissions/$id" ),
			array( 'POST', '/submissions/delete', array( 'ids' => array( $id ) ) ),
			array( 'GET', '/deliveries' ),
			array( 'POST', '/deliveries/1/retry' ),
			array( 'POST', '/deliveries/1/dismiss' ),
		);

		wp_set_current_user( 0 );
		foreach ( $rutas as $ruta ) {
			$this->assertSame( 401, $this->rest( $ruta[0], $ruta[1], $ruta[2] ?? array() )->get_status(), "$ruta[0] $ruta[1] sin sesión" );
		}

		// Gestionar formularios no da acceso a los datos personales de los envíos.
		$gestor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $gestor )->add_cap( Capabilities::MANAGE_FORMS );
		wp_set_current_user( $gestor );

		foreach ( $rutas as $ruta ) {
			$this->assertSame( 403, $this->rest( $ruta[0], $ruta[1], $ruta[2] ?? array() )->get_status(), "$ruta[0] $ruta[1] sin capability" );
		}

		$this->assertArrayNotHasKey( 'submission_count', $this->rest( 'GET', '/forms' )->get_data()[0] );
		$this->assertNotNull( $this->envios->find( $id ), 'Nada se ha borrado.' );

		// Sobre el usuario en curso: WordPress no relee sus capabilities si ya es el actual.
		wp_get_current_user()->add_cap( Capabilities::VIEW_SUBMISSIONS );

		$this->assertSame( 200, $this->rest( 'GET', "/submissions/$id" )->get_status() );
		$this->assertSame( 1, $this->rest( 'GET', '/forms' )->get_data()[0]['submission_count'] );
	}

	public function test_el_listado_filtra_por_formulario_busca_y_pagina() {
		$contacto    = $this->crear_formulario();
		$presupuesto = $this->crear_formulario( array(), 'Presupuesto' );

		$this->guardar( $contacto, time() - 300, array( 'nombre' => 'Ana Pérez', 'mensaje' => 'Quiero información' ), 'AAAA-2222' );
		$this->guardar( $contacto, time() - 200, array( 'nombre' => 'Luis Gómez', 'mensaje' => 'Tengo un 100% de dudas' ), 'BBBB-3333' );
		$this->guardar( $presupuesto, time() - 100, array( 'nombre' => 'Marta Ruiz' ), 'CCCC-4444' );

		$this->como_administrador();

		$todos = $this->rest( 'GET', '/submissions' );

		$this->assertSame( array( 'CCCC-4444', 'BBBB-3333', 'AAAA-2222' ), array_column( $todos->get_data(), 'reference' ), 'Del más reciente al más antiguo.' );
		$this->assertSame( '3', $todos->get_headers()['X-WP-Total'] );
		$this->assertSame( 'Presupuesto', $todos->get_data()[0]['form_title'] );
		$this->assertSame( 'Luis Gómez · Tengo un 100% de dudas', $todos->get_data()[1]['summary'] );

		$this->assertSame( array( 'BBBB-3333', 'AAAA-2222' ), array_column( $this->rest( 'GET', '/submissions', array( 'form' => $contacto ) )->get_data(), 'reference' ) );
		$this->assertSame( array( 'AAAA-2222' ), array_column( $this->rest( 'GET', '/submissions', array( 'search' => 'pérez' ) )->get_data(), 'reference' ) );
		$this->assertSame( array( 'CCCC-4444' ), array_column( $this->rest( 'GET', '/submissions', array( 'search' => 'cccc-44' ) )->get_data(), 'reference' ) );
		// Los comodines de LIKE se buscan como texto, no como comodines.
		$this->assertSame( array( 'BBBB-3333' ), array_column( $this->rest( 'GET', '/submissions', array( 'search' => '100%' ) )->get_data(), 'reference' ) );
		$this->assertSame( array( 'BBBB-3333' ), array_column( $this->rest( 'GET', '/submissions', array( 'search' => '%' ) )->get_data(), 'reference' ) );
		$this->assertSame( array(), $this->rest( 'GET', '/submissions', array( 'search' => '____' ) )->get_data() );

		$pagina = $this->rest( 'GET', '/submissions', array( 'per_page' => 2, 'page' => 2 ) );

		$this->assertSame( array( 'AAAA-2222' ), array_column( $pagina->get_data(), 'reference' ) );
		$this->assertSame( '2', $pagina->get_headers()['X-WP-TotalPages'] );
	}

	public function test_la_busqueda_no_permite_inyectar_sql() {
		$formulario = $this->crear_formulario();
		$this->guardar( $formulario, time(), array( 'nombre' => 'Ana' ) );
		$this->como_administrador();

		$respuesta = $this->rest( 'GET', '/submissions', array( 'search' => "' OR 1=1 -- " ) );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertSame( array(), $respuesta->get_data() );
		$this->assertCount( 1, $this->rest( 'GET', '/submissions' )->get_data(), 'La tabla sigue intacta.' );
	}

	public function test_el_detalle_devuelve_los_datos_tal_como_llegaron_sin_interpretarlos() {
		$formulario = $this->crear_formulario();
		$id         = $this->enviar_y_guardar( $formulario, array( 'nombre' => 'Ana "la <b>jefa</b>" & Cía', 'correo' => 'ana@example.org', 'mensaje' => '=HYPERLINK("http://malo.example")' ) );

		$this->como_administrador();
		$detalle = $this->rest( 'GET', "/submissions/$id" )->get_data();

		// El saneado quita las etiquetas HTML; lo demás viaja como texto y lo escapa quien lo pinta.
		$this->assertSame( 'Ana "la jefa" & Cía', $detalle['entries'][0]['value'] );
		$this->assertSame( '=HYPERLINK("http://malo.example")', $detalle['entries'][2]['value'] );
		$this->assertSame( 'Contacto', $detalle['form_title'] );
		$this->assertCount( 1, $detalle['deliveries'] );
		$this->assertSame( 'success', $detalle['deliveries'][0]['status'] );
		$this->assertSame( 404, $this->rest( 'GET', '/submissions/999999' )->get_status() );
	}

	public function test_borrar_envios_borra_tambien_su_registro_de_entregas() {
		$formulario = $this->crear_formulario();
		$valores    = array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' );
		$uno        = $this->enviar_y_guardar( $formulario, $valores );
		$dos        = $this->enviar_y_guardar( $formulario, $valores );
		$tres       = $this->enviar_y_guardar( $formulario, $valores );
		$borrados   = array();

		add_action(
			'formulario_mensaje_submissions_deleted',
			static function ( array $ids ) use ( &$borrados ) {
				$borrados = array_merge( $borrados, $ids );
			}
		);

		$this->como_administrador();

		$this->assertSame( 1, $this->rest( 'DELETE', "/submissions/$uno" )->get_data()['deleted'] );
		$this->assertSame( 404, $this->rest( 'DELETE', "/submissions/$uno" )->get_status() );
		$this->assertSame( 1, $this->rest( 'POST', '/submissions/delete', array( 'ids' => array( $dos, 999999, 0 ) ) )->get_data()['deleted'] );

		$this->assertNull( $this->envios->find( $uno ) );
		$this->assertNull( $this->envios->find( $dos ) );
		$this->assertNotNull( $this->envios->find( $tres ) );
		$this->assertSame( array(), $this->entregas->for_submission( $uno ) );
		$this->assertCount( 1, $this->entregas->for_submission( $tres ) );
		$this->assertContains( $uno, $borrados );
		$this->assertContains( $dos, $borrados );
	}

	public function test_un_fallo_de_correo_queda_registrado_se_cuenta_y_se_puede_reintentar() {
		$formulario = $this->crear_formulario();

		add_filter( 'pre_wp_mail', '__return_false' );
		$id = $this->enviar_y_guardar( $formulario, array( 'nombre' => 'Ana Secreta', 'correo' => 'ana@example.org' ) );
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->como_administrador();

		$detalle = $this->rest( 'GET', "/submissions/$id" )->get_data();
		$entrega = $detalle['deliveries'][0];

		$this->assertSame( 1, $detalle['failed_actions'] );
		$this->assertSame( 'failed', $entrega['status'] );
		$this->assertTrue( $entrega['can_retry'] );
		$this->assertStringNotContainsString( 'Ana Secreta', $entrega['error'], 'El registro de fallos no guarda datos del envío.' );
		$this->assertSame( 1, $this->contador()->total() );
		$this->assertSame( array( $id ), array_column( $this->rest( 'GET', '/submissions', array( 'failed' => true ) )->get_data(), 'id' ) );
		$this->assertSame( array( $entrega['id'] ), array_column( $this->rest( 'GET', '/deliveries' )->get_data(), 'id' ) );

		// El correo vuelve a funcionar: el reintento lo entrega con los datos guardados.
		reset_phpmailer_instance();
		$reintento = $this->rest( 'POST', "/deliveries/{$entrega['id']}/retry" );
		$correo    = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 200, $reintento->get_status() );
		$this->assertSame( 'success', $reintento->get_data()['status'] );
		$this->assertSame( 2, $reintento->get_data()['attempts'] );
		$this->assertStringContainsString( 'Nombre: Ana Secreta', $correo->body );
		$this->assertSame( 0, $this->contador()->total() );
		$this->assertSame( 0, $this->envios->find( $id )->failed_actions );
		$this->assertSame( 404, $this->rest( 'POST', "/deliveries/{$entrega['id']}/retry" )->get_status(), 'Una entrega correcta no se reintenta.' );
	}

	public function test_un_reintento_que_vuelve_a_fallar_sigue_pendiente_y_se_puede_descartar() {
		$formulario = $this->crear_formulario();

		add_filter( 'pre_wp_mail', '__return_false' );
		$id = $this->enviar_y_guardar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );

		$this->como_administrador();
		$entrega   = $this->rest( 'GET', "/submissions/$id" )->get_data()['deliveries'][0]['id'];
		$reintento = $this->rest( 'POST', "/deliveries/$entrega/retry" )->get_data();
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->assertSame( 'failed', $reintento['status'] );
		$this->assertSame( 2, $reintento['attempts'] );
		$this->assertSame( 1, $this->contador()->total() );

		$this->assertSame( 200, $this->rest( 'POST', "/deliveries/$entrega/dismiss" )->get_status() );
		$this->assertSame( 0, $this->contador()->total() );
		$this->assertSame( 0, $this->envios->find( $id )->failed_actions );
		$this->assertSame( 404, $this->rest( 'POST', "/deliveries/$entrega/dismiss" )->get_status() );
	}

	public function test_los_fallos_de_formularios_que_no_guardan_envios_se_cuentan_pero_no_se_reintentan() {
		$formulario = $this->crear_formulario(
			array(
				'settings' => array( 'store_enabled' => false ),
				'actions'  => array(
					array( 'id' => 'bien', 'type' => 'email', 'enabled' => true, 'settings' => array( 'to' => array( 'ventas@example.org' ), 'subject' => 's', 'body' => 'b' ) ),
					array( 'id' => 'mal', 'type' => 'email', 'enabled' => true, 'settings' => array( 'to' => array(), 'subject' => 's', 'body' => 'b' ) ),
				),
			)
		);

		$this->assertSame( 200, $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) )->get_status() );

		$this->como_administrador();
		$fallos = $this->rest( 'GET', '/deliveries' )->get_data();

		$this->assertCount( 1, $fallos, 'Solo se anota la acción que falló.' );
		$this->assertSame( 0, $fallos[0]['submission_id'] );
		$this->assertFalse( $fallos[0]['can_retry'] );
		$this->assertSame( 404, $this->rest( 'POST', "/deliveries/{$fallos[0]['id']}/retry" )->get_status() );
	}

	public function test_la_retencion_borra_los_envios_antiguos_de_cada_formulario_segun_sus_dias() {
		$con_retencion = $this->crear_formulario( array( 'settings' => array( 'retention_days' => 30 ) ) );
		$sin_retencion = $this->crear_formulario( array(), 'Archivo' );
		$ahora         = time();

		$antiguo  = $this->guardar( $con_retencion, $ahora - 31 * DAY_IN_SECONDS );
		$reciente = $this->guardar( $con_retencion, $ahora - 29 * DAY_IN_SECONDS );
		$eterno   = $this->guardar( $sin_retencion, $ahora - 400 * DAY_IN_SECONDS );

		$reloj     = new Reloj_Falso( $ahora );
		$retencion = new Retention_Service( new Post_Form_Repository(), $this->servicio( $reloj ), $this->envios, $this->entregas, $this->contador(), new Ajustes_En_Memoria(), $reloj );

		$this->assertSame( 1, $retencion->purge() );
		$this->assertNull( $this->envios->find( $antiguo ) );
		$this->assertNotNull( $this->envios->find( $reciente ) );
		$this->assertNotNull( $this->envios->find( $eterno ) );
		$this->assertSame( 0, $retencion->purge(), 'Repetir la tarea no borra nada más.' );

		// Un formulario en la papelera mantiene su retención.
		wp_trash_post( $con_retencion );
		$reloj->avanzar( 2 * DAY_IN_SECONDS );

		$this->assertSame( 1, $retencion->purge() );
		$this->assertNull( $this->envios->find( $reciente ) );
	}

	public function test_la_tarea_diaria_aplica_la_retencion_y_se_puede_lanzar_a_mano() {
		$formulario = $this->crear_formulario( array( 'settings' => array( 'retention_days' => 1 ) ) );
		$antiguo    = $this->guardar( $formulario, time() - 3 * DAY_IN_SECONDS );

		$this->como( 'editor' );
		$this->assertSame( 403, $this->rest( 'POST', '/maintenance' )->get_status() );
		$this->assertNotNull( $this->envios->find( $antiguo ) );

		$this->como_administrador();
		$this->assertTrue( $this->rest( 'POST', '/maintenance' )->get_data()['done'] );
		$this->assertNull( $this->envios->find( $antiguo ) );

		$programacion = new Maintenance_Schedule();
		$programacion->ensure();
		$this->assertNotFalse( wp_next_scheduled( Maintenance_Schedule::HOOK ) );
		$programacion->clear();
		$this->assertFalse( wp_next_scheduled( Maintenance_Schedule::HOOK ) );
	}

	public function test_la_exportacion_neutraliza_formulas_y_une_las_columnas_de_todos_los_envios() {
		$formulario = $this->crear_formulario();
		$ahora      = time();

		$this->guardar( $formulario, $ahora - 200, array( 'nombre' => '=cmd|/C calc!A0', 'telefono' => '+34 600 111 222' ), 'AAAA-2222' );
		$this->guardar( $formulario, $ahora - 100, array( 'nombre' => 'Ana', 'empresa' => '@Cía' ), 'BBBB-3333' );
		$this->guardar( $this->crear_formulario( array(), 'Otro' ), $ahora, array( 'nombre' => 'De otro formulario' ), 'CCCC-4444' );

		$filas = iterator_to_array( ( new Csv_Exporter( $this->envios ) )->rows( $formulario ), false );

		$this->assertSame( array( 'Reference', 'Date', 'Page', 'Nombre', 'Empresa', 'Telefono' ), $filas[0] );
		$this->assertCount( 3, $filas, 'Cabecera y los dos envíos de este formulario.' );
		$this->assertSame( array( 'BBBB-3333', 'Ana', "'@Cía", '' ), array( $filas[1][0], $filas[1][3], $filas[1][4], $filas[1][5] ) );
		$this->assertSame( array( 'AAAA-2222', "'=cmd|/C calc!A0", '', "'+34 600 111 222" ), array( $filas[2][0], $filas[2][3], $filas[2][4], $filas[2][5] ) );

		$exportador = new Csv_Exporter( $this->envios );

		foreach ( array( '=1+1', '+1', '-1', '@x', "\tx", "\rx" ) as $peligrosa ) {
			$this->assertSame( "'" . $peligrosa, $exportador->neutralize( $peligrosa ) );
		}

		$this->assertSame( 'Normal', $exportador->neutralize( 'Normal' ) );
	}

	public function test_la_exportacion_filtra_por_fechas() {
		$formulario = $this->crear_formulario();
		$base       = strtotime( '2026-06-15 12:00:00 UTC' );

		$this->guardar( $formulario, $base - 10 * DAY_IN_SECONDS, array( 'nombre' => 'Antes' ), 'AAAA-2222' );
		$this->guardar( $formulario, $base, array( 'nombre' => 'Dentro' ), 'BBBB-3333' );
		$this->guardar( $formulario, $base + 10 * DAY_IN_SECONDS, array( 'nombre' => 'Después' ), 'CCCC-4444' );

		$filas = iterator_to_array( ( new Csv_Exporter( $this->envios ) )->rows( $formulario, '2026-06-14 00:00:00', '2026-06-16 23:59:59' ), false );

		$this->assertSame( array( 'BBBB-3333' ), array_column( array_slice( $filas, 1 ), 0 ) );
	}

	public function test_la_descarga_de_la_exportacion_exige_capability_y_nonce() {
		$formulario  = $this->crear_formulario();
		$controlador = new Export_Controller( new Csv_Exporter( $this->envios ), new Post_Form_Repository() );

		$_GET = array( 'form' => (string) $formulario, '_wpnonce' => wp_create_nonce( Export_Controller::ACTION ) );

		$this->como( 'editor' );

		try {
			$controlador->handle();
			$this->fail( 'Sin la capability debe detenerse.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		}

		$this->como_administrador();
		$_GET     = array( 'form' => (string) $formulario, '_wpnonce' => 'nonce-falso' );
		$_REQUEST = $_GET;

		try {
			$controlador->handle();
			$this->fail( 'Con un nonce no válido debe detenerse.' );
		} catch ( WPDieException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		$_GET     = array();
		$_REQUEST = array();
	}

	public function test_borrar_un_formulario_definitivamente_puede_borrar_tambien_sus_envios() {
		$conserva = $this->crear_formulario();
		$borra    = $this->crear_formulario( array(), 'Temporal' );
		$queda    = $this->guardar( $conserva, time() );
		$huerfano = $this->guardar( $borra, time() );

		$this->como_administrador();

		$this->rest( 'DELETE', "/forms/$conserva", array( 'force' => true ) );
		$this->assertNotNull( $this->envios->find( $queda ), 'Por defecto los envíos se conservan.' );

		$otro = $this->guardar( $borra, time() );
		$this->rest( 'DELETE', "/forms/$borra", array( 'force' => true, 'delete_submissions' => true ) );

		$this->assertNull( $this->envios->find( $huerfano ) );
		$this->assertNull( $this->envios->find( $otro ) );
	}

	public function test_desinstalar_conserva_los_datos_salvo_que_se_pida_borrarlos() {
		global $wpdb;

		$formulario   = $this->crear_formulario();
		$capabilities = new Capabilities();
		$capabilities->grant_to_administrator();
		$desinstalar  = new Uninstaller( $capabilities, $this->esquema(), new Maintenance_Schedule(), new Queue_Schedule( new Reloj_Falso() ) );

		update_option( Option_Settings_Repository::OPTION, array( 'delete_data_on_uninstall' => false ) );
		$desinstalar->uninstall();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_FORMS ) );
		$this->assertFalse( wp_next_scheduled( Maintenance_Schedule::HOOK ) );
		$this->assertSame( Form_Post_Type::NAME, get_post_type( $formulario ), 'Los formularios se conservan.' );
		$this->assertIsArray( get_option( Option_Settings_Repository::OPTION ) );

		update_option( Option_Settings_Repository::OPTION, array( 'delete_data_on_uninstall' => true ) );
		wp_trash_post( $formulario );
		$desinstalar->uninstall();

		$this->assertNull( get_post( $formulario ), 'También se borran los formularios de la papelera.' );
		$this->assertFalse( get_option( Option_Settings_Repository::OPTION ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s', $wpdb->postmeta, Post_Form_Repository::DEFINITION_META ) ) );
	}
}
