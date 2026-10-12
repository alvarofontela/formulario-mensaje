<?php
/**
 * Ficha del plugin en la ventana «Ver detalles» de WordPress.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Da a WordPress la ficha del plugin que muestra al pulsar «Ver detalles de la versión».
 *
 * Sin esto WordPress la pediría a WordPress.org, donde el plugin no está, y la ventana solo diría
 * que no lo encuentra.
 */
final class Plugin_Information {

	/**
	 * Constructor.
	 *
	 * @param Release_Feed   $feed    Consulta de la última versión publicada.
	 * @param Github_Project $project Proyecto del que salen las versiones.
	 * @param string         $file    Ruta del fichero principal del plugin.
	 */
	public function __construct(
		private readonly Release_Feed $feed,
		private readonly Github_Project $project,
		private readonly string $file
	) {}

	/**
	 * Devuelve la ficha del plugin.
	 *
	 * @param mixed  $result Lo que haya respondido otro filtro; `false` si nadie lo ha hecho.
	 * @param string $action Qué pide WordPress.
	 * @param mixed  $args   Argumentos de la consulta; un objeto con el `slug` del plugin.
	 * @return mixed La ficha, o lo recibido si no se pregunta por este plugin o no se conoce su última versión.
	 */
	public function information( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== $this->project->slug() ) {
			return $result;
		}

		$release = $this->feed->latest();

		if ( null === $release ) {
			return $result;
		}

		$headers = $this->headers();

		// WordPress filtra con wp_kses() lo que imprime de esta ficha; aun así, aquí todo sale ya escapado.
		$information = array(
			'name'          => $headers['Name'],
			'slug'          => $this->project->slug(),
			'version'       => $release->version,
			'author'        => esc_html( $headers['Author'] ),
			'homepage'      => $this->project->uri(),
			'requires'      => $release->requires,
			'requires_php'  => $release->requires_php,
			'tested'        => $release->tested,
			'last_updated'  => $release->released,
			'download_link' => $this->project->package_url( $release->version ),
			'sections'      => array_filter(
				array(
					'description' => '<p>' . esc_html( $headers['Description'] ) . '</p>',
					'changelog'   => $this->changelog_html( $release->changelog ),
				)
			),
			// No está en WordPress.org: la ventana no enlaza a su ficha de allí.
			'external'      => true,
		);

		return (object) array_filter( $information, static fn ( mixed $value ): bool => '' !== $value );
	}

	/**
	 * Cabeceras del plugin instalado, traducidas.
	 *
	 * @return array{Name: string, Author: string, Description: string}
	 */
	private function headers(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $this->file, false );

		return array(
			'Name'        => (string) ( $data['Name'] ?? '' ),
			'Author'      => (string) ( $data['Author'] ?? '' ),
			'Description' => (string) ( $data['Description'] ?? '' ),
		);
	}

	/**
	 * Convierte el registro de cambios, que llega con la sintaxis del readme.txt, en HTML.
	 *
	 * `= 1.2.0 =` es el título de una versión y las líneas que empiezan por `*` o `-`, sus cambios.
	 *
	 * @param string $changelog Registro de cambios en texto sin formato.
	 */
	private function changelog_html( string $changelog ): string {
		$html    = '';
		$in_list = false;

		foreach ( explode( "\n", $changelog ) as $line ) {
			$line    = trim( $line );
			$is_item = 1 === preg_match( '/^[*-]\s+(.+)$/', $line, $item );

			if ( $in_list && ! $is_item ) {
				$html   .= '</ul>';
				$in_list = false;
			}

			if ( '' === $line ) {
				continue;
			}

			if ( $is_item ) {
				$html   .= ( $in_list ? '' : '<ul>' ) . '<li>' . esc_html( $item[1] ) . '</li>';
				$in_list = true;
			} elseif ( 1 === preg_match( '/^=\s*(.+?)\s*=$/', $line, $title ) ) {
				$html .= '<h4>' . esc_html( $title[1] ) . '</h4>';
			} else {
				$html .= '<p>' . esc_html( $line ) . '</p>';
			}
		}

		return $html . ( $in_list ? '</ul>' : '' );
	}
}
