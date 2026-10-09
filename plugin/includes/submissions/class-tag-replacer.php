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

	private const FORMAT_TEXT  = 'text';
	private const FORMAT_HTML  = 'html';
	private const FORMAT_EMAIL = 'email';

	/**
	 * Sustituye las etiquetas para un texto plano. Los valores se insertan sin escapar.
	 *
	 * @param string     $template   Texto con etiquetas.
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 */
	public function to_text( string $template, Submission $submission, Form $form ): string {
		return $this->replace( $template, $submission, $form, self::FORMAT_TEXT );
	}

	/**
	 * Sustituye las etiquetas para un fragmento de HTML de la web. Los valores se insertan escapados.
	 *
	 * @param string     $template   HTML con etiquetas, ya filtrado.
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 */
	public function to_html( string $template, Submission $submission, Form $form ): string {
		return $this->replace( $template, $submission, $form, self::FORMAT_HTML );
	}

	/**
	 * Sustituye las etiquetas para el cuerpo de un correo en HTML. Los valores se insertan escapados
	 * y `{all_fields}` se pinta como una tabla con estilos en línea.
	 *
	 * @param string     $template   HTML con etiquetas, ya escapado.
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 */
	public function to_email_html( string $template, Submission $submission, Form $form ): string {
		return $this->replace( $template, $submission, $form, self::FORMAT_EMAIL );
	}

	/**
	 * Sustituye las etiquetas de un texto.
	 *
	 * @param string     $template   Texto con etiquetas.
	 * @param Submission $submission Envío.
	 * @param Form       $form       Formulario.
	 * @param string     $format     Formato del resultado: una de las constantes FORMAT_*.
	 */
	private function replace( string $template, Submission $submission, Form $form, string $format ): string {
		$escape = static fn ( string $value ): string => self::FORMAT_TEXT === $format ? $value : nl2br( esc_html( $value ) );

		/*
		 * Una sola pasada sobre el texto: lo que un visitante escriba en un campo se inserta tal cual
		 * y no se vuelve a interpretar, aunque contenga algo con forma de etiqueta.
		 */
		return (string) preg_replace_callback(
			'/\{(all_fields|reference|form_title|site_name|page_url|date|field:([a-z][a-z0-9_]{0,31}))\}/',
			fn ( array $tag ): string => match ( $tag[1] ) {
				'all_fields' => $this->all_fields( $submission, $format ),
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
	 * Todos los campos con dato, con su etiqueta.
	 *
	 * @param Submission $submission Envío.
	 * @param string     $format     Formato del resultado.
	 */
	private function all_fields( Submission $submission, string $format ): string {
		$lines = array();

		foreach ( $submission->entries as $entry ) {
			if ( '' === $entry->formatted ) {
				continue;
			}

			$lines[] = match ( $format ) {
				self::FORMAT_EMAIL => sprintf(
					'<tr><th align="left" valign="top" style="width:35%%;padding:10px 16px 10px 0;border-bottom:1px solid #e0e0e0;font-weight:600;text-align:left;">%s</th><td valign="top" style="padding:10px 0;border-bottom:1px solid #e0e0e0;">%s</td></tr>',
					esc_html( $entry->label ),
					nl2br( esc_html( $entry->formatted ) )
				),
				self::FORMAT_HTML  => sprintf( '<strong>%s:</strong> %s', esc_html( $entry->label ), nl2br( esc_html( $entry->formatted ) ) ),
				default            => $entry->label . ': ' . $entry->formatted,
			};
		}

		return match ( $format ) {
			self::FORMAT_EMAIL => array() === $lines ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' . implode( '', $lines ) . '</table>',
			self::FORMAT_HTML  => implode( '<br>', $lines ),
			default            => implode( "\n", $lines ),
		};
	}
}
