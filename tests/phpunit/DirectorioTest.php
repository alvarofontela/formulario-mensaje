<?php
/**
 * Requisitos del directorio de WordPress.org que se pueden comprobar sin Plugin Check: que el readme.txt
 * diga lo mismo que la cabecera del plugin y que documente cada servicio externo con el que habla el código.
 */

class DirectorioTest extends Caso_Formulario {

	/** Dominios que aparecen en el código como referencia, sin que el plugin se conecte a ellos. */
	private const SOLO_REFERENCIA = array( 'www.gnu.org', 'www.cloudflare.com', 'example.com' );

	private function readme(): string {
		$fichero = plugin_dir_path( FORMULARIO_MENSAJE_FILE ) . 'readme.txt';

		$this->assertFileExists( $fichero );

		return str_replace( "\r\n", "\n", (string) file_get_contents( $fichero ) );
	}

	/** Valor de una cabecera del readme, como `Stable tag`. */
	private function cabecera( string $nombre ): string {
		$this->assertSame( 1, preg_match( '/^' . preg_quote( $nombre, '/' ) . ':\s*(.+)$/mi', $this->readme(), $partes ), "Falta «{$nombre}» en el readme.txt." );

		return trim( $partes[1] );
	}

	public function test_el_readme_declara_lo_mismo_que_la_cabecera_del_plugin() {
		$plugin = get_file_data(
			FORMULARIO_MENSAJE_FILE,
			array(
				'nombre'    => 'Plugin Name',
				'version'   => 'Version',
				'wordpress' => 'Requires at least',
				'php'       => 'Requires PHP',
				'licencia'  => 'License',
			)
		);

		$this->assertStringStartsWith( "=== {$plugin['nombre']} ===\n", $this->readme() );
		$this->assertSame( FORMULARIO_MENSAJE_VERSION, $plugin['version'], 'La constante y la cabecera del plugin llevan la misma versión.' );
		$this->assertSame( $plugin['version'], $this->cabecera( 'Stable tag' ) );
		$this->assertSame( $plugin['wordpress'], $this->cabecera( 'Requires at least' ) );
		$this->assertSame( $plugin['php'], $this->cabecera( 'Requires PHP' ) );
		$this->assertSame( $plugin['licencia'], $this->cabecera( 'License' ) );
		$this->assertStringContainsString( "\n= {$plugin['version']} =\n", $this->readme(), 'La versión actual tiene su entrada en el registro de cambios.' );
	}

	public function test_el_readme_respeta_los_limites_del_directorio() {
		$readme = $this->readme();

		$this->assertLessThanOrEqual( 5, count( explode( ',', $this->cabecera( 'Tags' ) ) ), 'El directorio admite cinco etiquetas como mucho.' );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $this->cabecera( 'Tested up to' ), '«Tested up to» lleva solo la versión mayor de WordPress.' );

		// La descripción corta es el primer párrafo tras las cabeceras, y el directorio la corta a los 150 caracteres.
		$this->assertSame( 1, preg_match( '/\n\n(.+)\n\n== Description ==/', $readme, $partes ) );
		$this->assertLessThanOrEqual( 150, mb_strlen( $partes[1] ) );

		foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'External services', 'Source code', 'Changelog' ) as $seccion ) {
			$this->assertStringContainsString( "\n== {$seccion} ==\n", $readme );
		}
	}

	/**
	 * La directriz 7 del directorio obliga a decir a qué servicios se conecta el plugin: un dominio nuevo
	 * en el código tiene que llegar con su apartado en «External services».
	 */
	public function test_el_readme_documenta_cada_servicio_externo_del_codigo() {
		$carpeta  = plugin_dir_path( FORMULARIO_MENSAJE_FILE );
		$dominios = array();
		$ficheros = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $carpeta, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $ficheros as $fichero ) {
			$ruta = str_replace( '\\', '/', $fichero->getPathname() );

			// El código propio: ni lo compilado ni las traducciones.
			if ( ! preg_match( '/\.(php|js)$/', $ruta ) || preg_match( '#/(build|languages)/#', $ruta ) ) {
				continue;
			}

			preg_match_all( '#https?://([a-z0-9.-]+\.[a-z]{2,})#i', (string) file_get_contents( $ruta ), $encontrados );

			$dominios = array_merge( $dominios, $encontrados[1] );
		}

		$dominios = array_diff( array_unique( $dominios ), self::SOLO_REFERENCIA );
		$servicios = strstr( $this->readme(), "\n== External services ==\n" );
		$servicios = substr( $servicios, 0, (int) strpos( $servicios, "\n== Source code ==\n" ) );

		$this->assertNotEmpty( $dominios );

		foreach ( $dominios as $dominio ) {
			$this->assertStringContainsString( $dominio, $servicios, "El readme.txt no documenta el servicio externo {$dominio}." );
		}
	}
}
