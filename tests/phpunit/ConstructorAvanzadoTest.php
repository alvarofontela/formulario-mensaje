<?php
/**
 * Constructor avanzado: anchos de los campos, plantillas de partida, duplicar, exportar e importar.
 */

use Formulario_Mensaje\Forms\Form_Porter;

class ConstructorAvanzadoTest extends Caso_Formulario {

	/** Definición con una notificación y un webhook con secreto y una cabecera con credenciales. */
	private function definicion_completa(): array {
		return $this->definicion(
			array(
				'settings' => array( 'success_message' => 'Gracias, {field:nombre}.', 'retention_days' => 90 ),
				'actions'  => array(
					array(
						'id'       => 'aviso',
						'type'     => 'email',
						'enabled'  => true,
						'name'     => 'Aviso',
						'settings' => array( 'to' => array( 'ventas@example.org' ), 'subject' => 'Nuevo', 'body' => '{all_fields}' ),
					),
					array(
						'id'       => 'wh',
						'type'     => 'webhook',
						'enabled'  => true,
						'name'     => 'CRM',
						'settings' => array(
							'url'     => 'https://crm.example.org/entrada',
							'secret'  => 'secreto-de-firma',
							'headers' => array(
								array( 'name' => 'Authorization', 'value' => 'Bearer token-privado' ),
								array( 'name' => 'X-Api-Key', 'value' => 'clave-privada' ),
								array( 'name' => 'X-Origen', 'value' => 'web' ),
							),
						),
					),
				),
			)
		);
	}

	public function test_el_ancho_de_un_campo_se_guarda_se_sanea_y_llega_al_html() {
		$formulario = $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'nombre', 'type' => 'text', 'width' => 50, 'settings' => array( 'label' => 'Nombre' ) ),
					array( 'id' => 'correo', 'type' => 'email', 'width' => '50', 'settings' => array( 'label' => 'Correo' ) ),
					array( 'id' => 'tercio', 'type' => 'text', 'width' => 33, 'settings' => array( 'label' => 'Tercio' ) ),
					array( 'id' => 'raro', 'type' => 'text', 'width' => 40, 'settings' => array( 'label' => 'Raro' ) ),
					array( 'id' => 'malo', 'type' => 'text', 'width' => '50 fm-inyectada" onclick="x', 'settings' => array( 'label' => 'Malo' ) ),
					array( 'id' => 'mensaje', 'type' => 'textarea', 'settings' => array( 'label' => 'Mensaje' ) ),
				),
			)
		);

		$this->como_administrador();
		$campos = $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['fields'];

		$this->assertSame( array( 50, 50, 33, 100, 100, 100 ), array_column( $campos, 'width' ), 'Un ancho que no está en la lista vuelve al ancho completo.' );

		$html = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertStringContainsString( 'class="fm-field fm-field--text fm-w-50" data-fm-field="nombre"', $html );
		$this->assertStringContainsString( 'class="fm-field fm-field--email fm-w-50" data-fm-field="correo"', $html );
		$this->assertStringContainsString( 'class="fm-field fm-field--text fm-w-33" data-fm-field="tercio"', $html );
		$this->assertStringContainsString( 'class="fm-field fm-field--textarea" data-fm-field="mensaje"', $html );
		$this->assertStringNotContainsString( 'fm-inyectada', $html );
		$this->assertStringNotContainsString( 'fm-w-100', $html );
	}

	public function test_las_plantillas_de_partida_llegan_al_constructor_ya_saneadas() {
		$this->como_administrador();

		$anadir = static function ( array $plantillas ): array {
			$plantillas[] = array(
				'id'          => 'propia',
				'label'       => 'Propia',
				'description' => 'De un tercero.',
				'definition'  => array(
					'fields'  => array(
						array( 'id' => 'dni', 'type' => 'text', 'width' => 50, 'settings' => array( 'label' => 'DNI <script>' ) ),
						array( 'id' => 'x', 'type' => 'tipo_que_no_existe', 'settings' => array() ),
					),
					'actions' => array(),
				),
			);
			$plantillas[] = array( 'id' => 'ID no válido', 'label' => 'x' );
			$plantillas[] = 'basura';

			return $plantillas;
		};

		add_filter( 'formulario_mensaje_form_templates', $anadir );

		$plantillas = $this->plantillas();

		$this->assertSame( array( 'contact', 'quote', 'callback', 'newsletter', 'blank', 'propia' ), array_column( $plantillas, 'id' ) );

		$contacto = $plantillas[0]['definition']->to_array();
		$this->assertSame( array( 'name', 'email', 'message' ), array_column( $contacto['fields'], 'id' ) );
		$this->assertSame( 'email', $contacto['actions'][0]['type'] );
		$this->assertSame( 'email', $contacto['actions'][0]['settings']['reply_to_field'] );

		$presupuesto = $plantillas[1]['definition']->to_array();
		$this->assertSame( array( 50, 50, 50, 50, 100, 100 ), array_column( $presupuesto['fields'], 'width' ) );
		$this->assertSame( 'consent', end( $presupuesto['fields'] )['type'] );

		// La plantilla de la llamada no tiene campo de correo: la notificación no responde a nadie.
		$this->assertSame( '', $plantillas[2]['definition']->to_array()['actions'][0]['settings']['reply_to_field'] );
		$this->assertSame( array(), $plantillas[4]['definition']->to_array()['fields'] );

		$propia = $plantillas[5]['definition']->to_array();
		$this->assertCount( 1, $propia['fields'], 'Las plantillas de terceros pasan por el saneado: el tipo desconocido se descarta.' );
		$this->assertStringNotContainsString( '<script>', $propia['fields'][0]['settings']['label'] );
	}

	public function test_duplicar_un_formulario_crea_una_copia_independiente() {
		$original = $this->crear_formulario( $this->definicion_completa(), 'Presupuestos' );

		$this->como( 'editor' );
		$this->assertSame( 403, $this->rest( 'POST', "/forms/$original/duplicate" )->get_status() );

		$this->como_administrador();
		$respuesta = $this->rest( 'POST', "/forms/$original/duplicate" );
		$copia     = $respuesta->get_data();

		$this->assertSame( 201, $respuesta->get_status() );
		$this->assertNotSame( $original, $copia['id'] );
		$this->assertSame( 'Presupuestos (copy)', $copia['title'] );

		$definicion_original = $this->rest( 'GET', "/forms/$original" )->get_data()['definition'];
		$definicion_copia    = $this->rest( 'GET', "/forms/{$copia['id']}" )->get_data()['definition'];

		$this->assertSame( $definicion_original, $definicion_copia, 'La copia lleva los mismos campos, ajustes y acciones, también el secreto cifrado.' );

		// Cambiar la copia no toca el original.
		$definicion_copia['fields'] = array();
		$this->rest( 'PUT', "/forms/{$copia['id']}", array( 'title' => 'Otra cosa', 'definition' => $definicion_copia ) );
		$this->assertCount( 3, $this->rest( 'GET', "/forms/$original" )->get_data()['definition']['fields'] );

		$this->assertSame( 404, $this->rest( 'POST', '/forms/999999/duplicate' )->get_status() );
	}

	public function test_la_exportacion_lleva_todo_menos_los_secretos() {
		$formulario = $this->crear_formulario( $this->definicion_completa(), 'Presupuestos' );

		$this->como( 'editor' );
		$this->assertSame( 403, $this->rest( 'GET', "/forms/$formulario/export" )->get_status() );

		$this->como_administrador();
		$documento = $this->rest( 'GET', "/forms/$formulario/export" )->get_data();
		$texto     = wp_json_encode( $documento );

		$this->assertSame( Form_Porter::FORMAT, $documento['format'] );
		$this->assertSame( Form_Porter::VERSION, $documento['version'] );
		$this->assertSame( 'Presupuestos', $documento['title'] );
		$this->assertCount( 3, $documento['definition']['fields'] );
		$this->assertSame( 'Gracias, {field:nombre}.', $documento['definition']['settings']['success_message'] );

		$webhook = $documento['definition']['actions'][1]['settings'];

		// Viajan los destinatarios y la dirección del webhook, con su mapeo y sus cabeceras...
		$this->assertSame( array( 'ventas@example.org' ), $documento['definition']['actions'][0]['settings']['to'] );
		$this->assertSame( 'https://crm.example.org/entrada', $webhook['url'] );
		$this->assertSame( array( 'Authorization', 'X-Api-Key', 'X-Origen' ), array_column( $webhook['headers'], 'name' ) );
		$this->assertTrue( $documento['definition']['actions'][1]['enabled'] );

		// ...pero no el secreto de firma, ni cifrado, ni el valor de ninguna cabecera: no se adivina por el nombre cuál es secreta.
		$this->assertSame( '', $webhook['secret'] );
		$this->assertSame( array( '', '', '' ), array_column( $webhook['headers'], 'value' ) );
		$this->assertStringNotContainsString( 'fm1:', $texto );
		$this->assertStringNotContainsString( 'token-privado', $texto );
		$this->assertStringNotContainsString( 'clave-privada', $texto );

		// El formulario original conserva su secreto.
		$this->assertStringStartsWith( 'fm1:', $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['actions'][1]['settings']['secret'] );
		$this->assertSame( 404, $this->rest( 'GET', '/forms/999999/export' )->get_status() );
	}

	public function test_un_formulario_importado_queda_con_sus_acciones_desactivadas_y_sin_secretos() {
		$origen = $this->crear_formulario( $this->definicion_completa(), 'Presupuestos' );

		$this->como_administrador();
		$documento = $this->rest( 'GET', "/forms/$origen/export" )->get_data();

		// Quien prepara el fichero puede añadirle lo que quiera: no se confía en él.
		$documento['definition']['actions'][1]['settings']['secret']  = 'secreto-colado';
		$documento['definition']['actions'][1]['settings']['headers'][0]['value'] = 'Bearer colado';
		$documento['definition']['fields'][]                           = array( 'id' => 'x', 'type' => 'tipo_que_no_existe', 'settings' => array() );
		$documento['definition']['fields'][0]['settings']['label']     = 'Nombre <script>alert(1)</script>';
		$documento['definition']['settings']['redirect_enabled']       = true;
		$documento['definition']['settings']['redirect_url']           = 'https://sitio-ajeno.example/gracias';
		$documento['title']                                            = 'Importado <b>x</b>';

		$this->como( 'editor' );
		$this->assertSame( 403, $this->rest( 'POST', '/forms/import', array( 'document' => $documento ) )->get_status() );

		$this->como_administrador();
		$respuesta = $this->rest( 'POST', '/forms/import', array( 'document' => $documento ) );

		$this->assertSame( 201, $respuesta->get_status(), wp_json_encode( $respuesta->get_data() ) );
		$this->assertNotSame( $origen, $respuesta->get_data()['id'] );
		$this->assertSame( 'Importado x', $respuesta->get_data()['title'] );

		$importado = $this->rest( 'GET', "/forms/{$respuesta->get_data()['id']}" )->get_data()['definition'];

		$this->assertCount( 3, $importado['fields'] );
		$this->assertStringNotContainsString( '<script>', $importado['fields'][0]['settings']['label'] );
		$this->assertSame( array( false, false ), array_column( $importado['actions'], 'enabled' ), 'Las acciones escriben a direcciones que eligió otra persona: hay que revisarlas.' );
		$this->assertSame( 'https://crm.example.org/entrada', $importado['actions'][1]['settings']['url'] );
		$this->assertSame( '', $importado['actions'][1]['settings']['secret'] );
		$this->assertSame( '', $importado['actions'][1]['settings']['headers'][0]['value'] );
		$this->assertFalse( $importado['settings']['redirect_enabled'], 'Una redirección a un dominio no permitido en esta web se desactiva.' );
		$this->assertSame( 90, $importado['settings']['retention_days'] );

		// Un envío al formulario importado se guarda, pero no avisa a nadie ni llama a ningún webhook.
		wp_set_current_user( 0 );
		$this->assertSame( 200, $this->enviar( $respuesta->get_data()['id'], array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) )->get_status() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function documentos_no_validos(): array {
		return array(
			'otro JSON'            => array( array( 'name' => 'package', 'version' => '1.0.0' ), 'formulario_mensaje_import_invalid' ),
			'sin definición'       => array( array( 'format' => Form_Porter::FORMAT, 'version' => 1 ), 'formulario_mensaje_import_invalid' ),
			'definición no válida' => array( array( 'format' => Form_Porter::FORMAT, 'version' => 1, 'definition' => 'texto' ), 'formulario_mensaje_import_invalid' ),
			'versión posterior'    => array( array( 'format' => Form_Porter::FORMAT, 'version' => 99, 'definition' => array() ), 'formulario_mensaje_import_newer' ),
		);
	}

	/** @dataProvider documentos_no_validos */
	public function test_un_fichero_que_no_es_una_exportacion_se_rechaza( array $documento, string $codigo ) {
		$this->como_administrador();

		$respuesta = $this->rest( 'POST', '/forms/import', array( 'document' => $documento ) );

		$this->assertSame( 400, $respuesta->get_status() );
		$this->assertSame( $codigo, $respuesta->get_data()['code'] );
	}

	/** Plantillas tal como las entrega el servicio, con el filtro de terceros aplicado. */
	private function plantillas(): array {
		// El servicio vive en el contenedor del plugin; se compone aquí con las mismas piezas.
		$tipos_campo  = new Formulario_Mensaje\Fields\Field_Type_Registry();
		$tipos_accion = new Formulario_Mensaje\Actions\Action_Type_Registry();

		do_action( 'formulario_mensaje_register_field_types', $tipos_campo );
		do_action( 'formulario_mensaje_register_action_types', $tipos_accion );

		$saneador = new Formulario_Mensaje\Forms\Form_Definition_Sanitizer(
			$tipos_campo,
			$tipos_accion,
			new Formulario_Mensaje\Fields\Settings_Schema_Sanitizer( new Formulario_Mensaje\Support\Inline_Html() ),
			new Formulario_Mensaje\Support\Inline_Html(),
			new Formulario_Mensaje\Rules\Condition_Sanitizer(),
			new Formulario_Mensaje\Forms\Form_Style()
		);

		return ( new Formulario_Mensaje\Forms\Form_Templates( $saneador, $tipos_accion ) )->all();
	}
}
