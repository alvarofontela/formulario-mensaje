<?php
/**
 * Tipo de campo: oculto.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Common_Settings;
use Formulario_Mensaje\Fields\Context_Resolver;
use Formulario_Mensaje\Fields\Field_Markup;
use Formulario_Mensaje\Fields\Input_Field_Type;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Support\Plain_Text;

defined( 'ABSPATH' ) || exit;

/**
 * Campo oculto: un valor fijo o un dato de contexto (página de origen, parámetros UTM o de la URL).
 */
final class Hidden_Field_Type implements Input_Field_Type {

	/**
	 * Longitud máxima del valor, en caracteres.
	 */
	private const MAX_LENGTH = 2000;

	/**
	 * Constructor.
	 *
	 * @param Field_Markup     $markup  HTML común de los campos.
	 * @param Common_Settings  $common  Opciones comunes de los campos.
	 * @param Context_Resolver $context Valores de contexto.
	 */
	public function __construct(
		private readonly Field_Markup $markup,
		private readonly Common_Settings $common,
		private readonly Context_Resolver $context
	) {}

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'hidden';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Hidden field', 'formulario-mensaje' );
	}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'advanced';
	}

	/**
	 * Opciones que admite un campo de este tipo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_schema(): array {
		$choices = array(
			$this->common->choice( Context_Resolver::SOURCE_FIXED, __( 'Fixed value', 'formulario-mensaje' ) ),
			$this->common->choice( Context_Resolver::SOURCE_PAGE_URL, __( 'Page address', 'formulario-mensaje' ) ),
			$this->common->choice( Context_Resolver::SOURCE_PAGE_TITLE, __( 'Page title', 'formulario-mensaje' ) ),
			$this->common->choice( Context_Resolver::SOURCE_REFERRER, __( 'Referring page', 'formulario-mensaje' ) ),
		);

		foreach ( Context_Resolver::UTM_PARAMS as $param ) {
			$choices[] = $this->common->choice(
				$param,
				/* translators: %s: name of a URL parameter such as utm_source. */
				sprintf( __( 'URL parameter: %s', 'formulario-mensaje' ), $param )
			);
		}

		$choices[] = $this->common->choice( Context_Resolver::SOURCE_QUERY, __( 'Other URL parameter', 'formulario-mensaje' ) );

		return array(
			$this->common->label( $this->get_label() ),
			array(
				'key'     => 'source',
				'type'    => 'select',
				'label'   => __( 'Value', 'formulario-mensaje' ),
				'default' => Context_Resolver::SOURCE_PAGE_URL,
				'choices' => $choices,
			),
			array(
				'key'     => 'value',
				'type'    => 'text',
				'label'   => __( 'Fixed value', 'formulario-mensaje' ),
				'default' => '',
				'show_if' => array(
					'key'    => 'source',
					'values' => array( Context_Resolver::SOURCE_FIXED ),
				),
			),
			array(
				'key'     => 'param',
				'type'    => 'text',
				'label'   => __( 'Parameter name', 'formulario-mensaje' ),
				'default' => '',
				'show_if' => array(
					'key'    => 'source',
					'values' => array( Context_Resolver::SOURCE_QUERY ),
				),
			),
		);
	}

	/**
	 * Devuelve el HTML del campo, con todos los valores ya escapados.
	 *
	 * Un valor fijo no viaja en el HTML: el servidor lo toma de la configuración al recibir el envío.
	 *
	 * @param Field          $field   Campo que se pinta.
	 * @param Render_Context $context Formulario e instancia en los que se pinta.
	 */
	public function render( Field $field, Render_Context $context ): string {
		$source = $this->source( $field );

		if ( Context_Resolver::SOURCE_FIXED === $source ) {
			/*
			 * Un valor fijo lo pone el servidor y no hace falta en la página, salvo que la condición de otro
			 * campo dependa de él: sin verlo, el navegador ocultaría un campo que el servidor va a exigir.
			 * Lo que llegue en la petición sigue sin contar (ver sanitize_value()).
			 */
			if ( ! $context->is_condition_source( $field ) ) {
				return '';
			}

			return sprintf(
				'<input%s>',
				$this->markup->attributes(
					array(
						'type'          => 'hidden',
						'id'            => $context->control_id( $field ),
						'name'          => $context->control_name( $field ),
						'data-fm-field' => $field->id,
						'value'         => $field->text( 'value' ),
					)
				)
			);
		}

		$param = $field->text( 'param' );

		return sprintf(
			'<input%s>',
			$this->markup->attributes(
				array(
					'type'            => 'hidden',
					'id'              => $context->control_id( $field ),
					'name'            => $context->control_name( $field ),
					// Sin envoltorio: el propio control dice de qué campo es, para las condiciones de otros campos.
					'data-fm-field'   => $field->id,
					'data-fm-context' => $this->context->client_key( $source, $param ),
				)
			)
			// El valor se imprime aparte: un valor vacío debe seguir siendo un atributo presente.
			. sprintf( ' value="%s"', esc_attr( $this->context->resolve( $source, $param ) ) )
		);
	}

	/**
	 * Sanea el valor recibido en un envío.
	 *
	 * @param Field $field Campo al que pertenece el valor.
	 * @param mixed $raw   Valor tal como llegó en la petición.
	 * @return string|string[]
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array {
		$source = $this->source( $field );

		// Un valor fijo sale siempre de la configuración: lo que diga la petición no cuenta.
		if ( Context_Resolver::SOURCE_FIXED === $source ) {
			return $field->text( 'value' );
		}

		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$value = mb_substr( Plain_Text::line( (string) $raw ), 0, self::MAX_LENGTH );

		// Las direcciones se limitan a http y https: el valor puede acabar como enlace en un correo.
		if ( in_array( $source, array( Context_Resolver::SOURCE_PAGE_URL, Context_Resolver::SOURCE_REFERRER ), true ) ) {
			return esc_url_raw( $value, array( 'http', 'https' ) );
		}

		return $value;
	}

	/**
	 * Valida el formato de un valor saneado y no vacío.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function validate( Field $field, string|array $value ): ?string {
		return null;
	}

	/**
	 * Convierte un valor saneado en un texto legible.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function format_value( Field $field, string|array $value ): string {
		return is_array( $value ) ? implode( ', ', $value ) : $value;
	}

	/**
	 * Origen del valor configurado en el campo.
	 *
	 * @param Field $field Campo.
	 */
	private function source( Field $field ): string {
		return $field->text( 'source', Context_Resolver::SOURCE_PAGE_URL );
	}

	/**
	 * Valor de ejemplo para probar notificaciones y otras acciones.
	 *
	 * @param Field $field Campo.
	 * @return string|string[]
	 */
	public function sample_value( Field $field ): string|array {
		return Context_Resolver::SOURCE_FIXED === $this->source( $field ) ? $field->text( 'value' ) : home_url( '/' );
	}
}
