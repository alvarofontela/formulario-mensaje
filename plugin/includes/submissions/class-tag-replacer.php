<?php
/**
 * Etiquetas en los textos configurables.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Forms\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Sustituye las etiquetas de un texto (asunto, cuerpo del correo, mensaje de éxito) por datos del envío.
 *
 * Etiquetas: `{field:ID}`, `{all_fields}`, `{reference}`, `{form_title}`, `{site_name}`, `{page_url}`
 * y `{date}`. Los datos del envío los escribe un visitante anónimo: para HTML se escapan aquí.
 */
final class Tag_Replacer {

	/**
	 * Sustituye las etiquetas para un texto plano. Los valores se insertan sin escapar.
	 *
	 * @param string     $template   Texto con etiquetas.
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 */
	public function to_text( string $template, Submission $submission, Form $form ): string {
		return $this->replace( $template, $submission, $form, false );
	}

	/**
	 * Sustituye las etiquetas para un fragmento de HTML. Los valores se insertan escapados.
	 *
	 * @param string     $template   HTML con etiquetas, ya filtrado.
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 */
	public function to_html( string $template, Submission $submission, Form $form ): string {
		return $this->replace( $template, $submission, $form, true );
	}

	/**
	 * Sustituye las etiquetas de un texto.
	 *
	 * @param string     $template   Texto con etiquetas.
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 * @param bool       $html       Si el resultado es HTML y los valores deben escaparse.
	 */
	private function replace( string $template, Submission $submission, Form $form, bool $html ): string {
		$escape = static fn ( string $value ): string => $html ? nl2br( esc_html( $value ) ) : $value;

		/*
		 * Una sola pasada sobre el texto: lo que un visitante escriba en un campo se inserta tal cual
		 * y no se vuelve a interpretar, aunque contenga algo con forma de etiqueta.
		 */
		return (string) preg_replace_callback(
			'/\{(all_fields|reference|form_title|site_name|page_url|date|field:([a-z][a-z0-9_]{0,31}))\}/',
			fn ( array $tag ): string => match ( $tag[1] ) {
				'all_fields' => $this->all_fields( $submission, $html ),
				'reference'  => $escape( $submission->reference ),
				'form_title' => $escape( $form->title ),
				'site_name'  => $escape( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				'page_url'   => $escape( $submission->page_url ),
				'date'       => $escape( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submission->created_at ) ),
				default      => $escape( $submission->formatted( $tag[2] ?? '' ) ),
			},
			$template
		);
	}

	/**
	 * Todos los campos con dato, uno por línea, con su etiqueta.
	 *
	 * @param Submission $submission Envío.
	 * @param bool       $html       Si el resultado es HTML.
	 */
	private function all_fields( Submission $submission, bool $html ): string {
		$lines = array();

		foreach ( $submission->entries as $entry ) {
			if ( '' === $entry->formatted ) {
				continue;
			}

			$lines[] = $html
				? sprintf( '<strong>%s:</strong> %s', esc_html( $entry->label ), nl2br( esc_html( $entry->formatted ) ) )
				: $entry->label . ': ' . $entry->formatted;
		}

		return implode( $html ? '<br>' : "\n", $lines );
	}
}
