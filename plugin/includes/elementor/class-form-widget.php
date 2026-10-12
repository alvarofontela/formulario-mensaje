<?php
/**
 * Widget de Elementor: formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Elementor;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use Formulario_Mensaje\Frontend\Form_Embed;
use Formulario_Mensaje\Frontend\Frontend_Assets;
use Formulario_Mensaje\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Inserta un formulario en una página de Elementor.
 *
 * Es un adaptador: el HTML es el mismo que el del bloque y el shortcode, y los controles de estilo
 * solo dan valor a las propiedades CSS del formulario. Elementor crea las instancias de esta clase
 * por su cuenta, así que no puede recibir sus dependencias por el constructor: las pide al
 * contenedor del plugin, que es la única excepción al «sin acceso estático» del proyecto.
 */
final class Form_Widget extends Widget_Base {

	/**
	 * Nombre interno del widget.
	 */
	public function get_name(): string {
		return 'formulario-mensaje';
	}

	/**
	 * Nombre que se ve en el panel de Elementor.
	 */
	public function get_title(): string {
		return __( 'Form (Formulario Mensaje)', 'formulario-mensaje' );
	}

	/**
	 * Icono del widget.
	 */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	/**
	 * Categorías del panel en las que aparece.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( 'general' );
	}

	/**
	 * Palabras por las que se encuentra en el buscador del panel.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'form', 'contact', 'formulario', 'contacto' );
	}

	/**
	 * Hojas de estilo que necesita, ya registradas por el plugin.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( Frontend_Assets::STYLE_HANDLE );
	}

	/**
	 * Registra los controles del widget.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_layout_controls();
		$this->register_label_controls();
		$this->register_field_controls();
		$this->register_button_controls();
	}

	/**
	 * Pinta el formulario elegido.
	 */
	protected function render(): void {
		$form_id = absint( $this->get_settings_for_display( 'form_id' ) );

		if ( 0 === $form_id ) {
			// Sin formulario elegido solo se avisa a quien está montando la página.
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				printf( '<p class="fm-unavailable">%s</p>', esc_html__( 'Choose the form to show in the settings of this widget.', 'formulario-mensaje' ) );
			}

			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML que compone el plugin, con cada valor ya escapado.
		echo Plugin::service( Form_Embed::class )->render( $form_id );
	}

	/**
	 * Contenido: qué formulario se muestra.
	 */
	private function register_content_controls(): void {
		// Elementor inserta el texto de cada opción como HTML: llegan ya escapados.
		$options = Plugin::service( Form_Choices::class )->options();

		$this->start_controls_section(
			'section_form',
			array(
				'label' => __( 'Form', 'formulario-mensaje' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'form_id',
			array(
				'label'       => __( 'Form', 'formulario-mensaje' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $options,
				'default'     => '0',
				'description' => __( 'Forms are created in the WordPress Forms menu.', 'formulario-mensaje' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Estilo: separación y bordes.
	 */
	private function register_layout_controls(): void {
		$this->start_controls_section(
			'section_layout',
			array(
				'label' => __( 'Spacing and borders', 'formulario-mensaje' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_size_control( 'gap', __( 'Space between fields', 'formulario-mensaje' ), 64, '--fm-embed-gap: {{SIZE}}{{UNIT}};' );
		$this->add_size_control( 'field_padding', __( 'Field padding', 'formulario-mensaje' ), 32, '--fm-embed-control-padding: {{SIZE}}{{UNIT}} calc({{SIZE}}{{UNIT}} * 1.25);' );
		$this->add_size_control( 'border_width', __( 'Border width', 'formulario-mensaje' ), 8, '--fm-embed-border-width: {{SIZE}}{{UNIT}};' );
		$this->add_size_control( 'border_radius', __( 'Border radius', 'formulario-mensaje' ), 40, '--fm-embed-border-radius: {{SIZE}}{{UNIT}};' );
		$this->add_color_control( 'border_color', __( 'Border color', 'formulario-mensaje' ), array( '{{WRAPPER}}' => '--fm-embed-border-color: {{VALUE}};' ) );
		$this->add_color_control( 'focus_color', __( 'Focus outline color', 'formulario-mensaje' ), array( '{{WRAPPER}}' => '--fm-embed-focus-color: {{VALUE}};' ) );

		$this->end_controls_section();
	}

	/**
	 * Estilo: etiquetas.
	 */
	private function register_label_controls(): void {
		$this->start_controls_section(
			'section_labels',
			array(
				'label' => __( 'Labels', 'formulario-mensaje' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_color_control( 'label_color', __( 'Text color', 'formulario-mensaje' ), array( '{{WRAPPER}}' => '--fm-embed-label-color: {{VALUE}};' ) );
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .formulario-mensaje .fm-label',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Estilo: controles del formulario.
	 */
	private function register_field_controls(): void {
		$this->start_controls_section(
			'section_fields',
			array(
				'label' => __( 'Fields', 'formulario-mensaje' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_color_control( 'field_color', __( 'Text color', 'formulario-mensaje' ), array( '{{WRAPPER}}' => '--fm-embed-field-color: {{VALUE}};' ) );
		$this->add_color_control( 'field_background', __( 'Background color', 'formulario-mensaje' ), array( '{{WRAPPER}}' => '--fm-embed-field-background: {{VALUE}};' ) );
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'field_typography',
				'selector' => '{{WRAPPER}} .formulario-mensaje .fm-control',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Estilo: botón de envío.
	 */
	private function register_button_controls(): void {
		$this->start_controls_section(
			'section_button',
			array(
				'label' => __( 'Button', 'formulario-mensaje' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// Sobre el propio botón: así vale también cuando el formulario no le pone color y lo hereda del tema.
		$this->add_color_control( 'button_background', __( 'Background color', 'formulario-mensaje' ), array( '{{WRAPPER}} .formulario-mensaje .fm-submit' => 'background-color: {{VALUE}}; border-color: transparent;' ) );
		$this->add_color_control( 'button_color', __( 'Text color', 'formulario-mensaje' ), array( '{{WRAPPER}} .formulario-mensaje .fm-submit' => 'color: {{VALUE}};' ) );
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .formulario-mensaje .fm-submit',
			)
		);

		$this->add_control(
			'button_align',
			array(
				'label'     => __( 'Alignment', 'formulario-mensaje' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Start', 'formulario-mensaje' ),
						'icon'  => 'eicon-h-align-left',
					),
					'center'     => array(
						'title' => __( 'Center', 'formulario-mensaje' ),
						'icon'  => 'eicon-h-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'End', 'formulario-mensaje' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .formulario-mensaje .fm-actions' => 'justify-content: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Añade un control de medida que da valor a una propiedad CSS del formulario.
	 *
	 * @param string $name    Nombre del control.
	 * @param string $label   Etiqueta, ya traducida.
	 * @param int    $maximum Valor máximo en píxeles.
	 * @param string $css     Declaración CSS con los marcadores de Elementor.
	 */
	private function add_size_control( string $name, string $label, int $maximum, string $css ): void {
		$this->add_control(
			$name,
			array(
				'label'      => $label,
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => $maximum,
					),
				),
				'selectors'  => array( '{{WRAPPER}}' => $css ),
			)
		);
	}

	/**
	 * Añade un control de color.
	 *
	 * @param string                $name      Nombre del control.
	 * @param string                $label     Etiqueta, ya traducida.
	 * @param array<string, string> $selectors Selectores y declaraciones CSS con los marcadores de Elementor.
	 */
	private function add_color_control( string $name, string $label, array $selectors ): void {
		$this->add_control(
			$name,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::COLOR,
				'selectors' => $selectors,
			)
		);
	}
}
