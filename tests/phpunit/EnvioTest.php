<?php
/**
 * Envío de formularios: validación, notificación, confirmación y redirección.
 */

use Formulario_Mensaje\Actions\Action_Runner;
use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Definition;
use Formulario_Mensaje\Mail\Email_Action;
use Formulario_Mensaje\Mail\Email_Message;
use Formulario_Mensaje\Settings\Settings;
use Formulario_Mensaje\Submissions\Redirect_Policy;
use Formulario_Mensaje\Submissions\Redirect_Resolver;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Submissions\Submission_Entry;
use Formulario_Mensaje\Submissions\Tag_Replacer;

class EnvioTest extends Caso_Formulario {

	private function envio( array $valores = array(), string $referencia = 'ABCD-2345' ): Submission {
		$entradas = array();

		foreach ( $valores as $id => $valor ) {
			$entradas[ $id ] = new Submission_Entry( $id, 'text', ucfirst( $id ), $valor, $valor );
		}

		return new Submission( 5, $referencia, 1_700_000_000, 'https://example.org/contacto/', $entradas );
	}

	private function formulario( array $ajustes = array() ): Form {
		return new Form( 5, 'Contacto', Form::STATUS_PUBLISHED, new Form_Definition( array(), $ajustes ) );
	}

	public function test_un_envio_correcto_manda_la_notificacion_a_los_destinatarios_configurados() {
		$formulario = $this->crear_formulario();

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana Pérez', 'correo' => 'ana@example.org', 'mensaje' => "Hola\nQuiero un presupuesto" ) );
		$datos     = $respuesta->get_data();
		$correo    = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 200, $respuesta->get_status(), wp_json_encode( $datos ) );
		$this->assertTrue( $datos['success'] );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $datos['reference'] );
		$this->assertSame( 'hide', $datos['after_submit'] );
		$this->assertSame( '', $datos['redirect'] );
		$this->assertSame( 'no-store', $respuesta->get_headers()['Cache-Control'] );

		$this->assertSame( 'ventas@example.org', $correo->to[0][0] );
		$this->assertSame( 'Nuevo envío: Contacto', $correo->subject );
		$this->assertStringContainsString( 'Nombre: Ana Pérez', $correo->body );
		$this->assertStringContainsString( "Mensaje: Hola\nQuiero un presupuesto", str_replace( "\r\n", "\n", $correo->body ) );
		$this->assertStringContainsString( 'Reply-To: ana@example.org', $correo->header );
	}

	public function test_los_errores_de_validacion_se_devuelven_por_campo_y_no_se_envia_nada() {
		$formulario = $this->crear_formulario();

		$respuesta = $this->enviar( $formulario, array( 'nombre' => '   ', 'correo' => 'no-es-correo', 'mensaje' => 'Hola' ) );
		$datos     = $respuesta->get_data();

		$this->assertSame( 422, $respuesta->get_status() );
		$this->assertSame( 'formulario_mensaje_invalid', $datos['code'] );
		$this->assertSame( array( 'nombre', 'correo' ), array_keys( $datos['data']['errors'] ) );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_solo_se_leen_los_campos_del_formulario() {
		$formulario = $this->crear_formulario();

		$this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org', 'intruso' => 'dato no pedido', 'to' => 'atacante@example.org' ) );
		$correo = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertCount( 1, $correo->to, 'El destinatario sale de la configuración, no de la petición.' );
		$this->assertStringNotContainsString( 'dato no pedido', $correo->body );
	}

	public function test_los_datos_del_visitante_no_pueden_inyectar_cabeceras_de_correo() {
		$formulario = $this->crear_formulario(
			array(
				'actions' => array(
					array(
						'id'       => 'aviso',
						'type'     => 'email',
						'enabled'  => true,
						'settings' => array( 'to' => array( 'ventas@example.org' ), 'subject' => 'De {field:nombre}', 'body' => '{all_fields}', 'reply_to_field' => 'nombre' ),
					),
				),
			)
		);

		$this->enviar( $formulario, array( 'nombre' => "Ana\r\nBcc: victima@example.org", 'correo' => 'ana@example.org' ) );
		$correo = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertStringNotContainsString( "\n", $correo->subject );
		$this->assertDoesNotMatchRegularExpression( '/^Bcc:/mi', $correo->header, 'El texto queda dentro del asunto, en una sola línea.' );
		$this->assertStringNotContainsString( 'Reply-To', $correo->header, 'Un campo que no contiene un correo válido no llega a Reply-To.' );
		$this->assertEmpty( $correo->bcc );
	}

	public function test_un_formulario_en_la_papelera_deja_de_aceptar_envios() {
		$formulario = $this->crear_formulario();
		wp_trash_post( $formulario );

		$respuesta = $this->rest( 'POST', "/forms/$formulario/submissions", array( 'fm' => array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) ) );

		$this->assertSame( 404, $respuesta->get_status() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_si_la_unica_accion_falla_el_visitante_recibe_un_error() {
		$formulario = $this->crear_formulario();

		// Un correo sin destinatarios válidos en el momento del envío: la acción falla.
		add_filter( 'pre_wp_mail', '__return_false' );
		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->assertSame( 500, $respuesta->get_status() );
		$this->assertSame( 'formulario_mensaje_delivery_failed', $respuesta->get_data()['code'] );
	}

	public function test_una_accion_desactivada_no_se_ejecuta_y_el_envio_se_acepta() {
		$formulario = $this->crear_formulario(
			array( 'actions' => array( array( 'id' => 'aviso', 'type' => 'email', 'enabled' => false, 'settings' => array( 'to' => array( 'ventas@example.org' ) ) ) ) )
		);

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_el_mensaje_de_exito_sustituye_etiquetas_y_escapa_los_datos_del_visitante() {
		$formulario = $this->crear_formulario(
			array( 'settings' => array( 'success_message' => 'Gracias, <strong>{field:nombre}</strong>. Referencia {reference}.<script>alert(0)</script>' ) )
		);

		$datos = $this->enviar( $formulario, array( 'nombre' => 'Ana & "Cía" > resto', 'correo' => 'ana@example.org' ) )->get_data();

		$this->assertStringContainsString( '<strong>Ana &amp; &quot;Cía&quot; &gt; resto</strong>', $datos['message'], 'El formato de quien configura se conserva; el dato del visitante se escapa.' );
		$this->assertStringNotContainsString( '<script>', $datos['message'] );
		$this->assertStringContainsString( 'Referencia ' . $datos['reference'], $datos['message'] );
	}

	public function test_las_etiquetas_no_se_vuelven_a_interpretar_dentro_de_los_datos_del_visitante() {
		$etiquetas = new Tag_Replacer();
		$envio     = $this->envio( array( 'nombre' => '{reference} {field:secreto} {all_fields}', 'secreto' => 'no debe repetirse' ) );

		$texto = $etiquetas->to_text( 'Hola {field:nombre} / {reference}', $envio, $this->formulario() );

		$this->assertSame( 'Hola {reference} {field:secreto} {all_fields} / ABCD-2345', $texto );
	}

	public function test_las_etiquetas_generales_y_todos_los_campos() {
		$etiquetas = new Tag_Replacer();
		$envio     = $this->envio( array( 'nombre' => 'Ana & Cía', 'vacio' => '' ) );

		$this->assertSame( "Nombre: Ana & Cía\n--\nContacto / https://example.org/contacto/", $etiquetas->to_text( "{all_fields}\n--\n{form_title} / {page_url}", $envio, $this->formulario() ) );
		$this->assertSame( '<strong>Nombre:</strong> Ana &amp; Cía', $etiquetas->to_html( '{all_fields}', $envio, $this->formulario() ) );
		$this->assertSame( 'Sin dato: ', $etiquetas->to_text( 'Sin dato: {field:no_existe}', $envio, $this->formulario() ) );
	}

	public function test_la_politica_de_redireccion_solo_admite_el_sitio_y_los_dominios_permitidos() {
		$politica = new Redirect_Policy( new Ajustes_En_Memoria( new Settings( allowed_redirect_hosts: array( 'pagos.example.com' ) ) ) );

		$this->assertTrue( $politica->is_allowed( '/gracias/' ) );
		$this->assertTrue( $politica->is_allowed( home_url( '/gracias/' ) ) );
		$this->assertTrue( $politica->is_allowed( 'https://pagos.example.com/ok?x=1' ) );
		$this->assertFalse( $politica->is_allowed( 'https://malo.example/' ) );
		$this->assertFalse( $politica->is_allowed( '//malo.example/' ) );
		$this->assertFalse( $politica->is_allowed( 'https://pagos.example.com.malo.example/' ) );
		$this->assertFalse( $politica->is_allowed( 'https://pagos.example.com@malo.example/' ) );
		$this->assertFalse( $politica->is_allowed( 'javascript:alert(1)' ) );
		$this->assertFalse( $politica->is_allowed( '/\\malo.example' ) );
		$this->assertFalse( $politica->is_allowed( '' ) );
	}

	public function test_la_redireccion_anade_solo_los_campos_elegidos_y_se_revalida_en_cada_envio() {
		$ajustes  = new Ajustes_En_Memoria( new Settings( allowed_redirect_hosts: array( 'pagos.example.com' ) ) );
		$resolver = new Redirect_Resolver( new Redirect_Policy( $ajustes ) );
		$envio    = $this->envio( array( 'nombre' => 'Ana & Cía', 'correo' => 'ana@example.org' ) );
		$form     = $this->formulario(
			array(
				'redirect_enabled' => true,
				'redirect_url'     => 'https://pagos.example.com/ok?origen=web',
				'redirect_fields'  => array( array( 'field' => 'nombre', 'param' => 'cliente' ) ),
			)
		);

		$this->assertSame( 'https://pagos.example.com/ok?origen=web&cliente=Ana%20%26%20C%C3%ADa', $resolver->resolve( $form, $envio ) );
		$this->assertSame( '', $resolver->resolve( $this->formulario( array( 'redirect_url' => 'https://pagos.example.com/ok' ) ), $envio ), 'Sin activar no redirige.' );

		// El dominio se retira de la lista después de guardar el formulario.
		$ajustes->save( new Settings() );

		$this->assertSame( '', $resolver->resolve( $form, $envio ) );
	}

	public function test_la_respuesta_de_un_envio_incluye_la_redireccion_configurada() {
		$formulario = $this->crear_formulario(
			array( 'settings' => array( 'redirect_enabled' => true, 'redirect_url' => '/gracias/', 'after_submit' => 'reset', 'tracking_enabled' => false ) )
		);

		$datos = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) )->get_data();

		$this->assertSame( home_url( '/gracias/' ), $datos['redirect'] );
		$this->assertSame( 'reset', $datos['after_submit'] );
		$this->assertFalse( $datos['tracking'] );
	}

	public function test_la_notificacion_usa_el_transporte_inyectado_y_admite_el_filtro_del_correo() {
		$transporte = new Transporte_Espia();
		$accion     = new Email_Action( $transporte, new Tag_Replacer() );
		$envio      = $this->envio( array( 'nombre' => 'Ana', 'correo' => 'ana@example.org' ) );
		$ajustes    = array( 'to' => array( 'ventas@example.org', 'no-valido' ), 'subject' => 'Aviso {reference}', 'body' => '{field:nombre}', 'reply_to_field' => 'correo' );

		$this->assertTrue( $accion->execute( $ajustes, $envio, $this->formulario() ) );
		$this->assertSame( array( 'ventas@example.org' ), $transporte->enviados[0]->to );
		$this->assertSame( 'Aviso ABCD-2345', $transporte->enviados[0]->subject );
		$this->assertSame( 'ana@example.org', $transporte->enviados[0]->reply_to );

		add_filter(
			'formulario_mensaje_email_message',
			static fn ( Email_Message $mensaje ) => new Email_Message( $mensaje->to, '[Web] ' . $mensaje->subject, $mensaje->body, $mensaje->reply_to )
		);
		$accion->execute( $ajustes, $envio, $this->formulario() );

		$this->assertSame( '[Web] Aviso ABCD-2345', $transporte->enviados[1]->subject );

		$sin_destino = $accion->execute( array( 'to' => array() ), $envio, $this->formulario() );
		$this->assertSame( 'formulario_mensaje_no_recipients', $sin_destino->get_error_code() );
	}

	public function test_el_fallo_de_una_accion_no_impide_las_demas_y_queda_registrado_sin_datos_del_envio() {
		$falla = new Transporte_Espia();
		$falla->falla = true;

		$registro = new Action_Type_Registry();
		$registro->register( new Email_Action( $falla, new Tag_Replacer() ) );

		$formulario = new Form(
			5,
			'Contacto',
			Form::STATUS_PUBLISHED,
			new Form_Definition(
				array(),
				array(),
				array(
					array( 'id' => 'a1', 'type' => 'email', 'enabled' => true, 'settings' => array( 'to' => array( 'uno@example.org' ), 'subject' => 's', 'body' => '{all_fields}' ) ),
					array( 'id' => 'a2', 'type' => 'desconocida', 'enabled' => true, 'settings' => array() ),
					array( 'id' => 'a3', 'type' => 'email', 'enabled' => false, 'settings' => array() ),
				)
			)
		);

		$resultados = ( new Action_Runner( $registro ) )->run( $formulario, $this->envio( array( 'nombre' => 'Dato personal' ) ) );

		$this->assertCount( 1, $resultados, 'Las acciones desconocidas o desactivadas no se ejecutan.' );
		$this->assertFalse( $resultados[0]->success );
		$this->assertSame( 'a1', $resultados[0]->action_id );
		$this->assertStringNotContainsString( 'Dato personal', $resultados[0]->error );
	}

	public function test_un_filtro_puede_anadir_errores_de_validacion_propios() {
		$formulario = $this->crear_formulario();

		add_filter(
			'formulario_mensaje_validation_errors',
			static function ( array $errores, array $entradas ) {
				if ( str_ends_with( (string) $entradas['correo']->value, '@competencia.example' ) ) {
					$errores['correo'] = 'Usa tu correo de empresa.';
				}

				$errores['campo_inexistente'] = 'Se descarta.';

				return $errores;
			},
			10,
			2
		);

		$datos = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'correo' => 'ana@competencia.example' ) )->get_data();

		$this->assertSame( array( 'correo' => 'Usa tu correo de empresa.' ), $datos['data']['errors'] );
	}
}
