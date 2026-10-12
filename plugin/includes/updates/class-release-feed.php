<?php
/**
 * Consulta de la última versión publicada del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Pregunta a GitHub cuál es la última versión estable del plugin y recuerda la respuesta.
 *
 * Es la única clase del módulo que sale a la red. La petición va a una dirección fija del proyecto,
 * no lleva ningún dato del sitio y se identifica solo con el nombre y la versión del plugin: el
 * agente de usuario de WordPress, que incluye la dirección del sitio, no se envía.
 */
final class Release_Feed {

	/**
	 * Transient de la red en el que se guarda la última versión conocida.
	 */
	public const CACHE_KEY = 'formulario_mensaje_latest_release';

	/**
	 * Segundos que se recuerda. WordPress vuelve a buscar actualizaciones, como mucho, cada doce horas.
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Segundos de espera cuando hay alguien delante de la pantalla.
	 */
	private const TIMEOUT = 5;

	/**
	 * Segundos de espera en una tarea programada, donde nadie espera la respuesta.
	 */
	private const BACKGROUND_TIMEOUT = 15;

	/**
	 * Redirecciones que se siguen: GitHub lleva de «la última versión» a su tag, y de ahí a su almacén de ficheros.
	 */
	private const REDIRECTS = 3;

	/**
	 * Bytes que se leen de la respuesta, como mucho.
	 */
	private const MAX_BYTES = 65536;

	/**
	 * Constructor.
	 *
	 * @param Github_Project   $project        Proyecto del que salen las versiones.
	 * @param Release_Manifest $manifest       Lector del fichero que describe una versión.
	 * @param string           $plugin_version Versión instalada del plugin, para identificar la petición.
	 */
	public function __construct(
		private readonly Github_Project $project,
		private readonly Release_Manifest $manifest,
		private readonly string $plugin_version
	) {}

	/**
	 * Consulta a GitHub la última versión y la recuerda.
	 *
	 * Si GitHub no responde, vale la última que se recuerde: un fallo pasajero no hace desaparecer
	 * del escritorio una actualización que ya se había anunciado.
	 *
	 * @return Release|null La última versión estable; nada si no hay ninguna o si no se ha podido saber.
	 */
	public function refresh(): ?Release {
		if ( ! $this->enabled() ) {
			return null;
		}

		$release = $this->fetch();

		if ( null === $release ) {
			return $this->remembered();
		}

		set_site_transient( self::CACHE_KEY, $release->to_array(), self::CACHE_TTL );

		return $release;
	}

	/**
	 * Devuelve la última versión conocida, consultándola solo si no se recuerda ninguna.
	 *
	 * @return Release|null La última versión estable; nada si no hay ninguna o si no se ha podido saber.
	 */
	public function latest(): ?Release {
		if ( ! $this->enabled() ) {
			return null;
		}

		return $this->remembered() ?? $this->refresh();
	}

	/**
	 * La versión guardada en la caché.
	 *
	 * Lo guardado se valida igual que lo recibido: la base de datos tampoco decide qué se instala.
	 */
	private function remembered(): ?Release {
		return $this->manifest->parse( get_site_transient( self::CACHE_KEY ) );
	}

	/**
	 * Pide a GitHub el fichero que describe la última versión.
	 */
	private function fetch(): ?Release {
		$response = wp_safe_remote_get(
			$this->project->manifest_url(),
			array(
				'timeout'             => wp_doing_cron() ? self::BACKGROUND_TIMEOUT : self::TIMEOUT,
				'redirection'         => self::REDIRECTS,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Formulario-Mensaje/' . $this->plugin_version,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return $this->manifest->parse( json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Indica si el plugin busca sus actualizaciones.
	 */
	private function enabled(): bool {
		/**
		 * Filtra si el plugin busca sus actualizaciones en GitHub.
		 *
		 * Con `false`, el plugin no hace ninguna petición a GitHub y WordPress no le ofrece actualizaciones.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Si se buscan actualizaciones. Por defecto, sí.
		 */
		return (bool) apply_filters( 'formulario_mensaje_updates_enabled', true );
	}
}
