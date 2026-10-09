<?php
/**
 * Estilo de los formularios: saneado, propiedades CSS, prioridad entre niveles y bloque.
 */

use Formulario_Mensaje\Forms\Form_Style;
use Formulario_Mensaje\Plugin;

class EstiloTest extends Caso_Formulario {

	public function test_el_estilo_es_una_lista_cerrada_de_colores_y_medidas() {
		$estilo = ( new Form_Style() )->sanitize(
			array(
				'label_color'       => '#AA0000',
				'field_background'  => '#fff',
				'border_color'      => 'red; } body { display: none',
				'button_background' => 'url(javascript:alert(1))',
				'button_color'      => 'rgb(0,0,0)',
				'gap'               => 24.4,
				'border_width'      => 99,
				'border_radius'     => -5,
				'field_padding'     => '12',
				'font_family'       => 'Comic Sans',
				'custom_css'        => 'body{}',
			)
		);

		$this->assertSame(
			array( 'label_color' => '#aa0000', 'field_background' => '#fff', 'gap' => 24, 'border_width' => 8, 'border_radius' => 0, 'field_padding' => 12 ),
			$estilo,
			'Solo quedan colores hexadecimales y medidas dentro de sus límites; lo demás se descarta.'
		);
		$this->assertSame( array(), ( new Form_Style() )->sanitize( 'basura' ) );
	}

	public function test_el_estilo_se_convierte_en_propiedades_css_de_su_nivel() {
		$estilo = new Form_Style();

		$this->assertSame(
			'--fm-form-label-color:#aa0000;--fm-form-gap:24px;--fm-form-control-padding:12px 15px;',
			$estilo->css( array( 'label_color' => '#aa0000', 'gap' => 24, 'field_padding' => 12 ), Form_Style::SCOPE_FORM )
		);
		$this->assertSame( '--fm-embed-border-radius:0px;', $estilo->css( array( 'border_radius' => 0 ), Form_Style::SCOPE_EMBED ) );
		$this->assertSame( '', $estilo->css( array(), Form_Style::SCOPE_FORM ) );

		// Lo que llega a css() sin pasar por el saneado no llega al HTML.
		$this->assertSame( '', $estilo->css( array( 'label_color' => '"><script>', 'gap' => 'x' ), Form_Style::SCOPE_FORM ) );
		$this->assertStringStartsWith( '--fm-form-', $estilo->css( array( 'gap' => 10 ), 'otro-nivel;color:red' ) );
	}

	public function test_el_estilo_del_formulario_se_guarda_y_llega_a_su_html() {
		$formulario = $this->crear_formulario(
			array( 'style' => array( 'label_color' => '#AA0000', 'gap' => 32, 'border_color' => 'no-es-un-color', 'button_background' => '#0055aa' ) )
		);

		$this->como_administrador();
		$this->assertSame(
			array( 'label_color' => '#aa0000', 'button_background' => '#0055aa', 'gap' => 32 ),
			$this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['style']
		);

		$html = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertMatchesRegularExpression( '#<div class="formulario-mensaje fm-button-styled" id="formulario-mensaje-[^"]+" style="--fm-form-label-color:\#aa0000;--fm-form-button-background:\#0055aa;--fm-form-gap:32px;">#', $html );

		// La vista previa del constructor usa el mismo render.
		$previa = $this->rest( 'POST', '/forms/preview', array( 'definition' => $this->definicion( array( 'style' => array( 'border_radius' => 12 ) ) ) ) )->get_data();
		$this->assertStringContainsString( 'style="--fm-form-border-radius:12px;"', $previa['html'] );
	}

	public function test_un_formulario_sin_estilo_no_lleva_atributo_style_ni_cambia_el_boton_del_tema() {
		$formulario = $this->crear_formulario();
		$html       = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertMatchesRegularExpression( '#<div class="formulario-mensaje" id="formulario-mensaje-[^"]+">#', $html );
		$this->assertStringNotContainsString( 'fm-button-styled', $html );
	}

	public function test_el_bloque_aplica_su_estilo_por_encima_del_del_formulario() {
		$formulario = $this->crear_formulario( array( 'style' => array( 'gap' => 32 ) ) );

		$html = do_blocks(
			sprintf(
				'<!-- wp:formulario-mensaje/form {"formId":%d,"formStyle":{"gap":8,"button_color":"#ffffff","label_color":"expression(alert(1))"},"style":{"color":{"text":"#123456"}}} /-->',
				$formulario
			)
		);

		// Los dos niveles viajan en el HTML; la hoja de estilos da prioridad al del sitio donde se inserta.
		$this->assertStringContainsString( '--fm-form-gap:32px;--fm-embed-button-color:#ffffff;--fm-embed-gap:8px;', $html );
		$this->assertStringContainsString( 'fm-button-styled', $html );
		$this->assertStringNotContainsString( 'expression', $html );
		// Los ajustes nativos del bloque (color de texto) van en su envoltorio.
		$this->assertStringContainsString( '<div style="color:#123456" class="wp-block-formulario-mensaje-form has-text-color">', $html );
	}

	public function test_la_hoja_de_estilos_resuelve_cada_propiedad_en_tres_niveles() {
		$css = (string) file_get_contents( FORMULARIO_MENSAJE_DIR . '/assets/css/form.css' );

		foreach ( array( 'gap', 'control-padding', 'border-width', 'border-color', 'border-radius', 'focus-color', 'label-color', 'field-color', 'field-background', 'button-background', 'button-color' ) as $propiedad ) {
			$this->assertMatchesRegularExpression(
				sprintf( '/--fm-%1$s: var\(--fm-embed-%1$s, var\(--fm-form-%1$s[,)]/', preg_quote( $propiedad, '/' ) ),
				$css,
				"La propiedad $propiedad no se resuelve primero por el sitio donde se inserta y después por el formulario."
			);
		}
	}

	public function test_el_acceso_al_contenedor_para_adaptadores_entrega_los_servicios_del_plugin() {
		$this->assertInstanceOf( Formulario_Mensaje\Frontend\Form_Embed::class, Plugin::service( Formulario_Mensaje\Frontend\Form_Embed::class ) );
		$this->assertSame( Plugin::service( Form_Style::class ), Plugin::service( Form_Style::class ), 'Es el contenedor del plugin, no uno nuevo.' );
	}

	public function test_el_widget_de_elementor_no_se_carga_si_elementor_no_esta() {
		$this->assertFalse( class_exists( 'Elementor\Widget_Base', false ) );
		$this->assertFalse( class_exists( Formulario_Mensaje\Elementor\Form_Widget::class, false ), 'La clase del widget extiende una de Elementor: no debe cargarse sin él.' );
		$this->assertNotFalse( has_action( 'elementor/widgets/register' ) );
	}
}
