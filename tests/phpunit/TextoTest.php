<?php
/**
 * Texto sin formato: se quitan las etiquetas, pero un «<» que no es HTML llega tal cual.
 */

use Formulario_Mensaje\Support\Plain_Text;

class TextoTest extends Caso_Formulario {

	/** @return array<string, array{0: string, 1: string}> */
	public function textos(): array {
		return array(
			'sin nada especial'           => array( 'Ana Pérez', 'Ana Pérez' ),
			'menor que pegado'            => array( 'a<b', 'a<b' ),
			'un corazón'                  => array( 'Me encanta <3', 'Me encanta <3' ),
			'comparaciones'               => array( '5 < 6 y 7 > 3', '5 < 6 y 7 > 3' ),
			'menor que al final'          => array( 'precio <', 'precio <' ),
			'con ampersand y comillas'    => array( 'Tom & "Jerry" <3 & más', 'Tom & "Jerry" <3 & más' ),
			'una entidad escrita a mano'  => array( 'usa &lt; y &amp; en HTML', 'usa &lt; y &amp; en HTML' ),
			'etiquetas'                   => array( 'Hola <b>mundo</b>', 'Hola mundo' ),
			'script con su contenido'     => array( '<script>alert(1)</script> hola', 'hola' ),
			'etiqueta y menor que suelto' => array( '<i>x</i> a<b', 'x a<b' ),
			'etiqueta sin cerrar'         => array( 'a <img src=x onerror=alert(1)', 'a <img src=x onerror=alert(1)' ),
			'etiqueta troceada'           => array( '<scr<b>ipt>alert(1)</scr<b>ipt>', '' ),
			'dos menores seguidos'        => array( '<<script>alert(1)</script>', '<' ),
			'saltos y espacios'           => array( "  uno\n dos\t tres  ", 'uno dos tres' ),
		);
	}

	/** @dataProvider textos */
	public function test_una_linea_conserva_los_menores_que_y_quita_las_etiquetas( string $texto, string $esperado ) {
		$limpio = Plain_Text::line( $texto );

		$this->assertSame( $esperado, $limpio );
		// Limpiar dos veces da lo mismo: lo guardado no cambia al volver a guardarlo.
		$this->assertSame( $limpio, Plain_Text::line( $limpio ) );
		// Y no queda ninguna etiqueta que WordPress quitaría.
		$this->assertSame( $limpio, wp_strip_all_tags( $limpio ) === $limpio ? $limpio : Plain_Text::line( $limpio ) );
	}

	public function test_varias_lineas_conservan_los_saltos() {
		$this->assertSame( "uno <3\ndos\n\na<b", Plain_Text::block( "uno <3\ndos\n\n<b>a</b><b" ) );
		$this->assertSame( "línea\ndos", Plain_Text::block( "línea\n<b>dos</b>" ) );
		// Un navegador envía los saltos de un área de texto como \r\n: se guardan siempre como \n.
		$this->assertSame( "uno\ndos\n\ntres\ncuatro", Plain_Text::block( "uno\r\ndos\r\n\r\ntres\rcuatro" ) );
	}

	public function test_lo_que_escribe_el_visitante_llega_con_sus_signos() {
		$formulario = $this->crear_formulario();

		$this->assertSame(
			200,
			$this->enviar( $formulario, array( 'nombre' => 'Ana <3 & "Luis"', 'correo' => 'ana@example.org', 'mensaje' => "a<b\nSi x < 10 entonces <b>negrita</b>" ) )->get_status()
		);

		$this->como_administrador();
		$envio   = $this->rest( 'GET', '/submissions', array( 'form' => $formulario ) )->get_data()[0];
		$detalle = $this->rest( 'GET', '/submissions/' . $envio['id'] )->get_data();

		$this->assertSame( 'Ana <3 & "Luis"', $detalle['entries'][0]['value'] );
		$this->assertSame( "a<b\nSi x < 10 entonces negrita", $detalle['entries'][2]['value'] );

		// En el correo HTML se escapa una vez, no dos.
		$correo = quoted_printable_decode( tests_retrieve_phpmailer_instance()->get_sent()->body );

		$this->assertStringContainsString( 'Ana &lt;3 &amp; &quot;Luis&quot;', $correo );
		$this->assertStringNotContainsString( '&amp;lt;', $correo );
		$this->assertStringNotContainsString( '<b>negrita', $correo );
	}

	public function test_las_etiquetas_y_opciones_del_formulario_se_muestran_como_se_escribieron() {
		$formulario = $this->crear_formulario(
			array(
				'fields'   => array(
					array( 'id' => 'edad', 'type' => 'text', 'settings' => array( 'label' => 'Edad <18', 'placeholder' => 'p. ej. <5', 'help' => 'Solo si x<y' ) ),
					array(
						'id'       => 'tramo',
						'type'     => 'radio',
						'settings' => array( 'label' => 'Tramo', 'required' => true, 'options' => array( array( 'label' => 'Menos de <5', 'value' => 'Menos de <5' ), array( 'label' => '5 o más', 'value' => '5 o más' ) ) ),
					),
					array(
						'id'        => 'detalle',
						'type'      => 'text',
						'settings'  => array( 'label' => 'Detalle', 'required' => true ),
						'condition' => array( 'match' => 'all', 'rules' => array( array( 'field' => 'tramo', 'operator' => 'equals', 'value' => 'Menos de <5' ) ) ),
					),
				),
				'settings' => array( 'submit_label' => 'Enviar <ya>' ),
			),
			'Formulario a<b'
		);

		$html = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertStringContainsString( 'aria-label="Formulario a&lt;b"', $html );
		$this->assertStringContainsString( '>Edad &lt;18</label>', $html );
		$this->assertStringContainsString( 'placeholder="p. ej. &lt;5"', $html );
		$this->assertStringContainsString( '>Solo si x&lt;y</p>', $html );
		$this->assertStringContainsString( 'value="Menos de &lt;5"', $html );
		$this->assertStringContainsString( '>Menos de &lt;5</label>', $html );
		$this->assertStringNotContainsString( '&amp;lt;', $html, 'Nada se escapa dos veces.' );

		// La opción con «<» se puede elegir y dispara la condición que depende de ella.
		$sin_detalle = $this->enviar( $formulario, array( 'tramo' => 'Menos de <5' ) );
		$this->assertSame( array( 'detalle' ), array_keys( $sin_detalle->get_data()['data']['errors'] ) );

		$this->assertSame( 200, $this->enviar( $formulario, array( 'tramo' => '5 o más' ) )->get_status() );
		$this->assertSame( 200, $this->enviar( $formulario, array( 'tramo' => 'Menos de <5', 'detalle' => 'x' ) )->get_status() );
	}

	public function test_la_busqueda_de_envios_encuentra_un_texto_con_menor_que() {
		$formulario = $this->crear_formulario();

		$this->enviar( $formulario, array( 'nombre' => 'Clave a<b', 'correo' => 'ana@example.org' ) );
		$this->enviar( $formulario, array( 'nombre' => 'Otro', 'correo' => 'luis@example.org' ) );

		$this->como_administrador();

		$encontrados = $this->rest( 'GET', '/submissions', array( 'search' => 'a<b' ) )->get_data();

		$this->assertCount( 1, $encontrados );
		$this->assertStringContainsString( 'Clave a<b', $encontrados[0]['summary'] );
	}
}
