<?php
/**
 * Datos de un webhook.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Submissions\Tag_Replacer;

defined( 'ABSPATH' ) || exit;

/**
 * Compone los datos que un webhook envía a su destino.
 *
 * Hay dos formas: la estándar, con todo el envío, y la personalizada, donde cada clave que espera
 * el destino se rellena con un texto con etiquetas.
 */
final class Webhook_Payload_Builder {

	public const MODE_ALL    = 'all';
	public const MODE_CUSTOM = 'custom';

	/**
	 * Constructor.
	 *
	 * @param Tag_Replacer $tags Etiquetas de los textos.
	 */
	public function __construct( private readonly Tag_Replacer $tags ) {}

	/**
	 * Compone los datos de un envío para un webhook.
	 *
	 * @param array<string, mixed> $settings   Ajustes del webhook.
	 * @param Submission           $submission Envío.
	 * @param Form                 $form       Formulario.
	 * @return array<string, mixed>
	 */
	public function build( array $settings, Submission $submission, Form $form ): array {
		$payload = self::MODE_CUSTOM === ( $settings['payload_mode'] ?? self::MODE_ALL )
			? $this->custom( $settings, $submission, $form )
			: $this->standard( $submission, $form );

		// El secreto de firma no sale del webhook: quien filtra los datos no lo necesita.
		unset( $settings['secret'] );

		/**
		 * Filtra los datos que un webhook envía a su destino.
		 *
		 * @since 0.4.0
		 *
		 * @param array<string, mixed> $payload    Datos que se envían, antes de convertirlos a JSON.
		 * @param Submission           $submission Envío.
		 * @param Form                 $form       Formulario.
		 * @param array<string, mixed> $settings   Ajustes del webhook, sin su secreto.
		 */
		$filtered = apply_filters( 'formulario_mensaje_webhook_payload', $payload, $submission, $form, $settings );

		return is_array( $filtered ) ? $filtered : $payload;
	}

	/**
	 * Datos estándar: el envío completo, con los valores por identificador de campo.
	 *
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 * @return array<string, mixed>
	 */
	private function standard( Submission $submission, Form $form ): array {
		$fields = array();
		$labels = array();

		foreach ( $submission->entries as $entry ) {
			$fields[ $entry->field_id ] = $entry->value;
			$labels[ $entry->field_id ] = $entry->label;
		}

		return array(
			'form'      => array(
				'id'    => $form->id,
				'title' => $form->title,
			),
			'reference' => $submission->reference,
			'date'      => gmdate( 'c', $submission->created_at ),
			'page_url'  => $submission->page_url,
			'fields'    => $fields,
			'labels'    => $labels,
		);
	}

	/**
	 * Datos personalizados: una clave por línea del mapeo, con su texto ya resuelto.
	 *
	 * @param array<string, mixed> $settings   Ajustes del webhook.
	 * @param Submission           $submission Envío.
	 * @param Form                 $form       Formulario.
	 * @return array<string, string>
	 */
	private function custom( array $settings, Submission $submission, Form $form ): array {
		$payload = array();

		foreach ( is_array( $settings['payload'] ?? null ) ? $settings['payload'] : array() as $item ) {
			if ( is_array( $item ) && is_string( $item['key'] ?? null ) && '' !== $item['key'] ) {
				$payload[ $item['key'] ] = $this->tags->to_text( is_string( $item['value'] ?? null ) ? $item['value'] : '', $submission, $form );
			}
		}

		return $payload;
	}
}
