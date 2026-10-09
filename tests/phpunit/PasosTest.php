<?php
/**
 * Formularios por pasos: reparto de campos, marcado, validación por paso y pasos condicionales.
 */

class PasosTest extends Caso_Formulario {

	/** Formulario de tres pasos; el segundo solo se muestra a las empresas. */
	private function formulario_por_pasos(): int {
		return $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'p_datos', 'type' => 'step', 'settings' => array( 'label' => 'Tus datos' ) ),
					array( 'id' => 'nombre', 'type' => 'text', 'width' => 50, 'settings' => array( 'label' => 'Nombre', 'required' => true ) ),
					array(
						'id'       => 'tipo',
						'type'     => 'radio',
						'width'    => 50,
						'settings' => array( 'label' => 'Tipo', 'required' => true, 'options' => array( array( 'label' => 'Particular', 'value' => 'particular' ), array( 'label' => 'Empresa', 'value' => 'empresa' ) ) ),
					),
					array(
						'id'        => 'p_empresa',
						'type'      => 'step',
						'settings'  => array( 'label' => 'Tu empresa' ),
						'condition' => array( 'match' => 'all', 'rules' => array( array( 'field' => 'tipo', 'operator' => 'equals', 'value' => 'empresa' ) ) ),
					),
					array( 'id' => 'empresa', 'type' => 'text', 'settings' => array( 'label' => 'Empresa', 'required' => true ) ),
					array( 'id' => 'p_mensaje', 'type' => 'step', 'settings' => array( 'label' => 'Tu mensaje' ) ),
					array( 'id' => 'mensaje', 'type' => 'textarea', 'settings' => array( 'label' => 'Mensaje', 'required' => true ) ),
				),
			)
		);
	}

	private function validar_paso( int $formulario, int $paso, array $valores ): WP_REST_Response {
		return $this->rest( 'POST', "/forms/$formulario/validate", array( 'fm' => $valores, 'step' => $paso ) );
	}

	public function test_el_html_agrupa_los_campos_en_pasos_con_su_titulo_y_su_condicion() {
		$formulario = $this->formulario_por_pasos();
		$html       = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertSame( 3, substr_count( $html, 'class="fm-step"' ) );
		$this->assertStringContainsString( 'data-fm-steps="3"', $html );
		$this->assertStringContainsString( 'data-fm-step-text="Step {current} of {total}"', $html );
		$this->assertMatchesRegularExpression( '#<div class="fm-step" data-fm-step="0"><h3 class="fm-step__title" tabindex="-1">Tus datos</h3><div class="fm-field fm-field--text fm-w-50" data-fm-field="nombre"#', $html );
		$this->assertMatchesRegularExpression( '#<div class="fm-step" data-fm-step="1" data-fm-condition="[^"]+"><h3 class="fm-step__title" tabindex="-1">Tu empresa</h3>#', $html );

		// Sin JavaScript se ve todo: los pasos no empiezan ocultos y los botones de paso sí.
		$this->assertDoesNotMatchRegularExpression( '#class="fm-step"[^>]*\shidden#', $html );
		$this->assertStringContainsString( '<div class="fm-progress" hidden>', $html );
		$this->assertStringContainsString( '<button type="button" class="fm-prev wp-element-button" hidden>Previous</button><button type="button" class="fm-next wp-element-button" hidden>Next</button><button type="submit"', $html );
	}

	public function test_un_formulario_sin_pasos_no_lleva_nada_de_pasos() {
		$formulario = $this->crear_formulario();
		$html       = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertStringNotContainsString( 'fm-step', $html );
		$this->assertStringNotContainsString( 'fm-progress', $html );
		$this->assertStringNotContainsString( 'fm-next', $html );
	}

	public function test_los_campos_anteriores_al_primer_paso_forman_un_paso_sin_titulo() {
		$formulario = $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre' ) ),
					array( 'id' => 'p2', 'type' => 'step', 'settings' => array( 'label' => 'Segundo' ) ),
					array( 'id' => 'mensaje', 'type' => 'textarea', 'settings' => array( 'label' => 'Mensaje' ) ),
				),
			)
		);
		$html       = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertSame( 2, substr_count( $html, 'class="fm-step"' ) );
		$this->assertMatchesRegularExpression( '#<div class="fm-step" data-fm-step="0"><div class="fm-field fm-field--text" data-fm-field="nombre"#', $html );
	}

	public function test_cada_paso_se_valida_por_separado_sin_aceptar_ni_guardar_nada() {
		$formulario = $this->formulario_por_pasos();

		$vacio = $this->validar_paso( $formulario, 0, array() );
		$this->assertSame( 422, $vacio->get_status() );
		$this->assertSame( array( 'nombre', 'tipo' ), array_keys( $vacio->get_data()['data']['errors'] ), 'Solo los errores del paso que se valida, no los del mensaje.' );

		$this->assertSame( 200, $this->validar_paso( $formulario, 0, array( 'nombre' => 'Ana', 'tipo' => 'empresa' ) )->get_status() );

		$sin_empresa = $this->validar_paso( $formulario, 1, array( 'nombre' => 'Ana', 'tipo' => 'empresa' ) );
		$this->assertSame( array( 'empresa' ), array_keys( $sin_empresa->get_data()['data']['errors'] ) );

		// Un paso que no existe no tiene errores; validar no manda correo ni guarda envíos.
		$this->assertSame( 200, $this->validar_paso( $formulario, 99, array() )->get_status() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );

		$this->como_administrador();
		$this->assertSame( array(), $this->rest( 'GET', '/submissions' )->get_data() );

		wp_set_current_user( 0 );
		$this->assertSame( 404, $this->validar_paso( 999999, 0, array() )->get_status() );
	}

	public function test_un_paso_que_no_se_muestra_no_exige_sus_campos_ni_los_guarda() {
		$formulario = $this->formulario_por_pasos();

		// Para un particular, el paso de la empresa no cuenta: ni al validarlo ni al enviar.
		$this->assertSame( 200, $this->validar_paso( $formulario, 1, array( 'nombre' => 'Ana', 'tipo' => 'particular' ) )->get_status() );

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'tipo' => 'particular', 'empresa' => 'Colada', 'mensaje' => 'Hola' ) );
		$this->assertSame( 200, $respuesta->get_status(), wp_json_encode( $respuesta->get_data() ) );
		$this->assertStringNotContainsString( 'Colada', tests_retrieve_phpmailer_instance()->get_sent()->body );

		$this->como_administrador();
		$envio = $this->rest( 'GET', '/submissions' )->get_data()[0]['id'];
		$this->assertSame( array( 'nombre', 'tipo', 'mensaje' ), array_column( $this->rest( 'GET', "/submissions/$envio" )->get_data()['entries'], 'field_id' ) );
	}

	public function test_al_enviar_se_valida_el_formulario_entero_aunque_no_se_haya_validado_paso_a_paso() {
		$formulario = $this->formulario_por_pasos();

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'tipo' => 'empresa' ) );

		$this->assertSame( 422, $respuesta->get_status() );
		$this->assertSame( array( 'empresa', 'mensaje' ), array_keys( $respuesta->get_data()['data']['errors'] ) );
	}

	public function test_el_script_del_formulario_declara_el_modulo_de_pasos() {
		$formulario = $this->crear_formulario();

		do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		ob_start();
		wp_script_modules()->print_import_map();
		$mapa = (string) ob_get_clean();

		$this->assertStringContainsString( '"@formulario-mensaje/steps"', $mapa );
		$this->assertStringContainsString( 'assets/js/steps.js?ver=' . FORMULARIO_MENSAJE_VERSION, $mapa );
	}
}
