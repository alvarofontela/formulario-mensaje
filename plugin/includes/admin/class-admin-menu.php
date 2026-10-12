<?php
/**
 * Menú de administración.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Admin;

use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Storage\Submission_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Registra las pantallas del plugin en el menú de administración.
 *
 * Las pantallas son una aplicación de React: aquí solo se pinta el contenedor donde se monta. Cada
 * pantalla tiene su capability, y el menú aparece para quien tenga al menos una.
 */
final class Admin_Menu {

	/**
	 * Slug de la pantalla de formularios.
	 */
	public const FORMS_PAGE = 'formulario-mensaje';

	/**
	 * Slug de la pantalla de envíos.
	 */
	public const SUBMISSIONS_PAGE = 'formulario-mensaje-submissions';

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
	 * Constructor.
	 *
	 * @param Submission_Service $submissions Servicio de envíos guardados.
	 */
	public function __construct( private readonly Submission_Service $submissions ) {}

	/**
	 * Registra el menú y sus pantallas.
	 */
	public function register(): void {
		// El globo de mensajes sin leer va en dos entradas del menú: se cuenta una sola vez.
		$bubble = $this->unread_bubble();
		$pages  = array_values(
			array_filter(
				$this->pages( $bubble ),
				static fn ( array $page ): bool => current_user_can( $page['capability'] )
			)
		);

		if ( array() === $pages ) {
			return;
		}

		// El menú abre la primera pantalla a la que el usuario tiene acceso.
		$parent = $pages[0];

		add_menu_page(
			__( 'Forms', 'formulario-mensaje' ),
			__( 'Forms', 'formulario-mensaje' ) . $bubble,
			$parent['capability'],
			$parent['slug'],
			'',
			'dashicons-feedback',
			26
		);

		foreach ( $pages as $page ) {
			$hook_suffix = add_submenu_page(
				$parent['slug'],
				$page['title'],
				$page['menu'],
				$page['capability'],
				$page['slug'],
				fn () => $this->render( $page['view'] )
			);

			if ( is_string( $hook_suffix ) ) {
				$this->screens[ $hook_suffix ] = $page['view'];
			}
		}
	}

	/**
	 * Vista que corresponde a una pantalla de administración.
	 *
	 * @param string $hook_suffix Identificador de la pantalla actual.
	 * @return string|null `forms`, `submissions`, `settings` o null si la pantalla no es del plugin.
	 */
	public function view_for( string $hook_suffix ): ?string {
		return $this->screens[ $hook_suffix ] ?? null;
	}

	/**
	 * Pantallas del plugin, en el orden del menú.
	 *
	 * @param string $bubble Globo con los mensajes sin leer, ya escapado, o cadena vacía.
	 * @return array<int, array{slug: string, view: string, capability: string, title: string, menu: string}>
	 */
	private function pages( string $bubble ): array {
		// Los mensajes van primero: es a lo que entra casi siempre quien usa el plugin en el día a día.
		return array(
			array(
				'slug'       => self::SUBMISSIONS_PAGE,
				'view'       => 'submissions',
				'capability' => Capabilities::VIEW_SUBMISSIONS,
				'title'      => __( 'Messages', 'formulario-mensaje' ),
				'menu'       => __( 'Messages', 'formulario-mensaje' ) . $bubble,
			),
			array(
				'slug'       => self::FORMS_PAGE,
				'view'       => 'forms',
				'capability' => Capabilities::MANAGE_FORMS,
				'title'      => __( 'Forms', 'formulario-mensaje' ),
				'menu'       => __( 'Forms', 'formulario-mensaje' ),
			),
			array(
				'slug'       => self::SETTINGS_PAGE,
				'view'       => 'settings',
				'capability' => Capabilities::MANAGE_SETTINGS,
				'title'      => __( 'Forms settings', 'formulario-mensaje' ),
				'menu'       => __( 'Settings', 'formulario-mensaje' ),
			),
		);
	}

	/**
	 * Aviso con el número de mensajes sin leer, para quien puede ver los envíos.
	 *
	 * Las entregas fallidas ya no se cuentan aquí: se ven en la bandeja, en el propio mensaje, y
	 * además avisan por correo.
	 *
	 * @return string HTML del aviso, o cadena vacía si no hay ninguno o el usuario no puede verlos.
	 */
	private function unread_bubble(): string {
		$unread = current_user_can( Capabilities::VIEW_SUBMISSIONS ) ? $this->submissions->counts()['unread'] : 0;

		if ( $unread <= 0 ) {
			return '';
		}

		return sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			$unread,
			esc_html( number_format_i18n( $unread ) ),
			esc_html(
				sprintf(
					/* translators: %s: number of unread messages. */
					_n( '%s unread message', '%s unread messages', $unread, 'formulario-mensaje' ),
					number_format_i18n( $unread )
				)
			)
		);
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
