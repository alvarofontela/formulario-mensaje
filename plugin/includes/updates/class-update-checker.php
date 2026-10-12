<?php
/**
 * Respuesta a WordPress cuando busca actualizaciones del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Le dice a WordPress cuál es la última versión del plugin y de dónde se descarga.
 *
 * WordPress pregunta por el filtro `update_plugins_{$hostname}` a los plugins que declaran la cabecera
 * `Update URI`, y es él quien compara la versión con la instalada, comprueba los requisitos, muestra
 * el aviso y hace la actualización, también la automática. Aquí solo se le da el dato.
 */
final class Update_Checker {

	/**
	 * Constructor.
	 *
	 * @param Release_Feed   $feed        Consulta de la última versión publicada.
	 * @param Github_Project $project     Proyecto del que salen las versiones.
	 * @param string         $plugin_file Fichero principal del plugin, relativo a la carpeta de plugins.
	 */
	public function __construct(
		private readonly Release_Feed $feed,
		private readonly Github_Project $project,
		private readonly string $plugin_file
	) {}

	/**
	 * Devuelve los datos de la última versión.
	 *
	 * @param array<string, mixed>|false $update      Lo que haya respondido otro filtro.
	 * @param array<string, mixed>       $plugin_data Cabeceras del plugin por el que pregunta WordPress.
	 * @param string                     $plugin_file Fichero principal de ese plugin.
	 * @return array<string, mixed>|false Los datos de la versión, o lo recibido si no hay nada que decir.
	 */
	public function check( array|false $update, array $plugin_data, string $plugin_file ): array|false {
		// El filtro lo comparten todos los plugins que se actualizan desde el mismo host.
		if ( $plugin_file !== $this->plugin_file || ( $plugin_data['UpdateURI'] ?? '' ) !== $this->project->uri() ) {
			return $update;
		}

		$release = $this->feed->refresh();

		if ( null === $release ) {
			return $update;
		}

		return array_filter(
			array(
				'id'           => $this->project->uri(),
				'slug'         => $this->project->slug(),
				'version'      => $release->version,
				'url'          => $this->project->release_url( $release->version ),
				'package'      => $this->project->package_url( $release->version ),
				'requires'     => $release->requires,
				'requires_php' => $release->requires_php,
				'tested'       => $release->tested,
			),
			static fn ( string $value ): bool => '' !== $value
		);
	}
}
