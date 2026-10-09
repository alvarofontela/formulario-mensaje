<?php
/**
 * Tipos de campo: saneado, validación, formato y HTML.
 */

use Formulario_Mensaje\Fields\Common_Settings;
use Formulario_Mensaje\Fields\Context_Resolver;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Fields\Input_Field_Type;
use Formulario_Mensaje\Fields\Prefill_Resolver;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Fields\Settings_Schema_Sanitizer;
use Formulario_Mensaje\Fields\Types;
use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Support\Inline_Html;

class CamposTest extends Caso_Formulario {

	private Field_Markup $markup;
	private Common_Settings $comunes;
	private Render_Context $contexto;

	public function set_up() {
		parent::set_up();

		$this->markup   = new Field_Markup();
		$this->comunes  = new Common_Settings();
		$this->contexto = new Render_Context( 5, '5-1' );
	}

	/** @param array<string, string> $consulta */
	private function prellenado( array $consulta = array() ): Prefill_Resolver {
		return new Prefill_Resolver( new Consulta_Falsa( $consulta ) );
	}

	private function campo( string $tipo, array $ajustes = array() ): Field {
		return new Field( 'campo', $tipo, $ajustes + array( 'label' => 'Etiqueta' ) );
	}

	public function test_el_registro_trae_todos_los_tipos_de_la_fase_1_y_admite_tipos_de_terceros() {
		$tercero = new Types\Text_Field_Type( $this->markup, $this->comunes, $this->prellenado() );

		add_action(
			'formulario_mensaje_register_field_types',
			static function ( Field_Type_Registry $registro ) use ( $tercero ) {
				$registro->register( $tercero );
			}
		);

		// El registro del plugin se construye al pedirlo; aquí se reproduce su composición.
		$registro = new Field_Type_Registry();
		do_action( 'formulario_mensaje_register_field_types', $registro );

		$tipos = array_map( static fn ( $tipo ) => $tipo->get_type(), $registro->all() );

		foreach ( array( 'text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkboxes', 'consent', 'number', 'date', 'url', 'hidden', 'heading', 'paragraph', 'divider' ) as $esperado ) {
			$this->assertContains( $esperado, $tipos );
		}

		$this->assertSame( $tercero, $registro->get( 'text' ), 'Un tipo registrado después sustituye al del plugin.' );
		$this->assertNull( $registro->get_input( 'heading' ), 'Los elementos de presentación no recogen datos.' );
		$this->assertInstanceOf( Input_Field_Type::class, $registro->get_input( 'email' ) );
	}

	public function test_el_texto_se_sanea_y_se_limita_en_longitud() {
		$tipo = new Types\Text_Field_Type( $this->markup, $this->comunes, $this->prellenado() );

		$this->assertSame( 'Hola mundo', $tipo->sanitize_value( $this->campo( 'text' ), "  Hola <b>mundo</b>\n " ) );
		$this->assertSame( 1000, mb_strlen( $tipo->sanitize_value( $this->campo( 'text' ), str_repeat( 'ñ', 5000 ) ) ) );
		$this->assertSame( '', $tipo->sanitize_value( $this->campo( 'text' ), array( 'no', 'es', 'texto' ) ) );
		$this->assertSame( '', $tipo->sanitize_value( $this->campo( 'text' ), null ) );
	}

	public function test_el_correo_exige_una_direccion_valida() {
		$tipo = new Types\Email_Field_Type( $this->markup, $this->comunes, $this->prellenado() );

		$this->assertNull( $tipo->validate( $this->campo( 'email' ), 'ana@example.org' ) );
		$this->assertNotNull( $tipo->validate( $this->campo( 'email' ), 'ana@' ) );
		$this->assertNotNull( $tipo->validate( $this->campo( 'email' ), 'no es un correo' ) );
	}

	public function test_el_telefono_admite_los_formatos_habituales() {
		$tipo = new Types\Tel_Field_Type( $this->markup, $this->comunes, $this->prellenado() );

		$this->assertNull( $tipo->validate( $this->campo( 'tel' ), '+34 600 11 22 33' ) );
		$this->assertNull( $tipo->validate( $this->campo( 'tel' ), '(981) 12-34-56' ) );
		$this->assertNotNull( $tipo->validate( $this->campo( 'tel' ), '1234' ) );
		$this->assertNotNull( $tipo->validate( $this->campo( 'tel' ), 'llámame' ) );
	}

	public function test_la_direccion_web_completa_el_esquema_y_rechaza_los_peligrosos() {
		$tipo  = new Types\Url_Field_Type( $this->markup, $this->comunes, $this->prellenado() );
		$campo = $this->campo( 'url' );

		$this->assertSame( 'https://ejemplo.com/pagina', $tipo->sanitize_value( $campo, 'ejemplo.com/pagina' ) );
		$this->assertNull( $tipo->validate( $campo, 'https://ejemplo.com/pagina?a=1' ) );
		$this->assertNotNull( $tipo->validate( $campo, $tipo->sanitize_value( $campo, 'javascript://alert(1)' ) ) );
		$this->assertNotNull( $tipo->validate( $campo, 'https://' ) );
	}

	public function test_el_numero_acepta_coma_decimal_y_rechaza_texto() {
		$tipo  = new Types\Number_Field_Type( $this->markup, $this->comunes, $this->prellenado() );
		$campo = $this->campo( 'number' );

		$this->assertSame( '1234.5', $tipo->sanitize_value( $campo, '1 234,5' ) );
		$this->assertNull( $tipo->validate( $campo, '-12.75' ) );
		$this->assertNotNull( $tipo->validate( $campo, '12abc' ) );
		$this->assertNotNull( $tipo->validate( $campo, '1e5' ) );
	}

	public function test_la_fecha_debe_existir_y_se_muestra_con_el_formato_del_sitio() {
		$tipo  = new Types\Date_Field_Type( $this->markup, $this->comunes, $this->prellenado() );
		$campo = $this->campo( 'date' );

		update_option( 'date_format', 'd/m/Y' );

		$this->assertNull( $tipo->validate( $campo, '2026-10-09' ) );
		$this->assertNotNull( $tipo->validate( $campo, '2026-02-30' ) );
		$this->assertNotNull( $tipo->validate( $campo, '09/10/2026' ) );
		$this->assertSame( '09/10/2026', $tipo->format_value( $campo, '2026-10-09' ) );
	}

	public function test_el_area_de_texto_conserva_los_saltos_de_linea() {
		$tipo = new Types\Textarea_Field_Type( $this->markup, $this->comunes, $this->prellenado() );

		$this->assertSame( "Línea 1\nLínea 2", $tipo->sanitize_value( $this->campo( 'textarea' ), "Línea 1\nLínea 2<script>alert(1)</script>" ) );
	}

	public function test_los_campos_de_opciones_solo_aceptan_valores_de_su_lista() {
		$opciones = array( 'options' => array( array( 'label' => 'Ventas', 'value' => 'ventas' ), array( 'label' => 'Soporte', 'value' => 'soporte' ) ) );
		$select   = new Types\Select_Field_Type( $this->markup, $this->comunes );
		$casillas = new Types\Checkboxes_Field_Type( $this->markup, $this->comunes );

		$this->assertNull( $select->validate( $this->campo( 'select', $opciones ), 'ventas' ) );
		$this->assertNotNull( $select->validate( $this->campo( 'select', $opciones ), 'direccion' ) );
		$this->assertSame( 'Ventas', $select->format_value( $this->campo( 'select', $opciones ), 'ventas' ) );

		$valores = $casillas->sanitize_value( $this->campo( 'checkboxes', $opciones ), array( 'ventas', 'soporte', 'ventas', array( 'x' ) ) );

		$this->assertSame( array( 'ventas', 'soporte' ), $valores );
		$this->assertNull( $casillas->validate( $this->campo( 'checkboxes', $opciones ), $valores ) );
		$this->assertNotNull( $casillas->validate( $this->campo( 'checkboxes', $opciones ), array( 'ventas', 'inventado' ) ) );
		$this->assertSame( 'Ventas, Soporte', $casillas->format_value( $this->campo( 'checkboxes', $opciones ), $valores ) );
		$this->assertSame( array(), $casillas->sanitize_value( $this->campo( 'checkboxes', $opciones ), 'ventas' ) );
	}

	public function test_el_consentimiento_registra_el_texto_que_se_acepto() {
		$tipo  = new Types\Consent_Field_Type( $this->markup, $this->comunes, new Inline_Html() );
		$campo = $this->campo( 'consent', array( 'text' => 'Acepto la <a href="/privacidad">política de privacidad</a>.' ) );

		$this->assertSame( '1', $tipo->sanitize_value( $campo, '1' ) );
		$this->assertSame( '', $tipo->sanitize_value( $campo, 'on' ) );
		$this->assertSame( 'Accepted: Acepto la política de privacidad.', $tipo->format_value( $campo, '1' ) );
		$this->assertStringContainsString( '<a href="/privacidad">política de privacidad</a>', $tipo->render( $campo, $this->contexto ) );
	}

	public function test_un_campo_oculto_de_valor_fijo_ignora_lo_que_envie_el_visitante_y_no_viaja_en_el_html() {
		$tipo  = new Types\Hidden_Field_Type( $this->markup, $this->comunes, new Context_Resolver( new Consulta_Falsa() ) );
		$campo = $this->campo( 'hidden', array( 'source' => 'fixed', 'value' => 'campaña-otoño' ) );

		$this->assertSame( 'campaña-otoño', $tipo->sanitize_value( $campo, 'manipulado' ) );
		$this->assertSame( 'campaña-otoño', $tipo->sanitize_value( $campo, null ) );
		$this->assertSame( '', $tipo->render( $campo, $this->contexto ) );
	}

	public function test_un_campo_oculto_de_contexto_lleva_el_parametro_de_la_url_y_su_clave_para_el_script() {
		$tipo  = new Types\Hidden_Field_Type( $this->markup, $this->comunes, new Context_Resolver( new Consulta_Falsa( array( 'utm_source' => 'bo"letín' ) ) ) );
		$campo = $this->campo( 'hidden', array( 'source' => 'utm_source' ) );
		$html  = $tipo->render( $campo, $this->contexto );

		$this->assertStringContainsString( 'data-fm-context="query:utm_source"', $html );
		$this->assertStringContainsString( 'value="bo&quot;letín"', $html );
		$this->assertSame( '', $tipo->sanitize_value( $this->campo( 'hidden', array( 'source' => 'page_url' ) ), 'javascript:alert(1)' ) );
	}

	public function test_el_html_de_un_campo_escapa_etiqueta_ayuda_y_valor_prellenado() {
		$tipo  = new Types\Text_Field_Type( $this->markup, $this->comunes, $this->prellenado( array( 'nombre' => '"><script>alert(1)</script>' ) ) );
		$campo = new Field(
			'campo',
			'text',
			array(
				'label'          => '<img src=x onerror=alert(1)>',
				'help'           => '<script>alert(2)</script>',
				'placeholder'    => '" onfocus="alert(3)',
				'required'       => true,
				'prefill_source' => 'query',
				'prefill_value'  => 'nombre',
			)
		);
		$html  = $tipo->render( $campo, $this->contexto );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '" onfocus="', $html );
		$this->assertStringContainsString( 'id="fm-5-1-campo"', $html );
		$this->assertStringContainsString( 'for="fm-5-1-campo"', $html );
		$this->assertStringContainsString( 'name="fm[campo]"', $html );
		$this->assertStringContainsString( ' required', $html );
		$this->assertStringContainsString( 'aria-describedby="fm-5-1-campo-help"', $html );
		$this->assertStringContainsString( 'data-fm-prefill-query="nombre"', $html );
	}

	public function test_el_prellenado_con_datos_del_usuario_solo_funciona_con_sesion_iniciada() {
		$campo = new Field( 'campo', 'email', array( 'prefill_source' => 'user', 'prefill_user_field' => 'user_email' ) );

		wp_set_current_user( 0 );
		$this->assertSame( '', $this->prellenado()->resolve( $campo ) );

		$id = self::factory()->user->create( array( 'user_email' => 'socio@example.org' ) );
		wp_set_current_user( $id );
		$this->assertSame( 'socio@example.org', $this->prellenado()->resolve( $campo ) );

		$prohibido = new Field( 'campo', 'text', array( 'prefill_source' => 'user', 'prefill_user_field' => 'user_pass' ) );
		$this->assertSame( '', $this->prellenado()->resolve( $prohibido ), 'Solo se exponen los datos de la lista cerrada.' );
	}

	public function test_los_grupos_de_radios_se_pintan_como_fieldset_con_leyenda() {
		$tipo = new Types\Radio_Field_Type( $this->markup, $this->comunes );
		$html = $tipo->render(
			$this->campo( 'radio', array( 'required' => true, 'options' => array( array( 'label' => 'Sí', 'value' => 'si' ), array( 'label' => 'No', 'value' => 'no' ) ) ) ),
			$this->contexto
		);

		$this->assertStringContainsString( '<fieldset', $html );
		$this->assertStringContainsString( '<legend class="fm-label">Etiqueta', $html );
		$this->assertSame( 2, substr_count( $html, 'type="radio"' ) );
		$this->assertSame( 1, substr_count( $html, ' required' ), 'Basta con un radio obligatorio por grupo.' );
	}

	public function test_el_esquema_sanea_cada_opcion_segun_su_tipo_de_control() {
		$saneador = new Settings_Schema_Sanitizer( new Inline_Html() );
		$esquema  = ( new Types\Select_Field_Type( $this->markup, $this->comunes ) )->get_settings_schema();

		$limpio = $saneador->sanitize(
			$esquema,
			array(
				'label'        => '<b>Motivo</b>',
				'required'     => 'true',
				'autocomplete' => 'inventado',
				'css_class'    => 'ancho-medio "><script>',
				'options'      => array( array( 'label' => 'Uno' ), array( 'label' => '', 'value' => '' ), array( 'label' => 'Uno' ), 'basura', array( 'label' => 'Dos', 'value' => '2' ) ),
				'intrusa'      => 'no está en el esquema',
			)
		);

		$this->assertSame( 'Motivo', $limpio['label'] );
		$this->assertTrue( $limpio['required'] );
		$this->assertSame( '', $limpio['autocomplete'], 'Un valor fuera de la lista vuelve al valor por defecto.' );
		$this->assertSame( 'ancho-medio script', $limpio['css_class'] );
		$this->assertSame( array( array( 'label' => 'Uno', 'value' => 'Uno' ), array( 'label' => 'Dos', 'value' => '2' ) ), $limpio['options'] );
		$this->assertArrayNotHasKey( 'intrusa', $limpio );
	}

	public function test_el_parrafo_solo_admite_formato_basico() {
		$tipo = new Types\Paragraph_Field_Type( $this->markup, $this->comunes, new Inline_Html() );
		$html = $tipo->render( new Field( 'p', 'paragraph', array( 'content' => 'Hola <strong>mundo</strong><script>alert(1)</script> <a href="javascript:alert(1)" onclick="x()">enlace</a>' ) ), $this->contexto );

		$this->assertStringContainsString( '<strong>mundo</strong>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}
}
