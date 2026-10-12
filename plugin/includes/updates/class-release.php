<?php
/**
 * Versión publicada del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que se sabe de una versión publicada. Solo la construye `Release_Manifest`, con todo ya validado.
 */
final class Release {

	/**
	 * Constructor.
	 *
	 * @param string $version      Versión, en la forma `X.Y.Z`.
	 * @param string $requires     Versión mínima de WordPress, o cadena vacía.
	 * @param string $requires_php Versión mínima de PHP, o cadena vacía.
	 * @param string $tested       Última versión de WordPress con la que se ha probado, o cadena vacía.
	 * @param string $released     Fecha de publicación (`AAAA-MM-DD`), o cadena vacía.
	 * @param string $changelog    Registro de cambios, en texto sin formato y con la sintaxis del readme.txt.
	 */
	public function __construct(
		public readonly string $version,
		public readonly string $requires,
		public readonly string $requires_php,
		public readonly string $tested,
		public readonly string $released,
		public readonly string $changelog
	) {}

	/**
	 * La versión con la misma forma que tiene en el fichero que la describe.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'version'      => $this->version,
			'requires'     => $this->requires,
			'requires_php' => $this->requires_php,
			'tested'       => $this->tested,
			'released'     => $this->released,
			'changelog'    => $this->changelog,
		);
	}
}
