<?php
/**
 * Lectura del fichero que describe una versión publicada.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Updates;

use Formulario_Mensaje\Support\Plain_Text;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte el contenido de `release.json` en una versión, o en nada si no es lo que se espera.
 *
 * Lo que llega de fuera no es fiable aunque venga de GitHub: de aquí sale la versión con la que se
 * compone la dirección del paquete que WordPress instalará, así que solo se admite la forma exacta
 * `X.Y.Z`. El fichero no puede indicar de dónde se descarga el paquete: si trae una dirección, se ignora.
 */
final class Release_Manifest {

	/**
	 * Versión estable: tres números sin ceros a la izquierda. Las preliminares (`1.1.0-beta.1`) no se ofrecen.
	 */
	private const VERSION = '/^(0|[1-9]\d{0,4})\.(0|[1-9]\d{0,4})\.(0|[1-9]\d{0,4})$/D';

	/**
	 * Versión de WordPress o de PHP: dos o tres números.
	 */
	private const REQUIREMENT = '/^\d{1,3}\.\d{1,3}(\.\d{1,3})?$/D';

	/**
	 * Fecha de publicación.
	 */
	private const DATE = '/^\d{4}-\d{2}-\d{2}$/D';

	/**
	 * Caracteres del registro de cambios que se conservan.
	 */
	private const CHANGELOG_LENGTH = 20000;

	/**
	 * Lee la descripción de una versión.
	 *
	 * @param mixed $data Contenido del fichero ya decodificado, o lo guardado en la caché.
	 */
	public function parse( mixed $data ): ?Release {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$version = $this->matching( $data['version'] ?? null, self::VERSION );

		if ( '' === $version ) {
			return null;
		}

		$changelog = is_string( $data['changelog'] ?? null ) ? $data['changelog'] : '';

		return new Release(
			$version,
			$this->matching( $data['requires'] ?? null, self::REQUIREMENT ),
			$this->matching( $data['requires_php'] ?? null, self::REQUIREMENT ),
			$this->matching( $data['tested'] ?? null, self::REQUIREMENT ),
			$this->matching( $data['released'] ?? null, self::DATE ),
			mb_substr( Plain_Text::block( $changelog ), 0, self::CHANGELOG_LENGTH )
		);
	}

	/**
	 * Devuelve el valor si es un texto con la forma indicada, y si no, una cadena vacía.
	 *
	 * @param mixed  $value   Valor sin validar.
	 * @param string $pattern Expresión regular que debe cumplir entero.
	 */
	private function matching( mixed $value, string $pattern ): string {
		return is_string( $value ) && 1 === preg_match( $pattern, $value ) ? $value : '';
	}
}
