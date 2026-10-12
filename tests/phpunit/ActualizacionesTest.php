<?php
/**
 * Actualizaciones desde GitHub: lo que el plugin responde a WordPress cuando busca versiones nuevas
 * y cuando abre su ficha, y lo que no acepta de una respuesta manipulada.
 */

use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use Formulario_Mensaje\Lifecycle\Uninstaller;
use Formulario_Mensaje\Queue\Queue_Schedule;
use Formulario_Mensaje\Updates\Github_Project;
use Formulario_Mensaje\Updates\Release_Feed;
use Formulario_Mensaje\Updates\Updates_Provider;
use Formulario_Mensaje\Uploads\Upload_Directory;

class ActualizacionesTest extends Caso_Formulario {

	private const PROYECTO   = 'https://github.com/alvarofontela/formulario-mensaje';
	private const MANIFIESTO = self::PROYECTO . '/releases/latest/download/release.json';

	/** Peticiones que el plugin ha hecho. */
	private array $peticiones = array();

	/** Respuesta de GitHub simulada: el contenido de release.json, un texto tal cual, un código HTTP o un WP_Error. */
	private $respuesta;

	public function set_up() {
		parent::set_up();

		$this->peticiones = array();
		$this->respuesta  = $this->manifiesto();

		delete_site_transient( Release_Feed::CACHE_KEY );

		add_filter(
			'pre_http_request',
			function ( $previo, array $argumentos, string $url ) {
				$this->peticiones[] = array( 'url' => $url, 'argumentos' => $argumentos );

				if ( is_wp_error( $this->respuesta ) ) {
					return $this->respuesta;
				}

				$codigo = is_int( $this->respuesta ) ? $this->respuesta : 200;
				$cuerpo = is_array( $this->respuesta ) ? wp_json_encode( $this->respuesta ) : (string) $this->respuesta;

				return array( 'response' => array( 'code' => $codigo, 'message' => '' ), 'body' => $cuerpo, 'headers' => array(), 'cookies' => array() );
			},
			10,
			3
		);
	}

	/** Contenido de release.json, como lo genera scripts/release.py. */
	private function manifiesto( array $cambios = array() ): array {
		return array_merge(
			array(
				'version'      => '9.8.7',
				'requires'     => '7.0',
				'requires_php' => '8.1',
				'tested'       => '7.1',
				'released'     => '2026-10-12',
				'changelog'    => "= 9.8.7 =\n* Primer cambio.\n* Segundo cambio.\n\n= 9.8.6 =\n* Cambio anterior.",
			),
			$cambios
		);
	}

	private function fichero(): string {
		return plugin_basename( FORMULARIO_MENSAJE_FILE );
	}

	/** Cabeceras del plugin, como las lee WordPress. */
	private function cabeceras(): array {
		return get_file_data( FORMULARIO_MENSAJE_FILE, array( 'Version' => 'Version', 'UpdateURI' => 'Update URI' ) );
	}

	/** Lo que WordPress recibe al preguntar por las actualizaciones de un plugin alojado en github.com. */
	private function comprobar( ?string $fichero = null, ?array $cabeceras = null ) {
		return apply_filters( 'update_plugins_github.com', false, $cabeceras ?? $this->cabeceras(), $fichero ?? $this->fichero(), array() );
	}

	/** Lo que WordPress recibe al abrir la ficha de un plugin. */
	private function ficha( string $slug = 'formulario-mensaje', string $accion = 'plugin_information' ) {
		return apply_filters( 'plugins_api', false, $accion, (object) array( 'slug' => $slug ) );
	}

	public function test_la_cabecera_update_uri_es_el_proyecto_de_github() {
		$this->assertSame( self::PROYECTO, $this->cabeceras()['UpdateURI'] );
		$this->assertSame( self::PROYECTO, 'https://' . Github_Project::HOST . '/' . Updates_Provider::REPOSITORY );
		// WordPress nombra el filtro con el host de esa cabecera.
		$this->assertSame( Github_Project::HOST, wp_parse_url( $this->cabeceras()['UpdateURI'], PHP_URL_HOST ) );
		$this->assertNotFalse( has_filter( 'update_plugins_' . Github_Project::HOST ) );
	}

	public function test_wordpress_recibe_la_ultima_version_y_su_paquete() {
		$actualizacion = $this->comprobar();

		$this->assertSame(
			array(
				'id'           => self::PROYECTO,
				'slug'         => 'formulario-mensaje',
				'version'      => '9.8.7',
				'url'          => self::PROYECTO . '/releases/tag/9.8.7',
				'package'      => self::PROYECTO . '/releases/download/9.8.7/formulario-mensaje-9.8.7.zip',
				'requires'     => '7.0',
				'requires_php' => '8.1',
				'tested'       => '7.1',
			),
			$actualizacion
		);
		// WordPress es quien compara: la versión publicada es mayor que la instalada.
		$this->assertTrue( version_compare( $actualizacion['version'], FORMULARIO_MENSAJE_VERSION, '>' ) );
	}

	public function test_la_consulta_va_a_una_direccion_fija_y_no_lleva_datos_del_sitio() {
		$this->comprobar();

		$this->assertCount( 1, $this->peticiones );
		$this->assertSame( self::MANIFIESTO, $this->peticiones[0]['url'] );

		$argumentos = $this->peticiones[0]['argumentos'];

		$this->assertSame( 'GET', $argumentos['method'] );
		$this->assertTrue( $argumentos['reject_unsafe_urls'], 'La petición usa la HTTP API segura.' );
		$this->assertNotFalse( $argumentos['sslverify'] );
		$this->assertSame( 'Formulario-Mensaje/' . FORMULARIO_MENSAJE_VERSION, $argumentos['user-agent'] );
		$this->assertStringNotContainsString( wp_parse_url( home_url(), PHP_URL_HOST ), wp_json_encode( $argumentos ), 'La dirección del sitio no viaja en la petición.' );
		$this->assertEmpty( $argumentos['body'] );
	}

	public function test_el_paquete_no_sale_de_la_respuesta() {
		$this->respuesta = $this->manifiesto(
			array(
				'package'       => 'https://evil.example/formulario-mensaje.zip',
				'download_link' => 'https://evil.example/formulario-mensaje.zip',
				'url'           => 'https://evil.example/',
				'id'            => 'https://evil.example/',
				'slug'          => 'otro-plugin',
			)
		);

		$actualizacion = $this->comprobar();

		$this->assertSame( self::PROYECTO . '/releases/download/9.8.7/formulario-mensaje-9.8.7.zip', $actualizacion['package'] );
		$this->assertStringNotContainsString( 'evil.example', wp_json_encode( $actualizacion ) );
		$this->assertStringNotContainsString( 'evil.example', wp_json_encode( $this->ficha() ) );
		$this->assertSame( 'formulario-mensaje', $actualizacion['slug'] );
	}

	public function versiones_no_validas(): array {
		return array(
			'con v delante'        => array( 'v9.8.7' ),
			'dos números'          => array( '9.8' ),
			'preliminar'           => array( '9.8.7-beta.1' ),
			'con metadatos'        => array( '9.8.7+build' ),
			'ceros a la izquierda' => array( '09.8.7' ),
			'sube de carpeta'      => array( '9.8.7/../../../otro/repo/releases/download/1.0.0' ),
			'con salto de línea'   => array( "9.8.7\n" ),
			'con dirección'        => array( '9.8.7?x=https://evil.example' ),
			'vacía'                => array( '' ),
			'número'               => array( 9 ),
			'lista'                => array( array( '9.8.7' ) ),
			'ausente'              => array( null ),
		);
	}

	/** @dataProvider versiones_no_validas */
	public function test_una_version_que_no_es_x_y_z_no_se_ofrece( $version ) {
		$this->respuesta = $this->manifiesto( array( 'version' => $version ) );

		$this->assertFalse( $this->comprobar() );
		$this->assertFalse( $this->ficha() );
		$this->assertFalse( get_site_transient( Release_Feed::CACHE_KEY ), 'Una respuesta que no vale no se recuerda.' );
	}

	public function respuestas_no_validas(): array {
		return array(
			'error de red'             => array( new WP_Error( 'http_request_failed', 'cURL error 28' ) ),
			'no encontrado'            => array( 404 ),
			'error de GitHub'          => array( 503 ),
			'no es JSON'               => array( '<html>Not Found</html>' ),
			'JSON que no es un objeto' => array( '"9.8.7"' ),
		);
	}

	/** @dataProvider respuestas_no_validas */
	public function test_sin_respuesta_valida_no_hay_actualizacion( $respuesta ) {
		$this->respuesta = $respuesta;

		$this->assertFalse( $this->comprobar() );
		$this->assertFalse( $this->ficha() );
	}

	public function test_los_requisitos_mal_formados_se_descartan_sin_perder_la_version() {
		$this->respuesta = $this->manifiesto(
			array(
				'requires'     => '7.0 <script>',
				'requires_php' => array( '8.1' ),
				'tested'       => 'siempre',
				'released'     => 'ayer',
			)
		);

		$actualizacion = $this->comprobar();

		$this->assertSame( '9.8.7', $actualizacion['version'] );
		$this->assertArrayNotHasKey( 'requires', $actualizacion );
		$this->assertArrayNotHasKey( 'requires_php', $actualizacion );
		$this->assertArrayNotHasKey( 'tested', $actualizacion );
		$this->assertFalse( isset( $this->ficha()->last_updated ) );
	}

	public function test_si_github_deja_de_responder_vale_la_ultima_version_conocida() {
		$this->assertSame( '9.8.7', $this->comprobar()['version'] );

		$this->respuesta = new WP_Error( 'http_request_failed', 'cURL error 28' );

		$this->assertSame( '9.8.7', $this->comprobar()['version'] );
		$this->assertCount( 2, $this->peticiones, 'Cada vez que WordPress busca actualizaciones se vuelve a preguntar.' );
	}

	public function test_lo_recordado_tambien_se_valida() {
		set_site_transient( Release_Feed::CACHE_KEY, $this->manifiesto( array( 'version' => '9.8.7/../x' ) ), HOUR_IN_SECONDS );
		$this->respuesta = 404;

		$this->assertFalse( $this->comprobar() );
		$this->assertFalse( $this->ficha() );
	}

	public function test_no_responde_por_otros_plugins_alojados_en_github() {
		$ajeno = array( 'Version' => '1.0.0', 'UpdateURI' => 'https://github.com/otro/plugin' );
		$otro  = array( 'version' => '2.0.0', 'package' => 'https://github.com/otro/plugin/releases/download/2.0.0/plugin.zip' );

		$this->assertFalse( $this->comprobar( 'otro-plugin/otro-plugin.php', $ajeno ) );
		$this->assertSame( $otro, apply_filters( 'update_plugins_github.com', $otro, $ajeno, 'otro-plugin/otro-plugin.php', array() ) );
		// Tampoco por una copia cuya cabecera apunte a otro repositorio.
		$this->assertFalse( $this->comprobar( null, array( 'Version' => '1.0.0', 'UpdateURI' => 'https://github.com/otro/formulario-mensaje' ) ) );
		$this->assertCount( 0, $this->peticiones );
	}

	public function test_la_ficha_del_plugin_sale_de_la_ultima_version() {
		$ficha = $this->ficha();

		$this->assertIsObject( $ficha );
		$this->assertSame( 'Formulario Mensaje', $ficha->name );
		$this->assertSame( 'formulario-mensaje', $ficha->slug );
		$this->assertSame( '9.8.7', $ficha->version );
		$this->assertSame( self::PROYECTO, $ficha->homepage );
		$this->assertSame( '7.0', $ficha->requires );
		$this->assertSame( '8.1', $ficha->requires_php );
		$this->assertSame( '7.1', $ficha->tested );
		$this->assertSame( '2026-10-12', $ficha->last_updated );
		$this->assertSame( self::PROYECTO . '/releases/download/9.8.7/formulario-mensaje-9.8.7.zip', $ficha->download_link );
		$this->assertTrue( $ficha->external );
		$this->assertNotSame( '', wp_strip_all_tags( $ficha->sections['description'] ) );
		$this->assertSame(
			'<h4>9.8.7</h4><ul><li>Primer cambio.</li><li>Segundo cambio.</li></ul><h4>9.8.6</h4><ul><li>Cambio anterior.</li></ul>',
			$ficha->sections['changelog']
		);
	}

	public function test_la_ficha_usa_lo_que_ya_se_sabe_sin_volver_a_preguntar() {
		$this->comprobar();
		$this->ficha();
		$this->ficha();

		$this->assertCount( 1, $this->peticiones );
	}

	public function test_el_registro_de_cambios_se_muestra_como_texto() {
		$this->respuesta = $this->manifiesto(
			array( 'changelog' => "= 9.8.7 <img src=x onerror=alert(1)> =\n* Un <script>alert(1)</script>cambio con a<b y \"comillas\".\nUna línea suelta & otra cosa." )
		);

		$registro = $this->ficha()->sections['changelog'];

		$this->assertStringNotContainsString( '<script', $registro );
		$this->assertStringNotContainsString( '<img', $registro );
		$this->assertStringContainsString( 'a&lt;b y &quot;comillas&quot;', $registro );
		$this->assertStringContainsString( '<p>Una línea suelta &amp; otra cosa.</p>', $registro );
		// Lo único que hay de HTML es lo que pone el plugin.
		$this->assertSame( $registro, wp_kses( $registro, array( 'h4' => array(), 'ul' => array(), 'li' => array(), 'p' => array() ) ) );
	}

	public function test_la_ficha_de_otros_plugins_no_se_toca() {
		$this->assertFalse( $this->ficha( 'akismet' ) );
		$this->assertFalse( $this->ficha( 'formulario-mensaje', 'query_plugins' ) );
		$this->assertFalse( apply_filters( 'plugins_api', false, 'plugin_information', array( 'slug' => 'formulario-mensaje' ) ) );
		$this->assertCount( 0, $this->peticiones );
	}

	public function test_el_filtro_permite_no_buscar_actualizaciones() {
		add_filter( 'formulario_mensaje_updates_enabled', '__return_false' );

		$this->assertFalse( $this->comprobar() );
		$this->assertFalse( $this->ficha() );
		$this->assertCount( 0, $this->peticiones );
	}

	public function test_al_desinstalar_se_olvida_la_ultima_version() {
		$this->comprobar();
		$this->assertIsArray( get_site_transient( Release_Feed::CACHE_KEY ) );

		$desinstalar = new Uninstaller( new Capabilities(), $this->esquema(), new Maintenance_Schedule(), new Queue_Schedule( new Reloj_Falso() ), new Upload_Directory() );
		$desinstalar->uninstall();

		$this->assertFalse( get_site_transient( Release_Feed::CACHE_KEY ) );
	}
}
