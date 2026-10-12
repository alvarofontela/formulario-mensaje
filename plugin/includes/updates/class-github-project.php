<?php
/**
 * Proyecto de GitHub del que salen las versiones del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Direcciones de las versiones publicadas del plugin en GitHub.
 *
 * Todas se componen aquí, a partir del repositorio fijado en el código y de una versión ya validada:
 * ninguna sale de una respuesta de GitHub ni de la base de datos. Es lo que impide que una respuesta
 * manipulada haga que WordPress descargue e instale un paquete de otro sitio.
 */
final class Github_Project {

	/**
	 * Host de la cabecera `Update URI`: WordPress lo usa para nombrar su filtro `update_plugins_{$hostname}`.
	 */
	public const HOST = 'github.com';

	/**
	 * Fichero que acompaña a cada versión y la describe. Lo genera `scripts/release.py` al publicarla.
	 */
	private const MANIFEST = 'release.json';

	/**
	 * Constructor.
	 *
	 * @param string $repository Repositorio, como `propietario/nombre`.
	 * @param string $slug       Slug del plugin: su carpeta y el comienzo del nombre de su paquete.
	 */
	public function __construct(
		private readonly string $repository,
		private readonly string $slug
	) {}

	/**
	 * Slug del plugin.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Dirección del proyecto. Es el valor de la cabecera `Update URI` del plugin.
	 */
	public function uri(): string {
		return 'https://' . self::HOST . '/' . $this->repository;
	}

	/**
	 * Dirección del fichero que describe la última versión estable.
	 *
	 * GitHub la redirige al fichero de la versión más reciente que no es preliminar ni borrador. No es
	 * la API de GitHub, que limita las consultas sin credenciales a 60 por hora y por IP: en un
	 * alojamiento compartido, donde muchas webs salen por la misma, se agotaría.
	 */
	public function manifest_url(): string {
		return $this->uri() . '/releases/latest/download/' . self::MANIFEST;
	}

	/**
	 * Dirección del paquete instalable de una versión.
	 *
	 * @param string $version Versión ya validada, que es también el nombre de su tag.
	 */
	public function package_url( string $version ): string {
		return $this->uri() . '/releases/download/' . $version . '/' . $this->slug . '-' . $version . '.zip';
	}

	/**
	 * Dirección de la página de una versión.
	 *
	 * @param string $version Versión ya validada.
	 */
	public function release_url( string $version ): string {
		return $this->uri() . '/releases/tag/' . $version;
	}
}
