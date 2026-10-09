<?php
/**
 * Menú de administración.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Admin;

use Formulario_Mensaje\Lifecycle\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registra las pantallas del plugin en el menú de administración.
 *
 * Las pantallas son una aplicación de React: aquí solo se pinta el contenedor donde se monta.
 */
final class Admin_Menu {

	/**
	 * Slug de la pantalla de formularios, que es también la del menú.
	 */
	public const FORMS_PAGE = 'formulario-mensaje';

	/**
	 * Slug de la pantalla de ajustes.
	 */
	public const SETTINGS_PAGE = 'formulario-mensaje-settings';

	/**
	 * Identificadores (hook suffix) de las pantallas del plugin, con la vista que muestra cada una.
	 *
	 * @var array<string, string>
	 */
	private array $screens = array();

	/**
	 * Registra el menú y sus pantallas.
	 */
	public function register(): void {
		$forms = add_menu_page(
			__( 'Forms', 'formulario-mensaje' ),
			__( 'Forms', 'formulario-mensaje' ),
			Capabilities::MANAGE_FORMS,
			self::FORMS_PAGE,
			fn () => $this->render( 'forms' ),
			'dashicons-feedback',
			26
		);

		add_submenu_page(
			self::FORMS_PAGE,
			__( 'Forms', 'formulario-mensaje' ),
			__( 'All forms', 'formulario-mensaje' ),
			Capabilities::MANAGE_FORMS,
			self::FORMS_PAGE
		);

		$settings = add_submenu_page(
			self::FORMS_PAGE,
			__( 'Forms settings', 'formulario-mensaje' ),
			__( 'Settings', 'formulario-mensaje' ),
			Capabilities::MANAGE_SETTINGS,
			self::SETTINGS_PAGE,
			fn () => $this->render( 'settings' )
		);

		$this->screens = array_filter(
			array(
				(string) $forms    => 'forms',
				(string) $settings => 'settings',
			),
			static fn ( string $hook_suffix ): bool => '' !== $hook_suffix,
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Vista que corresponde a una pantalla de administración.
	 *
	 * @param string $hook_suffix Identificador de la pantalla actual.
	 * @return string|null `forms`, `settings` o null si la pantalla no es del plugin.
	 */
	public function view_for( string $hook_suffix ): ?string {
		return $this->screens[ $hook_suffix ] ?? null;
	}

	/**
	 * Pinta el contenedor de la aplicación.
	 *
	 * WordPress solo llama a este método si el usuario tiene la capability de la pantalla. El título
	 * lo pinta la aplicación; `wp-header-end` marca dónde coloca WordPress los avisos de otros plugins.
	 *
	 * @param string $view Vista inicial de la aplicación.
	 */
	private function render( string $view ): void {
		printf(
			'<div class="wrap"><hr class="wp-header-end"><div id="formulario-mensaje-admin" data-view="%1$s"><noscript><p>%2$s</p></noscript></div></div>',
			esc_attr( $view ),
			esc_html__( 'This screen needs JavaScript.', 'formulario-mensaje' )
		);
	}
}
