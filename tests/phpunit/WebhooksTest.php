<?php
/**
 * Webhooks y cola en segundo plano: envío aplazado, firma, reintentos, avisos y purga del registro.
 */

use Formulario_Mensaje\Alerts\Failure_Alert;
use Formulario_Mensaje\Forms\Post_Form_Repository;
use Formulario_Mensaje\Plugin;
use Formulario_Mensaje\Queue\Queue_Schedule;
use Formulario_Mensaje\Queue\Retry_Policy;
use Formulario_Mensaje\Queue\Wpdb_Delivery_Queue;
use Formulario_Mensaje\Secrets\Secret_Cipher;
use Formulario_Mensaje\Settings\Option_Settings_Repository;
use Formulario_Mensaje\Settings\Settings;
use Formulario_Mensaje\Storage\Delivery;
use Formulario_Mensaje\Storage\Delivery_Status;
use Formulario_Mensaje\Storage\Failure_Counter;
use Formulario_Mensaje\Storage\Retention_Service;
use Formulario_Mensaje\Storage\Submission_Service;
use Formulario_Mensaje\Storage\Wpdb_Delivery_Repository;
use Formulario_Mensaje\Storage\Wpdb_Submission_Repository;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Submissions\Submission_Entry;
use Formulario_Mensaje\Webhooks\Destination_Guard;
use Formulario_Mensaje\Webhooks\Webhook_Payload_Builder;
use Formulario_Mensaje\Webhooks\Webhook_Sender;
use Formulario_Mensaje\Webhooks\Webhook_Signer;

class WebhooksTest extends Caso_Formulario {

	private const DESTINO = 'https://hooks.example.org/entrada';

	/** Peticiones HTTP que el plugin ha intentado hacer. */
	private array $peticiones = array();

	/** Código con el que responde el destino simulado. */
	private int $codigo = 200;

	public function set_up() {
		parent::set_up();

		$this->peticiones = array();
		$this->codigo     = 200;

		add_filter(
			'pre_http_request',
			function ( $respuesta, array $argumentos, string $url ) {
				$this->peticiones[] = array( 'url' => $url, 'argumentos' => $argumentos );

				return array( 'response' => array( 'code' => $this->codigo, 'message' => '' ), 'body' => '{"recibido":true}', 'headers' => array(), 'cookies' => array() );
			},
			10,
			3
		);
	}

	/** Acción de webhook para la definición de un formulario. */
	private function webhook( array $ajustes = array(), array $extra = array() ): array {
		return array_merge(
			array( 'id' => 'wh', 'type' => 'webhook', 'enabled' => true, 'settings' => array_merge( array( 'url' => self::DESTINO ), $ajustes ) ),
			$extra
		);
	}

	/** Procesa la cola como lo hace WP-Cron, que retira el evento antes de lanzarlo. */
	private function procesar_cola(): void {
		wp_clear_scheduled_hook( Queue_Schedule::HOOK );
		do_action( Queue_Schedule::HOOK );
	}

	/** Hace que a las entregas pendientes les toque ya, como si hubiera pasado el tiempo. */
	private function adelantar_cola(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_attempt_at = %s WHERE status = %s', $this->esquema()->deliveries_table(), '2000-01-01 00:00:00', 'pending' ) );
	}

	/** Entregas del único envío guardado, leídas por REST como administrador. */
	private function entregas(): array {
		$this->como_administrador();

		$envio = $this->rest( 'GET', '/submissions' )->get_data()[0]['id'];

		return $this->rest( 'GET', "/submissions/$envio" )->get_data()['deliveries'];
	}

	public function test_el_webhook_no_hace_esperar_al_visitante_queda_en_cola_y_se_envia_despues() {
		$formulario = $this->crear_formulario( array( 'actions' => array( $this->webhook() ) ) );

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org', 'mensaje' => "Hola\nmundo" ) );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertSame( array(), $this->peticiones, 'El envío no espera al destino.' );
		$this->assertNotFalse( wp_next_scheduled( Queue_Schedule::HOOK ), 'Queda programado el procesado de la cola.' );

		$entrega = $this->entregas()[0];
		$this->assertSame( 'pending', $entrega['status'] );
		$this->assertSame( 0, $entrega['attempts'] );
		$this->assertSame( 'Webhook', $entrega['action_label'] );
		$this->assertFalse( $entrega['can_retry'] );

		wp_set_current_user( 0 );
		$this->procesar_cola();

		$this->assertCount( 1, $this->peticiones );

		$peticion = $this->peticiones[0];
		$datos    = json_decode( $peticion['argumentos']['body'], true );

		$this->assertSame( self::DESTINO, $peticion['url'] );
		$this->assertSame( 'POST', $peticion['argumentos']['method'] );
		$this->assertTrue( $peticion['argumentos']['reject_unsafe_urls'], 'La petición va por la variante segura de la HTTP API.' );
		$this->assertSame( 0, $peticion['argumentos']['redirection'], 'No se siguen redirecciones.' );
		$this->assertSame( 'application/json; charset=utf-8', $peticion['argumentos']['headers']['Content-Type'] );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $peticion['argumentos']['headers']['X-Formulario-Mensaje-Reference'] );
		$this->assertArrayNotHasKey( 'X-Formulario-Mensaje-Signature', $peticion['argumentos']['headers'], 'Sin secreto no hay firma.' );
		$this->assertArrayNotHasKey( 'X-Formulario-Mensaje-Test', $peticion['argumentos']['headers'] );

		$this->assertSame( array( 'id' => $formulario, 'title' => 'Contacto' ), $datos['form'] );
		$this->assertSame( $peticion['argumentos']['headers']['X-Formulario-Mensaje-Reference'], $datos['reference'] );
		$this->assertSame( array( 'nombre' => 'Ana', 'correo' => 'ana@example.org', 'mensaje' => "Hola\nmundo" ), $datos['fields'] );
		$this->assertSame( 'Correo', $datos['labels']['correo'] );

		$entrega = $this->entregas()[0];
		$this->assertSame( 'success', $entrega['status'] );
		$this->assertSame( 1, $entrega['attempts'] );
		$this->assertNull( $entrega['next_attempt'] );

		// Volver a procesar la cola no repite un webhook entregado.
		$this->procesar_cola();
		$this->assertCount( 1, $this->peticiones );
	}

	public function test_el_secreto_de_firma_se_guarda_cifrado_y_la_firma_se_puede_verificar() {
		$formulario = $this->crear_formulario( array( 'actions' => array( $this->webhook( array( 'secret' => 'secreto-compartido' ) ) ) ) );

		$this->como_administrador();
		$guardado = $this->rest( 'GET', "/forms/$formulario" )->get_data();
		$cifrado  = $guardado['definition']['actions'][0]['settings']['secret'];

		$this->assertStringStartsWith( 'fm1:', $cifrado );
		$this->assertStringNotContainsString( 'secreto-compartido', wp_json_encode( $guardado ), 'El constructor nunca recibe el secreto.' );
		$this->assertStringNotContainsString( 'secreto-compartido', (string) get_post_meta( $formulario, '_formulario_mensaje_definition', true ) );

		// Guardar el formulario devolviendo el valor cifrado lo conserva; uno que no es de esta instalación se descarta.
		$this->rest( 'PUT', "/forms/$formulario", array( 'title' => 'Contacto', 'definition' => $guardado['definition'] ) );
		$this->assertSame( $cifrado, $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['actions'][0]['settings']['secret'] );

		$ajeno = $guardado['definition'];
		$ajeno['actions'][0]['settings']['secret'] = 'fm1:00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff0011223344556677';
		$otro = $this->rest( 'POST', '/forms', array( 'title' => 'Otro', 'definition' => $ajeno ) )->get_data();
		$this->assertSame( '', $otro['definition']['actions'][0]['settings']['secret'] );

		wp_set_current_user( 0 );
		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );
		$this->procesar_cola();

		$peticion = $this->peticiones[0]['argumentos'];
		$marca    = $peticion['headers']['X-Formulario-Mensaje-Timestamp'];

		$this->assertEqualsWithDelta( time(), (int) $marca, 5 );
		$this->assertSame(
			'sha256=' . hash_hmac( 'sha256', $marca . '.' . $peticion['body'], 'secreto-compartido' ),
			$peticion['headers']['X-Formulario-Mensaje-Signature']
		);
	}

	public function test_el_metodo_las_cabeceras_y_el_mapeo_se_sanean_y_se_aplican() {
		$formulario = $this->crear_formulario(
			array(
				'actions' => array(
					$this->webhook(
						array(
							'method'       => 'put',
							'headers'      => array(
								array( 'name' => 'Authorization', 'value' => "Bearer abc\r\nX-Inyectada: 1" ),
								array( 'name' => 'X-Origen', 'value' => 'web' ),
								array( 'name' => 'Host', 'value' => 'interno.local' ),
								array( 'name' => 'Content-Type', 'value' => 'text/plain' ),
								array( 'name' => 'X-Formulario-Mensaje-Signature', 'value' => 'sha256=falsa' ),
								array( 'name' => "Mala\r\nCabecera", 'value' => 'x' ),
								array( 'name' => 'Con Espacio', 'value' => 'x' ),
							),
							'payload_mode' => 'custom',
							'payload'      => array(
								array( 'key' => 'email', 'value' => '{field:correo}' ),
								array( 'key' => 'full_name', 'value' => 'Sr./Sra. {field:nombre}' ),
								array( 'key' => 'source', 'value' => 'web · {form_title}' ),
								array( 'key' => 'clave con espacios', 'value' => 'x' ),
								array( 'key' => '', 'value' => 'x' ),
							),
						)
					),
				),
			)
		);

		$this->como_administrador();
		$ajustes = $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['actions'][0]['settings'];

		$this->assertSame( 'PUT', $ajustes['method'] );
		$this->assertSame( array( 'Authorization', 'X-Origen' ), array_column( $ajustes['headers'], 'name' ) );
		$this->assertStringNotContainsString( "\n", $ajustes['headers'][0]['value'] );
		$this->assertSame( array( 'email', 'full_name', 'source' ), array_column( $ajustes['payload'], 'key' ) );

		wp_set_current_user( 0 );
		$this->enviar( $formulario, array( 'nombre' => 'Ana {field:correo}', 'correo' => 'ana@example.org' ) );
		$this->procesar_cola();

		$peticion = $this->peticiones[0]['argumentos'];

		$this->assertSame( 'PUT', $peticion['method'] );
		$this->assertSame( 'web', $peticion['headers']['X-Origen'] );
		$this->assertSame( 'application/json; charset=utf-8', $peticion['headers']['Content-Type'] );
		$this->assertSame(
			// Lo que escribe el visitante no se vuelve a interpretar como etiqueta.
			array( 'email' => 'ana@example.org', 'full_name' => 'Sr./Sra. Ana {field:correo}', 'source' => 'web · Contacto' ),
			json_decode( $peticion['body'], true )
		);
	}

	public function test_una_direccion_que_no_es_http_o_https_no_se_guarda() {
		$this->como_administrador();

		foreach ( array( 'file:///etc/passwd', 'ftp://example.org/x', 'javascript:alert(1)', 'gopher://127.0.0.1:25/', 'no es una url' ) as $direccion ) {
			$definicion = $this->definicion( array( 'actions' => array( $this->webhook( array( 'url' => $direccion ) ) ) ) );
			$guardado   = $this->rest( 'POST', '/forms', array( 'title' => 'x', 'definition' => $definicion ) )->get_data();

			$this->assertSame( '', $guardado['definition']['actions'][0]['settings']['url'], $direccion );
		}
	}

	public function test_un_destino_interno_se_rechaza_sin_hacer_la_peticion() {
		// Sin el destino simulado: decide la HTTP API de WordPress, como en producción.
		remove_all_filters( 'pre_http_request' );
		$this->como_administrador();

		foreach ( array( 'http://127.0.0.1:8080/admin', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'http://192.168.1.1/' ) as $direccion ) {
			$definicion = $this->definicion( array( 'actions' => array( $this->webhook( array( 'url' => $direccion ) ) ) ) );
			$respuesta  = $this->rest( 'POST', '/forms/test-webhook', array( 'definition' => $definicion, 'action_id' => 'wh' ) );

			$this->assertSame( 502, $respuesta->get_status(), $direccion );
			$this->assertSame( 'formulario_mensaje_webhook_failed', $respuesta->get_data()['code'], $direccion );
		}
	}

	public function test_un_webhook_que_falla_se_reintenta_y_al_agotarse_queda_como_fallo_y_se_avisa() {
		add_filter( 'formulario_mensaje_retry_delays', static fn (): array => array( 60, 300 ) );
		update_option( Option_Settings_Repository::OPTION, array( 'failure_alert_email' => 'jefa@example.org' ) );

		$formulario   = $this->crear_formulario( array( 'actions' => array( $this->webhook() ) ) );
		$this->codigo = 500;

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );
		$this->procesar_cola();

		$entrega = $this->entregas()[0];
		$this->assertSame( 'pending', $entrega['status'] );
		$this->assertSame( 1, $entrega['attempts'] );
		$this->assertStringContainsString( 'HTTP status 500', $entrega['error'] );
		$this->assertStringNotContainsString( 'recibido', $entrega['error'], 'La respuesta del destino no se anota.' );
		$this->assertEqualsWithDelta( time() + 60, strtotime( $entrega['next_attempt'] ), 5 );
		$this->assertEqualsWithDelta( time() + 60, wp_next_scheduled( Queue_Schedule::HOOK ), 5, 'El siguiente intento queda programado.' );
		$this->assertSame( 0, ( new Failure_Counter( $this->repositorio_entregas(), $this->repositorio_envios() ) )->total(), 'Mientras se reintenta no cuenta como fallo.' );

		// Todavía no le toca: procesar la cola no hace nada.
		$this->procesar_cola();
		$this->assertCount( 1, $this->peticiones );

		$this->adelantar_cola();
		$this->procesar_cola();
		$this->assertSame( 2, $this->entregas()[0]['attempts'] );
		$this->assertEqualsWithDelta( time() + 300, strtotime( $this->entregas()[0]['next_attempt'] ), 5 );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'Aún no se avisa a nadie.' );

		$this->adelantar_cola();
		$this->procesar_cola();

		$entrega = $this->entregas()[0];
		$this->assertCount( 3, $this->peticiones );
		$this->assertSame( 'failed', $entrega['status'] );
		$this->assertSame( 3, $entrega['attempts'] );
		$this->assertNull( $entrega['next_attempt'] );
		$this->assertTrue( $entrega['can_retry'] );
		$this->assertSame( 1, ( new Failure_Counter( $this->repositorio_entregas(), $this->repositorio_envios() ) )->total() );

		$aviso = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'jefa@example.org', $aviso->to[0][0] );
		$this->assertStringContainsString( '1 form action has failed', $aviso->subject );
		$this->assertStringContainsString( 'page=formulario-mensaje-submissions', $aviso->body );
		$this->assertStringNotContainsString( 'ana@example.org', $aviso->body, 'El aviso no lleva datos del envío.' );

		// Arreglado el destino, el reintento manual lo entrega en el momento.
		$this->codigo = 204;
		$reintento    = $this->rest( 'POST', "/deliveries/{$entrega['id']}/retry" )->get_data();

		$this->assertSame( 'success', $reintento['status'] );
		$this->assertSame( 4, $reintento['attempts'] );
		$this->assertCount( 4, $this->peticiones );
	}

	public function test_un_fallo_de_red_tambien_se_reintenta() {
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn () => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$formulario = $this->crear_formulario( array( 'actions' => array( $this->webhook() ) ) );

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );
		$this->procesar_cola();

		$entrega = $this->entregas()[0];
		$this->assertSame( 'pending', $entrega['status'] );
		$this->assertStringContainsString( 'timed out', $entrega['error'] );
	}

	public function test_un_formulario_que_no_guarda_sus_envios_tambien_entrega_el_webhook_y_no_deja_copia() {
		global $wpdb;

		$formulario = $this->crear_formulario( array( 'settings' => array( 'store_enabled' => false ), 'actions' => array( $this->webhook() ) ) );
		$tabla      = $this->esquema()->deliveries_table();

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );

		$this->assertSame( 200, $respuesta->get_status(), 'El envío se acepta: la cola lo tiene.' );
		$this->assertStringContainsString( 'ana@example.org', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE form_id = %d', $tabla, $formulario ) ) );

		$this->procesar_cola();

		$this->assertSame( 'ana@example.org', json_decode( $this->peticiones[0]['argumentos']['body'], true )['fields']['correo'] );
		$this->assertSame(
			array( 'status' => 'success', 'payload' => null ),
			$wpdb->get_row( $wpdb->prepare( 'SELECT status, payload FROM %i WHERE form_id = %d', $tabla, $formulario ), ARRAY_A ),
			'Entregado el webhook, la copia del envío desaparece.'
		);
	}

	public function test_la_copia_del_envio_tambien_se_borra_cuando_el_webhook_agota_sus_reintentos() {
		global $wpdb;

		add_filter( 'formulario_mensaje_retry_delays', '__return_empty_array' );

		$formulario   = $this->crear_formulario( array( 'settings' => array( 'store_enabled' => false ), 'actions' => array( $this->webhook() ) ) );
		$this->codigo = 503;

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );
		$this->procesar_cola();

		$this->assertSame(
			array( 'status' => 'failed', 'payload' => null ),
			$wpdb->get_row( $wpdb->prepare( 'SELECT status, payload FROM %i WHERE form_id = %d', $this->esquema()->deliveries_table(), $formulario ), ARRAY_A )
		);
	}

	public function test_un_webhook_con_condicion_solo_entra_en_la_cola_si_el_envio_la_cumple() {
		$condicion  = array( 'match' => 'all', 'rules' => array( array( 'field' => 'nombre', 'operator' => 'equals', 'value' => 'Ana' ) ) );
		$formulario = $this->crear_formulario( array( 'actions' => array( $this->webhook( array(), array( 'condition' => $condicion ) ) ) ) );

		$this->enviar( $formulario, array( 'nombre' => 'Luis', 'correo' => 'luis@example.org' ) );
		$this->procesar_cola();

		$this->assertSame( array(), $this->peticiones );
		$this->assertSame( array(), $this->entregas() );
	}

	public function test_un_webhook_desactivado_mientras_esperaba_en_la_cola_no_se_envia() {
		$formulario = $this->crear_formulario( array( 'actions' => array( $this->webhook() ) ) );

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );

		$this->como_administrador();
		$this->rest( 'PUT', "/forms/$formulario", array( 'title' => 'Contacto', 'definition' => $this->definicion( array( 'actions' => array( $this->webhook( array(), array( 'enabled' => false ) ) ) ) ) ) );

		$this->procesar_cola();

		$entrega = $this->entregas()[0];
		$this->assertSame( array(), $this->peticiones );
		$this->assertSame( 'failed', $entrega['status'], 'No se reintenta: desactivarlo es la forma de pararlo.' );
		$this->assertStringContainsString( 'formulario_mensaje_action_disabled', $entrega['error'] );
	}

	public function test_una_entrega_cuyo_proceso_muere_una_y_otra_vez_no_se_reintenta_sin_fin() {
		global $wpdb;

		// Un reintento como mucho: dos intentos en total.
		add_filter( 'formulario_mensaje_retry_delays', static fn (): array => array( 60 ) );

		$formulario = $this->crear_formulario( array( 'actions' => array( $this->webhook() ) ) );
		$tabla      = $this->esquema()->deliveries_table();

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );

		// El proceso del primer intento murió a medias: la entrega quedó reservada, con su intento contado y sin resultado.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts = 1 WHERE form_id = %d', $tabla, $formulario ) );
		$this->adelantar_cola();
		$this->codigo = 500;
		$this->procesar_cola();

		$this->assertCount( 1, $this->peticiones, 'Vencida la reserva, se vuelve a intentar.' );
		$this->assertSame( 'failed', $this->entregas()[0]['status'], 'Era el último intento que permite la política.' );

		// Si también el último intento muere a medias, la entrega no puede volver a la cola indefinidamente.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, attempts = 2, next_attempt_at = %s WHERE form_id = %d', $tabla, 'pending', '2000-01-01 00:00:00', $formulario ) );
		$this->procesar_cola();

		$entrega = $this->entregas()[0];

		$this->assertCount( 1, $this->peticiones, 'No se ejecuta otra vez.' );
		$this->assertSame( 'failed', $entrega['status'] );
		$this->assertSame( 3, $entrega['attempts'] );
		$this->assertStringContainsString( 'formulario_mensaje_delivery_interrupted', $entrega['error'] );
		$this->assertTrue( $entrega['can_retry'], 'Queda como cualquier fallo: se puede reintentar a mano o descartar.' );
	}

	public function test_una_entrega_de_la_cola_solo_la_reserva_un_proceso() {
		global $wpdb;

		$cola = new Wpdb_Delivery_Queue( $wpdb, $this->esquema(), $this->repositorio_entregas() );
		$id   = $cola->push( new Delivery( 0, 0, 7, 'wh', 'webhook', Delivery_Status::Pending, 0, '', 1000, 1000, 1000 ), '{"reference":"AAAA-BBBB","entries":[]}' );

		$this->assertNull( $cola->claim_next( 999, 300 ), 'Todavía no le toca.' );
		$this->assertSame( 1000, $cola->next_due() );

		$trabajo = $cola->claim_next( 1000, 300 );

		$this->assertSame( $id, $trabajo->delivery->id );
		$this->assertSame( 1, $trabajo->delivery->attempts, 'Reservar cuenta el intento.' );
		$this->assertSame( '{"reference":"AAAA-BBBB","entries":[]}', $trabajo->payload );
		$this->assertNull( $cola->claim_next( 1000, 300 ), 'Otro proceso no la ve mientras está reservada.' );
		$this->assertNull( $cola->claim_next( 1299, 300 ) );
		$this->assertNotNull( $cola->claim_next( 1300, 300 ), 'Si el proceso muere, vuelve a estar disponible al vencer la reserva.' );

		$cola->finish( $id, Delivery_Status::Success, '', 1400 );

		$this->assertNull( $cola->claim_next( 99999, 300 ) );
		$this->assertSame( 0, $cola->next_due() );
	}

	public function test_la_politica_de_reintentos_espera_cada_vez_mas_y_se_puede_filtrar() {
		$politica = new Retry_Policy();

		$this->assertSame( array( 60, 300, 1800, 7200, 28800, null ), array_map( array( $politica, 'delay_after' ), range( 1, 6 ) ) );

		add_filter( 'formulario_mensaje_retry_delays', static fn (): array => array( 10, 'no', 0, 20 ) );
		$this->assertSame( array( 10, 20, null ), array_map( array( $politica, 'delay_after' ), range( 1, 3 ) ) );
	}

	public function test_la_cola_mantiene_programado_solo_el_intento_mas_proximo() {
		$programa = new Queue_Schedule( new Reloj_Falso( time() ) );

		$programa->ensure( time() + 300 );
		$this->assertSame( time() + 300, wp_next_scheduled( Queue_Schedule::HOOK ) );

		// Uno más tardío no cambia nada; uno anterior sustituye al que había.
		$programa->ensure( time() + 900 );
		$this->assertSame( time() + 300, wp_next_scheduled( Queue_Schedule::HOOK ) );

		$programa->kick();
		$this->assertSame( time(), wp_next_scheduled( Queue_Schedule::HOOK ) );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn ( $eventos ): bool => isset( $eventos[ Queue_Schedule::HOOK ] ) ) );

		$programa->clear();
		$this->assertFalse( wp_next_scheduled( Queue_Schedule::HOOK ) );
	}

	public function test_el_destino_de_un_webhook_se_resuelve_una_vez_y_la_conexion_queda_fijada_a_esa_direccion() {
		$dns     = new Resolutor_Fijo( array( 'hooks.example.org' => '93.184.216.34' ) );
		$destino = new Destination_Guard( $dns );

		// El formato es el de una entrada de CURLOPT_RESOLVE: servidor, puerto y la dirección ya comprobada.
		$this->assertSame( 'hooks.example.org:443:93.184.216.34', $destino->pin( 'https://hooks.example.org/entrada' ) );
		$this->assertSame( 'hooks.example.org:80:93.184.216.34', $destino->pin( 'http://HOOKS.example.org/entrada?a=1' ) );
		$this->assertSame( 'hooks.example.org:8080:93.184.216.34', $destino->pin( 'https://hooks.example.org:8080/' ) );

		// Una dirección escrita como IP no tiene nombre que resolver: la valida el núcleo tal cual.
		$consultas = $dns->consultas;
		$this->assertSame( '', $destino->pin( 'https://203.0.114.7/entrada' ) );
		$this->assertSame( $consultas, $dns->consultas );
	}

	public function test_un_nombre_que_apunta_a_una_direccion_interna_o_que_no_se_resuelve_no_es_un_destino() {
		$dns     = new Resolutor_Fijo();
		$destino = new Destination_Guard( $dns );

		foreach ( array( '127.0.0.1', '10.0.0.5', '172.16.4.4', '192.168.1.20', '169.254.169.254', '0.0.0.0' ) as $interna ) {
			$dns->direcciones = array( 'rebote.example.org' => $interna );
			$error            = $destino->pin( 'https://rebote.example.org/' );

			$this->assertWPError( $error, $interna );
			$this->assertSame( 'formulario_mensaje_webhook_internal', $error->get_error_code() );
		}

		$this->assertSame( 'formulario_mensaje_webhook_unresolved', $destino->pin( 'https://no-existe.example.org/' )->get_error_code() );

		// Un sitio puede permitir un servidor de su red por su nombre con el filtro del núcleo: se
		// respeta, y la conexión queda fijada igualmente a la dirección comprobada.
		$permitir = static fn ( bool $externo, string $servidor ): bool => $externo || 'n8n.interno.example' === $servidor;
		add_filter( 'http_request_host_is_external', $permitir, 10, 2 );

		$dns->direcciones = array( 'n8n.interno.example' => '10.0.0.5', 'rebote.example.org' => '10.0.0.6' );

		$this->assertSame( 'n8n.interno.example:443:10.0.0.5', $destino->pin( 'https://n8n.interno.example/webhook' ) );
		$this->assertWPError( $destino->pin( 'https://rebote.example.org/' ) );

		remove_filter( 'http_request_host_is_external', $permitir, 10 );

		// El propio sitio siempre es un destino, apunte adonde apunte, igual que para el núcleo.
		$propio           = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$dns->direcciones = array( $propio => '127.0.0.1' );

		$this->assertSame( $propio . ':80:127.0.0.1', $destino->pin( 'http://' . $propio . '/wp-json/' ) );

		$dns->direcciones = array();
		$this->assertSame( '', $destino->pin( 'http://' . $propio . '/wp-json/' ), 'Si no se resuelve, se deja al servidor llegar como pueda.' );
	}

	public function test_el_envio_de_un_webhook_se_corta_si_su_nombre_apunta_a_la_red_interna() {
		$dns    = new Resolutor_Fijo( array( 'hooks.example.org' => '169.254.169.254' ) );
		$emisor = new Webhook_Sender(
			Plugin::service( Webhook_Payload_Builder::class ),
			new Webhook_Signer(),
			new Secret_Cipher(),
			new Reloj_Falso(),
			new Destination_Guard( $dns ),
			'0.0.0'
		);
		$envio  = new Submission( 7, 'ABCD-2345', 0, '', array( 'nombre' => new Submission_Entry( 'nombre', 'text', 'Nombre', 'Ana', 'Ana' ) ) );
		$form   = ( new Post_Form_Repository() )->find( $this->crear_formulario() );

		// Con un doble que responde antes, no va a haber conexión: ni se consulta el DNS.
		$respuesta = $emisor->send( array( 'url' => self::DESTINO ), $envio, $form, true );

		$this->assertSame( 200, $respuesta->status_code );
		$this->assertSame( '{"recibido":true}', $respuesta->body );
		$this->assertSame( 0, $dns->consultas );

		// Sin nadie que responda antes, la petición iría a la red: se corta con su error y no sale nada.
		remove_all_filters( 'pre_http_request' );

		$error = $emisor->send( array( 'url' => self::DESTINO ), $envio, $form, true );

		$this->assertWPError( $error );
		$this->assertSame( 'formulario_mensaje_webhook_failed', $error->get_error_code() );
		$this->assertStringContainsString( 'internal network address', $error->get_error_message() );
		$this->assertSame( 1, $dns->consultas, 'El nombre se resuelve una sola vez.' );
		$this->assertFalse( has_filter( 'pre_http_request' ), 'El control solo dura lo que la petición.' );
		$this->assertFalse( has_action( 'http_api_curl' ) );
	}

	public function test_el_webhook_de_prueba_devuelve_la_respuesta_del_destino_y_no_pasa_por_la_cola() {
		$this->como_administrador();

		$definicion = $this->definicion( array( 'actions' => array( $this->webhook( array( 'secret' => 'clave' ) ) ) ) );
		$respuesta  = $this->rest( 'POST', '/forms/test-webhook', array( 'title' => 'En edición', 'definition' => $definicion, 'action_id' => 'wh' ) );
		$datos      = $respuesta->get_data();
		$peticion   = $this->peticiones[0]['argumentos'];

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertTrue( $datos['success'] );
		$this->assertSame( 200, $datos['status'] );
		$this->assertSame( '{"recibido":true}', $datos['body'] );
		$this->assertIsInt( $datos['duration_ms'] );
		$this->assertSame( '1', $peticion['headers']['X-Formulario-Mensaje-Test'] );
		$this->assertArrayHasKey( 'X-Formulario-Mensaje-Signature', $peticion['headers'], 'El secreto recién escrito firma la prueba.' );
		$this->assertSame( 'name@example.com', json_decode( $peticion['body'], true )['fields']['correo'] );
		$this->assertFalse( wp_next_scheduled( Queue_Schedule::HOOK ) );

		// Un código de error del destino no es un fallo de la prueba: se muestra.
		$this->codigo = 401;
		$rechazo      = $this->rest( 'POST', '/forms/test-webhook', array( 'definition' => $definicion, 'action_id' => 'wh' ) )->get_data();
		$this->assertFalse( $rechazo['success'] );
		$this->assertSame( 401, $rechazo['status'] );

		$this->assertSame( 404, $this->rest( 'POST', '/forms/test-webhook', array( 'definition' => $definicion, 'action_id' => 'aviso' ) )->get_status() );

		$sin_destino = $this->definicion( array( 'actions' => array( $this->webhook( array( 'url' => '' ) ) ) ) );
		$this->assertSame( 'formulario_mensaje_webhook_not_configured', $this->rest( 'POST', '/forms/test-webhook', array( 'definition' => $sin_destino, 'action_id' => 'wh' ) )->get_data()['code'] );

		$this->como( 'editor' );
		$this->assertSame( 403, $this->rest( 'POST', '/forms/test-webhook', array( 'definition' => $definicion, 'action_id' => 'wh' ) )->get_status() );
	}

	public function test_los_webhooks_de_prueba_tienen_un_limite_por_usuario() {
		$this->como_administrador();

		$definicion = $this->definicion( array( 'actions' => array( $this->webhook() ) ) );

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 200, $this->rest( 'POST', '/forms/test-webhook', array( 'definition' => $definicion, 'action_id' => 'wh' ) )->get_status() );
		}

		$this->assertSame( 429, $this->rest( 'POST', '/forms/test-webhook', array( 'definition' => $definicion, 'action_id' => 'wh' ) )->get_status() );
		$this->assertCount( 20, $this->peticiones );
	}

	public function test_los_datos_del_webhook_se_pueden_filtrar_sin_ver_el_secreto() {
		$recibido = null;

		add_filter(
			'formulario_mensaje_webhook_payload',
			static function ( array $datos, Submission $envio, $formulario, array $ajustes ) use ( &$recibido ): array {
				$recibido = $ajustes;

				return array( 'ref' => $envio->reference, 'total' => count( $datos['fields'] ) );
			},
			10,
			4
		);

		$formulario = $this->crear_formulario( array( 'actions' => array( $this->webhook( array( 'secret' => 'clave' ) ) ) ) );

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );
		$this->procesar_cola();

		$this->assertSame( array( 'ref', 'total' ), array_keys( json_decode( $this->peticiones[0]['argumentos']['body'], true ) ) );
		$this->assertArrayNotHasKey( 'secret', $recibido );
		$this->assertSame( self::DESTINO, $recibido['url'] );
	}

	public function test_una_notificacion_que_falla_avisa_al_administrador_y_los_avisos_se_agrupan() {
		$reloj      = new Reloj_Falso();
		$ajustes    = new Ajustes_En_Memoria( new Settings( failure_alert_email: 'avisos@example.org' ) );
		$transporte = new Transporte_Espia();
		$reserva    = new Transporte_Espia();
		$aviso      = new Failure_Alert( $ajustes, $transporte, $reserva, $reloj );

		$aviso->report();
		$this->assertCount( 1, $transporte->enviados, 'El primer fallo avisa en el momento.' );
		$this->assertSame( array( 'avisos@example.org' ), $transporte->enviados[0]->to );

		// Los siguientes se acumulan hasta que pasa el tiempo mínimo entre avisos.
		$aviso->report();
		$aviso->report();
		$aviso->flush();
		$this->assertCount( 1, $transporte->enviados );

		$reloj->avanzar( 12 * HOUR_IN_SECONDS );
		$aviso->flush();
		$this->assertCount( 2, $transporte->enviados );
		$this->assertStringContainsString( '2 form actions have failed', $transporte->enviados[1]->subject );

		// Sin fallos nuevos no hay nada que avisar.
		$reloj->avanzar( 24 * HOUR_IN_SECONDS );
		$aviso->flush();
		$this->assertCount( 2, $transporte->enviados );
		$this->assertSame( array(), $reserva->enviados );
	}

	public function test_el_aviso_sale_por_el_correo_de_wordpress_si_falla_el_transporte_del_plugin() {
		$transporte        = new Transporte_Espia();
		$transporte->falla = true;
		$reserva           = new Transporte_Espia();

		( new Failure_Alert( new Ajustes_En_Memoria(), $transporte, $reserva, new Reloj_Falso() ) )->report();

		$this->assertCount( 1, $reserva->enviados );
		$this->assertSame( array( get_option( 'admin_email' ) ), $reserva->enviados[0]->to );
	}

	public function test_con_los_avisos_desactivados_no_se_envia_nada_ni_se_acumula() {
		$reloj      = new Reloj_Falso();
		$ajustes    = new Ajustes_En_Memoria( new Settings( failure_alerts: false ) );
		$transporte = new Transporte_Espia();
		$aviso      = new Failure_Alert( $ajustes, $transporte, new Transporte_Espia(), $reloj );

		$aviso->report();
		$aviso->report();
		$this->assertSame( array(), $transporte->enviados );

		// Al activarlos no llega un aviso por los fallos de cuando estaban desactivados.
		$ajustes->save( new Settings() );
		$aviso->flush();
		$this->assertSame( array(), $transporte->enviados );
	}

	public function test_el_registro_de_entregas_se_purga_pasados_los_dias_configurados_sin_tocar_la_cola() {
		global $wpdb;

		$ahora    = time();
		$entregas = $this->repositorio_entregas();
		$envios   = $this->repositorio_envios();
		$contador = new Failure_Counter( $entregas, $envios );
		$reloj    = new Reloj_Falso( $ahora );
		$viejo    = $ahora - 40 * DAY_IN_SECONDS;

		$envio = $envios->insert(
			new Formulario_Mensaje\Storage\Stored_Submission( 0, 5, 'AAAA-BBBB', $viejo, 'https://example.org/', '', '', array( new Submission_Entry( 'nombre', 'text', 'Nombre', 'Ana', 'Ana' ) ) )
		);

		$fallo_viejo = $entregas->insert( new Delivery( 0, $envio, 5, 'aviso', 'email', Delivery_Status::Failed, 1, 'x', $viejo, $viejo ) );
		$exito_viejo = $entregas->insert( new Delivery( 0, $envio, 5, 'wh', 'webhook', Delivery_Status::Success, 1, '', $viejo, $viejo ) );
		$reciente    = $entregas->insert( new Delivery( 0, $envio, 5, 'wh', 'webhook', Delivery_Status::Success, 1, '', $ahora - DAY_IN_SECONDS, $ahora - DAY_IN_SECONDS ) );
		$en_cola     = ( new Wpdb_Delivery_Queue( $wpdb, $this->esquema(), $entregas ) )->push( new Delivery( 0, $envio, 5, 'wh2', 'webhook', Delivery_Status::Pending, 0, '', $viejo, $viejo, $viejo ), '' );

		$contador->refresh( $envio );
		$this->assertSame( 1, $envios->find( $envio )->failed_actions );

		$retencion = new Retention_Service(
			new Post_Form_Repository(),
			new Submission_Service( $envios, $entregas, $contador, $reloj ),
			$envios,
			$entregas,
			$contador,
			new Ajustes_En_Memoria( new Settings( delivery_log_days: 30 ) ),
			$reloj
		);
		$retencion->purge();

		$this->assertNull( $entregas->find( $fallo_viejo ) );
		$this->assertNull( $entregas->find( $exito_viejo ) );
		$this->assertNotNull( $entregas->find( $reciente ) );
		$this->assertNotNull( $entregas->find( $en_cola ), 'Lo que está en la cola no se purga.' );
		$this->assertSame( 0, $envios->find( $envio )->failed_actions, 'El envío deja de figurar como «con errores».' );
		$this->assertSame( 0, $contador->total() );
	}

	public function test_los_ajustes_de_avisos_y_del_registro_se_sanean() {
		$this->como_administrador();

		$guardado = $this->rest( 'PUT', '/settings', array( 'failure_alerts' => false, 'failure_alert_email' => 'no-es-correo', 'delivery_log_days' => 0 ) )->get_data();

		$this->assertFalse( $guardado['failure_alerts'] );
		$this->assertSame( '', $guardado['failure_alert_email'] );
		$this->assertSame( 1, $guardado['delivery_log_days'], 'Con cero días el mantenimiento borraría el registro entero.' );

		$guardado = $this->rest( 'PUT', '/settings', array( 'failure_alert_email' => 'avisos@example.org', 'delivery_log_days' => 9999 ) )->get_data();

		$this->assertFalse( $guardado['failure_alerts'], 'Lo que no viene en la petición se queda como estaba.' );
		$this->assertSame( 'avisos@example.org', $guardado['failure_alert_email'] );
		$this->assertSame( 365, $guardado['delivery_log_days'] );
	}

	public function test_el_esquema_se_actualiza_con_las_columnas_de_la_cola() {
		global $wpdb;

		$columnas = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->esquema()->deliveries_table() ) );

		$this->assertContains( 'next_attempt_at', $columnas );
		$this->assertContains( 'payload', $columnas );
		$this->assertFalse( $this->esquema()->needs_install() );
	}

	public function test_un_envio_se_reconstruye_a_partir_de_su_copia() {
		$envio = new Submission(
			7,
			'AAAA-BBBB',
			1_700_000_000,
			'https://example.org/contacto/',
			array(
				'nombre' => new Submission_Entry( 'nombre', 'text', 'Nombre', 'Ana', 'Ana' ),
				'gustos' => new Submission_Entry( 'gustos', 'checkboxes', 'Gustos', array( 'a', 'b' ), 'A, B' ),
			),
			'203.0.113.9',
			'Navegador'
		);

		$copia = Submission::from_array( json_decode( wp_json_encode( $envio->to_array() ), true ) );

		$this->assertSame( 'AAAA-BBBB', $copia->reference );
		$this->assertSame( array( 'a', 'b' ), $copia->entry( 'gustos' )->value );
		$this->assertSame( 'Ana', $copia->formatted( 'nombre' ) );
		$this->assertSame( '', $copia->client_ip, 'La copia de la cola no lleva la IP.' );
		$this->assertNull( Submission::from_array( 'basura' ) );
		$this->assertNull( Submission::from_array( array( 'reference' => 'x' ) ) );
	}

	private function repositorio_entregas(): Wpdb_Delivery_Repository {
		global $wpdb;

		return new Wpdb_Delivery_Repository( $wpdb, $this->esquema() );
	}

	private function repositorio_envios(): Wpdb_Submission_Repository {
		global $wpdb;

		return new Wpdb_Submission_Repository( $wpdb, $this->esquema() );
	}
}
