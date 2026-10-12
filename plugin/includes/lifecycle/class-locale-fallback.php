<?php
/**
 * Idioma de reserva de las traducciones.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Decide qué traducción del plugin usa un sitio cuyo idioma exacto no tiene ficheros.
 *
 * WordPress solo carga la traducción del locale exacto: un sitio en `es_MX` vería el plugin en inglés aunque
 * exista la de `es_ES`. Aquí cada idioma tiene un locale de reserva que sirve a todas sus variantes. Una
 * traducción propia de la variante tiene siempre prioridad, la traiga el plugin o esté instalada en el
 * sitio (`wp-content/languages/plugins`).
 */
final class Locale_Fallback {

	/**
	 * Locale de reserva de cada idioma, por su código (lo que va antes del primer guion bajo del locale).
	 */
	private const FALLBACKS = array(
		'es' => 'es_ES',
	);

	/**
	 * Constructor.
	 *
	 * @param Translations $translations   Traducciones del plugin.
	 * @param string       $site_directory Carpeta donde el sitio guarda las traducciones de sus plugins.
	 */
	public function __construct(
		private readonly Translations $translations,
		private readonly string $site_directory,
	) {}

	/**
	 * Fichero .mo que se carga en lugar del que WordPress ha calculado para el plugin.
	 *
	 * @param string $mofile Ruta del .mo del locale del sitio.
	 * @return string La misma ruta, o la del locale de reserva si el del sitio no tiene traducción.
	 */
	public function mo_file( string $mofile ): string {
		$prefix = Translations::DOMAIN . '-';
		$name   = basename( $mofile );

		if ( ! str_starts_with( $name, $prefix ) || ! str_ends_with( $name, '.mo' ) ) {
			return $mofile;
		}

		$fallback = $this->fallback_for( substr( $name, strlen( $prefix ), -3 ) );

		// WordPress prueba el .l10n.php antes que el .mo: con cualquiera de los dos, el locale tiene traducción.
		if ( null === $fallback || is_readable( $mofile ) || is_readable( substr( $mofile, 0, -3 ) . '.l10n.php' ) ) {
			return $mofile;
		}

		return $this->translations->directory() . '/' . $prefix . $fallback . '.mo';
	}

	/**
	 * Fichero .json con los textos de un script que se carga en lugar del que WordPress ha calculado.
	 *
	 * @param string $file Ruta del .json del locale del sitio.
	 * @return string La misma ruta, o la del locale de reserva si el del sitio no tiene traducción.
	 */
	public function script_file( string $file ): string {
		$prefix = Translations::DOMAIN . '-';
		$name   = basename( $file );

		if ( ! str_starts_with( $name, $prefix ) || ! str_ends_with( $name, '.json' ) ) {
			return $file;
		}

		// El nombre es «dominio-locale-script.json», y un locale no lleva guiones.
		$rest   = substr( $name, strlen( $prefix ) );
		$locale = (string) strstr( $rest, '-', true );

		$fallback = $this->fallback_for( $locale );

		// WordPress mira antes en la carpeta del plugin que en la del sitio: la del sitio se comprueba aquí.
		if ( null === $fallback || is_readable( $file ) || is_readable( $this->site_directory . '/' . $name ) ) {
			return $file;
		}

		return $this->translations->directory() . '/' . $prefix . $fallback . substr( $rest, strlen( $locale ) );
	}

	/**
	 * Locale de reserva de un locale.
	 *
	 * @param string $locale Locale del sitio o del usuario, como `es_MX`.
	 * @return string|null Locale de reserva, o null si no tiene o si ya es él.
	 */
	private function fallback_for( string $locale ): ?string {
		/**
		 * Filtra los locales de reserva: qué traducción del plugin se usa cuando el idioma del sitio no tiene la suya.
		 *
		 * @since 0.14.0
		 *
		 * @param array<string, string> $fallbacks Locale de reserva de cada idioma, por su código: `array( 'es' => 'es_ES' )`.
		 */
		$fallbacks = (array) apply_filters( 'formulario_mensaje_locale_fallbacks', self::FALLBACKS );
		$fallback  = $fallbacks[ explode( '_', $locale, 2 )[0] ] ?? null;

		// Va a formar parte de una ruta: solo se acepta lo que tiene forma de locale.
		if ( ! is_string( $fallback ) || $fallback === $locale || 1 !== preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/', $fallback ) ) {
			return null;
		}

		return $fallback;
	}
}
