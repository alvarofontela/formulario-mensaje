<?php
/**
 * Regresiones de los hallazgos de la revisión de seguridad de los commits.
 *
 * Cada test reproduce el fallo que se encontró y comprueba que sigue corregido.
 */

use Formulario_Mensaje\Antispam\Wpdb_Rate_Limiter;
use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use Formulario_Mensaje\Storage\Failure_Counter;
use Formulario_Mensaje\Storage\Stored_Submission;
use Formulario_Mensaje\Storage\Submission_Service;
use Formulario_Mensaje\Storage\Wpdb_Delivery_Repository;
use Formulario_Mensaje\Storage\Wpdb_Submission_Repository;
use Formulario_Mensaje\Submissions\Submission_Entry;

class RevisionSeguridadTest extends Caso_Formulario {

	private Wpdb_Submission_Repository $envios;
	private Wpdb_Delivery_Repository $entregas;

	public function set_up() {
		parent::set_up();

		global $wpdb;

		$this->envios   = new Wpdb_Submission_Repository( $wpdb, $this->esquema() );
		$this->entregas = new Wpdb_Delivery_Repository( $wpdb, $this->esquema() );
	}

	private function guardar( int $formulario ): int {
		return $this->envios->insert(
			new Stored_Submission( 0, $formulario, 'ABCD-2345', time(), '', '', '', array( new Submission_Entry( 'nombre', 'text', 'Nombre', 'Ana', 'Ana' ) ) )
		);
	}

	public function test_borrar_un_formulario_con_sus_envios_sin_poder_ver_envios_se_rechaza_entero() {
		$formulario = $this->crear_formulario();
		$envio      = $this->guardar( $formulario );

		$gestor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $gestor );
		wp_get_current_user()->add_cap( Capabilities::MANAGE_FORMS );

		$respuesta = $this->rest( 'DELETE', "/forms/$formulario", array( 'force' => true, 'delete_submissions' => true ) );

		$this->assertSame( 403, $respuesta->get_status() );
		$this->assertNotNull( get_post( $formulario ), 'El formulario no se borra a medias.' );
		$this->assertNotNull( $this->envios->find( $envio ) );

		// Sin pedir el borrado de envíos, gestionar formularios basta.
		$this->assertSame( 200, $this->rest( 'DELETE', "/forms/$formulario", array( 'force' => true ) )->get_status() );
		$this->assertNotNull( $this->envios->find( $envio ), 'Los envíos se conservan.' );
	}

	public function test_gestionar_formularios_no_basta_para_fijar_el_plazo_de_retencion_que_borra_envios() {
		$formulario = $this->crear_formulario( array( 'settings' => array( 'retention_days' => 30 ) ) );
		$antiguo    = $this->envios->insert(
			new Stored_Submission( 0, $formulario, 'ABCD-2346', time() - 2 * DAY_IN_SECONDS, '', '', '', array( new Submission_Entry( 'nombre', 'text', 'Nombre', 'Ana', 'Ana' ) ) )
		);
		// crear_formulario() deja la sesión cerrada, como un visitante.
		$this->como_administrador();
		$definicion = $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition'];

		$gestor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $gestor );
		wp_get_current_user()->add_cap( Capabilities::MANAGE_FORMS );

		// Bajar el plazo a un día haría que el mantenimiento diario borrase el envío: es borrar envíos.
		$corto                               = $definicion;
		$corto['settings']['retention_days'] = 1;

		$respuesta = $this->rest( 'PUT', "/forms/$formulario", array( 'definition' => $corto ) );

		$this->assertSame( 403, $respuesta->get_status() );
		$this->assertSame( 'formulario_mensaje_retention_forbidden', $respuesta->get_data()['code'] );
		$this->assertSame( 30, $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['settings']['retention_days'], 'No se guarda a medias.' );

		do_action( Maintenance_Schedule::HOOK );
		$this->assertNotNull( $this->envios->find( $antiguo ), 'El envío sigue ahí.' );

		// Guardar cualquier otra cosa con el plazo tal como estaba sí puede: es lo que envía el constructor.
		$otro                                = $definicion;
		$otro['settings']['submit_label']    = 'Enviar ya';
		$this->assertSame( 200, $this->rest( 'PUT', "/forms/$formulario", array( 'definition' => $otro ) )->get_status() );
		$this->assertSame( 200, $this->rest( 'PUT', "/forms/$formulario", array( 'title' => 'Otro nombre' ) )->get_status() );

		// Tampoco al crear ni al importar: un formulario nuevo de este usuario nace sin plazo.
		$this->assertSame( 403, $this->rest( 'POST', '/forms', array( 'title' => 'Nuevo', 'definition' => $corto ) )->get_status() );

		$documento = $this->rest( 'GET', "/forms/$formulario/export" )->get_data();
		$importado = $this->rest( 'POST', '/forms/import', array( 'document' => $documento ) );

		$this->assertSame( 201, $importado->get_status() );
		$this->assertSame( 0, $this->rest( 'GET', '/forms/' . $importado->get_data()['id'] )->get_data()['definition']['settings']['retention_days'], 'El plazo importado llega sin poner.' );

		// Con el permiso de los envíos, el mismo cambio se guarda.
		wp_get_current_user()->add_cap( Capabilities::VIEW_SUBMISSIONS );

		$this->assertSame( 200, $this->rest( 'PUT', "/forms/$formulario", array( 'definition' => $corto ) )->get_status() );
		$this->assertSame( 1, $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['settings']['retention_days'] );
	}

	public function test_borrar_los_envios_de_un_formulario_los_borra_todos_aunque_sean_muchos() {
		$formulario = $this->crear_formulario();
		$otro       = $this->crear_formulario( array(), 'Otro' );

		// Más de dos lotes de 200.
		for ( $i = 0; $i < 450; $i++ ) {
			$this->guardar( $formulario );
		}

		$ajeno    = $this->guardar( $otro );
		$servicio = new Submission_Service( $this->envios, $this->entregas, new Failure_Counter( $this->entregas, $this->envios ), new Reloj_Falso() );

		$this->assertSame( 450, $servicio->delete_for_form( $formulario ) );
		$this->assertSame( array(), $this->envios->ids_before( $formulario, '', 10 ) );
		$this->assertNotNull( $this->envios->find( $ajeno ) );
		$this->assertSame( 0, $servicio->delete_for_form( $formulario ), 'Sin envíos, termina.' );
	}

	public function test_no_se_reintenta_una_accion_que_ahora_esta_desactivada() {
		$formulario = $this->crear_formulario();

		add_filter( 'pre_wp_mail', '__return_false' );
		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->como_administrador();

		$entrega    = $this->rest( 'GET', '/deliveries' )->get_data()[0]['id'];
		$definicion = $this->definicion();

		$definicion['actions'][0]['enabled'] = false;
		$this->rest( 'PUT', "/forms/$formulario", array( 'title' => 'Contacto', 'definition' => $definicion ) );

		reset_phpmailer_instance();
		$reintento = $this->rest( 'POST', "/deliveries/$entrega/retry" );

		$this->assertSame( 409, $reintento->get_status() );
		$this->assertSame( 'formulario_mensaje_action_disabled', $reintento->get_data()['code'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'No se envía nada.' );
	}

	public function test_el_limite_por_ip_sigue_contando_si_la_tabla_de_contadores_falla() {
		global $wpdb;

		// Una conexión con otro prefijo: la tabla de contadores no existe.
		$roto         = clone $wpdb;
		$roto->prefix = 'noexiste_';
		$roto->suppress_errors( true );

		$contador = new Wpdb_Rate_Limiter( $roto, new Schema( $roto ), new Reloj_Falso() );

		$this->assertSame( array( 1, 2, 3 ), array( $contador->hit( 'clave', 60 ), $contador->hit( 'clave', 60 ), $contador->hit( 'clave', 60 ) ), 'Fallar no equivale a no tener límite.' );

		$contador->release( 'clave' );

		$this->assertSame( 3, $contador->hit( 'clave', 60 ) );
	}
}
