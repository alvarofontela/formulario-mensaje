<?php
/**
 * Plantilla HTML de los correos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Envuelve el contenido de un correo en la plantilla con la marca del sitio: logotipo, color y pie.
 *
 * El HTML es deliberadamente simple (tablas y estilos en línea): es lo que entienden los clientes
 * de correo.
 */
final class Email_Template {

	/**
	 * Constructor.
	 *
	 * @param Mail_Settings_Repository $settings Ajustes de correo, con la marca.
	 */
	public function __construct( private readonly Mail_Settings_Repository $settings ) {}

	/**
	 * Devuelve el documento HTML de un correo.
	 *
	 * @param string $content Contenido en HTML, con todo dato externo ya escapado.
	 * @param string $title   Título del documento (el asunto), sin escapar.
	 */
	public function render( string $content, string $title ): string {
		$settings  = $this->settings->get();
		$color     = sanitize_hex_color( $settings->brand_color );
		$color     = is_string( $color ) && '' !== $color ? $color : Mail_Settings::DEFAULT_BRAND_COLOR;
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$header = '' !== $settings->brand_logo_url
			? sprintf( '<img src="%s" alt="%s" style="display:block;max-width:240px;max-height:72px;height:auto;border:0;">', esc_url( $settings->brand_logo_url ), esc_attr( $site_name ) )
			: sprintf( '<span style="font-size:20px;font-weight:600;color:#1d2327;">%s</span>', esc_html( $site_name ) );

		$footer = '' !== $settings->brand_footer ? nl2br( esc_html( $settings->brand_footer ) ) : esc_html( $site_name );

		return sprintf(
			'<!DOCTYPE html><html lang="%1$s"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>%2$s</title></head>'
			. '<body style="margin:0;padding:0;background-color:#f0f0f1;">'
			. '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="background-color:#f0f0f1;"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%%;max-width:600px;background-color:#ffffff;border-top:4px solid %3$s;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;">'
			. '<tr><td style="padding:24px 32px 8px;">%4$s</td></tr>'
			. '<tr><td style="padding:16px 32px 32px;">%5$s</td></tr>'
			. '<tr><td style="padding:16px 32px;background-color:#f6f7f7;font-size:12px;line-height:1.5;color:#646970;">%6$s</td></tr>'
			. '</table></td></tr></table></body></html>',
			esc_attr( get_bloginfo( 'language' ) ),
			esc_html( $title ),
			esc_attr( $color ),
			$header,
			$content,
			$footer
		);
	}
}
