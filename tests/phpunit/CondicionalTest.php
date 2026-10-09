<?php
/**
 * Lógica condicional en los campos: mismos casos que el navegador, validación y marcado.
 */

use Formulario_Mensaje\Rules\Rule_Evaluator;
use Formulario_Mensaje\Rules\Visibility_Resolver;

class CondicionalTest extends Caso_Formulario {

	/** Casos compartidos con el motor de reglas del navegador (tests/e2e/condicional.spec.ts). */
	private static function casos(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/reglas.json' ), true );
	}

	public function condiciones(): array {
		$casos = array();

		foreach ( self::casos()['conditions'] as $caso ) {
			$casos[ $caso['name'] ] = array( $caso['condition'], $caso['values'], $caso['expected'] );
		}

		return $casos;
	}

	/** @dataProvider condiciones */
	public function test_el_motor_de_reglas_del_servidor_cumple_los_casos_compartidos( array $condicion, array $valores, bool $esperado ) {
		$this->assertSame( $esperado, ( new Rule_Evaluator() )->passes( $condicion, $valores ) );
	}

	public function visibilidades(): array {
		$casos = array();

		foreach ( self::casos()['visibility'] as $caso ) {
			$casos[ $caso['name'] ] = array( $caso['conditions'], $caso['values'], $caso['expected'], $caso['parents'] ?? array() );
		}

		return $casos;
	}

	/** @dataProvider visibilidades */
	public function test_la_visibilidad_del_servidor_cumple_los_casos_compartidos( array $condiciones, array $valores, array $esperado, array $padres ) {
		$this->assertSame( $esperado, ( new Visibility_Resolver( new Rule_Evaluator() ) )->resolve( $condiciones, $valores, $padres ) );
	}

	/** Formulario en el que la empresa y su CIF solo se piden a las empresas. */
	private function formulario_con_condiciones(): int {
		$es_empresa = array( 'match' => 'all', 'rules' => array( array( 'field' => 'tipo', 'operator' => 'equals', 'value' => 'empresa' ) ) );

		return $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre', 'required' => true ) ),
					array(
						'id'       => 'tipo',
						'type'     => 'radio',
						'settings' => array( 'label' => 'Tipo', 'required' => true, 'options' => array( array( 'label' => 'Particular', 'value' => 'particular' ), array( 'label' => 'Empresa', 'value' => 'empresa' ) ) ),
					),
					array( 'id' => 'empresa', 'type' => 'text', 'condition' => $es_empresa, 'settings' => array( 'label' => 'Empresa', 'required' => true ) ),
					array(
						'id'        => 'cif',
						'type'      => 'text',
						'condition' => array( 'match' => 'all', 'rules' => array( array( 'field' => 'empresa', 'operator' => 'is_not_empty', 'value' => '' ) ) ),
						'settings'  => array( 'label' => 'CIF' ),
					),
					array( 'id' => 'aviso', 'type' => 'paragraph', 'condition' => $es_empresa, 'settings' => array( 'content' => 'Te enviaremos factura.' ) ),
				),
			)
		);
	}

	public function test_un_campo_obligatorio_oculto_por_su_condicion_no_se_exige_ni_se_guarda() {
		$formulario = $this->formulario_con_condiciones();

		// Un particular no rellena la empresa, aunque sea obligatoria; y si la petición la trae, se descarta.
		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'tipo' => 'particular', 'empresa' => 'Colada por un bot', 'cif' => 'B123' ) );

		$this->assertSame( 200, $respuesta->get_status(), wp_json_encode( $respuesta->get_data() ) );

		$correo = tests_retrieve_phpmailer_instance()->get_sent()->body;
		$this->assertStringNotContainsString( 'Colada por un bot', $correo );
		$this->assertStringNotContainsString( 'B123', $correo, 'El CIF depende de un campo que no se muestra: tampoco viaja.' );

		$this->como_administrador();
		$envio  = $this->rest( 'GET', '/submissions' )->get_data()[0]['id'];
		$campos = array_column( $this->rest( 'GET', "/submissions/$envio" )->get_data()['entries'], 'field_id' );

		$this->assertSame( array( 'nombre', 'tipo' ), $campos, 'Solo se guardan los campos que le correspondían al envío.' );
	}

	public function test_un_campo_obligatorio_que_si_se_muestra_se_exige() {
		$formulario = $this->formulario_con_condiciones();

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'tipo' => 'empresa' ) );

		$this->assertSame( 422, $respuesta->get_status() );
		$this->assertSame( array( 'empresa' ), array_keys( $respuesta->get_data()['data']['errors'] ) );

		$respuesta = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'tipo' => 'empresa', 'empresa' => 'ACME', 'cif' => 'B123' ) );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertStringContainsString( 'CIF: B123', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	public function test_la_condicion_de_un_campo_se_sanea_y_solo_puede_depender_de_campos_anteriores() {
		$formulario = $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'a', 'type' => 'text', 'settings' => array( 'label' => 'A' ) ),
					array(
						'id'        => 'b',
						'type'      => 'text',
						'settings'  => array( 'label' => 'B' ),
						'condition' => array(
							'match' => 'any',
							'rules' => array(
								array( 'field' => 'a', 'operator' => 'equals', 'value' => ' <b>sí</b> ' ),
								array( 'field' => 'b', 'operator' => 'is_empty', 'value' => '' ),
								array( 'field' => 'a', 'operator' => 'ejecutar', 'value' => 'x' ),
								// Un campo que viene después: aceptarlo permitiría dependencias circulares.
								array( 'field' => 'c', 'operator' => 'is_empty', 'value' => '' ),
							),
						),
					),
					array( 'id' => 'c', 'type' => 'text', 'settings' => array( 'label' => 'C' ), 'condition' => 'basura' ),
				),
			)
		);

		$this->como_administrador();
		$campos = $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['fields'];

		$this->assertSame(
			array( 'match' => 'any', 'rules' => array( array( 'field' => 'a', 'operator' => 'equals', 'value' => 'sí' ) ) ),
			$campos[1]['condition']
		);
		$this->assertSame( array( 'match' => 'all', 'rules' => array() ), $campos[2]['condition'] );
	}

	public function test_el_html_lleva_la_condicion_de_cada_campo_y_ninguno_empieza_oculto() {
		$formulario = $this->formulario_con_condiciones();
		$html       = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertSame( 3, substr_count( $html, 'data-fm-condition=' ), 'La empresa, el CIF y el párrafo.' );
		$this->assertStringContainsString( 'data-fm-field="empresa" data-fm-condition="{&quot;match&quot;:&quot;all&quot;,&quot;rules&quot;:[{&quot;field&quot;:&quot;tipo&quot;,&quot;operator&quot;:&quot;equals&quot;,&quot;value&quot;:&quot;empresa&quot;}]}"', $html );
		$this->assertStringNotContainsString( 'data-fm-field="nombre" data-fm-condition', $html );

		// Sin JavaScript se ven todos los campos; el servidor aplica las condiciones al recibir el envío.
		$this->assertDoesNotMatchRegularExpression( '/data-fm-condition="[^"]*"[^>]*\shidden/', $html );
		$this->assertStringContainsString( 'Te enviaremos factura.', $html );
	}

	public function test_el_script_del_formulario_declara_su_dependencia_del_motor_de_reglas() {
		$formulario = $this->crear_formulario();

		do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		ob_start();
		wp_script_modules()->print_import_map();
		$mapa = (string) ob_get_clean();

		$this->assertStringContainsString( '"@formulario-mensaje/rules"', $mapa );
		$this->assertStringContainsString( 'assets/js/rules.js?ver=' . FORMULARIO_MENSAJE_VERSION, $mapa );
	}
}
