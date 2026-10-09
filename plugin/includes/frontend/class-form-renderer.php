<?php
/**
 * HTML de un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Frontend;

use Formulario_Mensaje\Antispam\Honeypot_Check;
use Formulario_Mensaje\Captcha\Captcha_Service;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Fields\Step_Layout;
use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Style;
use Formulario_Mensaje\Rest\Rest_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Genera el HTML accesible de un formulario. Es el mismo para el shortcode, el bloque y la vista previa.
 *
 * El HTML no contiene nada que caduque (ni nonce ni token), así que una caché de página completa puede
 * servirlo sin que el formulario deje de funcionar.
 */
final class Form_Renderer {

	/**
	 * Valor del campo `action` del POST sin JavaScript, y nombre de su hook en `admin-post.php`.
	 */
	public const FALLBACK_ACTION = 'formulario_mensaje_submit';

	/**
	 * Nombre del campo que lleva el identificador del formulario en el POST sin JavaScript.
	 */
	public const FORM_ID_FIELD = 'fm_form_id';

	/**
	 * Veces que se ha pintado un formulario en esta petición, para que los `id` no se repitan.
	 *
	 * @var int
	 */
	private int $instances = 0;

	/**
	 * Constructor.
	 *
	 * @param Field_Type_Registry $field_types Tipos de campo.
	 * @param Field_Markup        $markup      HTML común de los campos.
	 * @param Captcha_Service     $captcha     Captcha de los formularios.
	 * @param Form_Style          $style       Estilo de los formularios.
	 * @param Step_Layout         $steps       Pasos de los formularios.
	 */
	public function __construct(
		private readonly Field_Type_Registry $field_types,
		private readonly Field_Markup $markup,
		private readonly Captcha_Service $captcha,
		private readonly Form_Style $style,
		private readonly Step_Layout $steps
	) {}

	/**
	 * Devuelve el HTML de un formulario, con todos los valores escapados.
	 *
	 * @param Form                 $form         Formulario.
	 * @param string               $confirmation Mensaje de éxito en HTML ya escapado, si se acaba de completar un envío sin JavaScript.
	 * @param bool                 $show_form    Si se pinta el formulario además del mensaje de éxito.
	 * @param bool                 $interactive  Si el formulario puede enviarse; la vista previa del constructor lo pinta inerte.
	 * @param array<string, mixed> $embed_style Estilo del sitio donde se inserta (el bloque); tiene prioridad sobre el del formulario.
	 */
	public function render( Form $form, string $confirmation = '', bool $show_form = true, bool $interactive = true, array $embed_style = array() ): string {
		++$this->instances;

		$instance = $form->id . '-' . $this->instances;

		// El estilo son propiedades CSS con valores ya validados (colores hexadecimales y píxeles).
		$css = $this->style->css( $form->definition->style, Form_Style::SCOPE_FORM ) . $this->style->css( $embed_style, Form_Style::SCOPE_EMBED );

		return sprintf(
			'<div%1$s>%2$s<div class="fm-success" role="status" tabindex="-1"%3$s>%4$s</div></div>',
			$this->markup->attributes(
				array(
					'class' => 'formulario-mensaje' . ( $this->style->styles_button( $form->definition->style, $embed_style ) ? ' fm-button-styled' : '' ),
					'id'    => 'formulario-mensaje-' . $instance,
					'style' => $css,
				)
			),
			$show_form ? $this->form( $form, new Render_Context( $form->id, $instance ), $interactive ) : '',
			'' === $confirmation ? ' hidden' : '',
			$confirmation
		);
	}

	/**
	 * Etiqueta `<form>` con sus campos.
	 *
	 * @param Form           $form        Formulario.
	 * @param Render_Context $context     Contexto de pintado.
	 * @param bool           $interactive Si el formulario puede enviarse.
	 */
	private function form( Form $form, Render_Context $context, bool $interactive ): string {
		$steps        = $this->steps->steps( $form->definition );
		$fields       = '';
		$has_required = false;

		$has_uploads = false;

		foreach ( $form->definition->fields as $field ) {
			$has_required = $has_required || $field->is_required();
			$has_uploads  = $has_uploads || null !== $this->field_types->get_upload( $field->type );
		}

		if ( array() === $steps ) {
			$fields = $this->fields( $form->definition->fields, $context );
		}

		// Sin JavaScript, cada paso es una sección más del formulario, con su título.
		foreach ( $steps as $index => $step ) {
			$fields .= sprintf(
				'<div class="fm-step" data-fm-step="%1$d"%2$s>%3$s%4$s</div>',
				$index,
				null === $step['marker'] ? '' : $this->markup->condition_attribute( $step['marker'] ),
				null === $step['marker'] ? '' : $this->fields( array( $step['marker'] ), $context ),
				$this->fields( $step['fields'], $context )
			);
		}

		$attributes = array(
			'class'      => 'fm-form',
			'method'     => 'post',
			'aria-label' => $form->title,
		);

		// Sin esto, el envío sin JavaScript mandaría el nombre de los ficheros y no los ficheros.
		if ( $has_uploads ) {
			$attributes['enctype'] = 'multipart/form-data';
		}

		if ( $interactive && array() !== $steps ) {
			// Lo único de un paso que se comprueba en el navegador: que un fichero obligatorio esté elegido.
			$attributes['data-fm-required-message'] = __( 'This field is required.', 'formulario-mensaje' );
			$attributes['data-fm-steps']            = count( $steps );
			/* translators: {current} and {total} are replaced by the number of the current step and the number of steps; keep them as they are. */
			$attributes['data-fm-step-text'] = __( 'Step {current} of {total}', 'formulario-mensaje' );
		}

		if ( $interactive ) {
			$attributes['action']                = admin_url( 'admin-post.php' );
			$attributes['data-fm-form']          = $form->id;
			$attributes['data-fm-endpoint']      = rest_url( Rest_Provider::ROUTE_NAMESPACE . '/forms/' . $form->id );
			$attributes['data-fm-error-message'] = __( 'Your message could not be sent. Check your connection and try again.', 'formulario-mensaje' );
		}

		// Con captcha, el script carga su módulo y el del servicio cuando el visitante empieza a usar el formulario.
		$captcha = $interactive ? $this->captcha->client_config( $form ) : null;

		if ( null !== $captcha ) {
			$attributes['data-fm-captcha']         = $captcha['provider'];
			$attributes['data-fm-captcha-key']     = $captcha['site_key'];
			$attributes['data-fm-captcha-message'] = __( 'Complete the security check before sending the form.', 'formulario-mensaje' );
		}

		return sprintf(
			'<form%1$s><div class="fm-status" role="alert" tabindex="-1" hidden></div>%2$s%8$s%3$s%4$s%5$s%7$s<div class="fm-actions">%9$s<button type="submit" class="fm-submit wp-element-button">%6$s</button></div></form>',
			$this->markup->attributes( $attributes ),
			$has_required ? $this->required_note() : '',
			$fields,
			$this->honeypot( $context ),
			$interactive ? $this->fallback_fields( $form ) : '',
			esc_html( (string) $form->definition->setting( 'submit_label', __( 'Send', 'formulario-mensaje' ) ) ),
			// Hueco donde el servicio pinta su casilla, si la tiene.
			null === $captcha ? '' : '<div class="fm-captcha" data-fm-captcha-slot></div>',
			$interactive && array() !== $steps ? $this->progress() : '',
			$interactive && array() !== $steps ? $this->step_buttons() : ''
		);
	}

	/**
	 * HTML de una lista de campos.
	 *
	 * @param Field[]        $fields  Campos.
	 * @param Render_Context $context Formulario e instancia en los que se pintan.
	 */
	private function fields( array $fields, Render_Context $context ): string {
		$html = '';

		foreach ( $fields as $field ) {
			// Un campo de un tipo que ya no está registrado no se pinta.
			$html .= $this->field_types->get( $field->type )?->render( $field, $context ) ?? '';
		}

		return $html;
	}

	/**
	 * Barra de progreso de un formulario por pasos. Oculta hasta que el script la pone en marcha.
	 */
	private function progress(): string {
		return '<div class="fm-progress" hidden><div class="fm-progress__bar" role="progressbar" aria-valuemin="1" aria-valuemax="1" aria-valuenow="1"><span class="fm-progress__fill"></span></div><p class="fm-progress__text" aria-live="polite"></p></div>';
	}

	/**
	 * Botones para moverse entre los pasos. Ocultos hasta que el script los pone en marcha: sin
	 * JavaScript los pasos se ven todos seguidos y solo hace falta el botón de enviar.
	 */
	private function step_buttons(): string {
		return sprintf(
			'<button type="button" class="fm-prev wp-element-button" hidden>%1$s</button><button type="button" class="fm-next wp-element-button" hidden>%2$s</button>',
			esc_html__( 'Previous', 'formulario-mensaje' ),
			esc_html__( 'Next', 'formulario-mensaje' )
		);
	}

	/**
	 * Aviso de qué significa el asterisco de los campos obligatorios.
	 */
	private function required_note(): string {
		return sprintf(
			'<p class="fm-required-note">%s</p>',
			sprintf(
				/* translators: %s: the asterisk that marks required fields. */
				esc_html__( 'Fields marked with %s are required.', 'formulario-mensaje' ),
				// Aquí el asterisco sí se lee en voz alta: es justo lo que el aviso explica.
				'<span class="fm-required-mark">*</span>'
			)
		);
	}

	/**
	 * Campo trampa: fuera de la vista y del orden de tabulación, con una etiqueta por si alguien llega a verlo.
	 *
	 * @param Render_Context $context Contexto de pintado.
	 */
	private function honeypot( Render_Context $context ): string {
		$id = 'fm-' . $context->instance_id . '-website';

		return sprintf(
			'<div class="fm-hp" aria-hidden="true"><label for="%1$s">%2$s</label><input type="text" id="%1$s" name="%3$s" value="" tabindex="-1" autocomplete="off"></div>',
			esc_attr( $id ),
			esc_html__( 'Leave this field empty', 'formulario-mensaje' ),
			esc_attr( Honeypot_Check::FIELD_NAME )
		);
	}

	/**
	 * Campos que necesita el envío como POST normal, cuando no hay JavaScript.
	 *
	 * @param Form $form Formulario.
	 */
	private function fallback_fields( Form $form ): string {
		return sprintf(
			'<input type="hidden" name="action" value="%1$s"><input type="hidden" name="%2$s" value="%3$d">',
			esc_attr( self::FALLBACK_ACTION ),
			esc_attr( self::FORM_ID_FIELD ),
			$form->id
		);
	}
}
