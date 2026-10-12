<?php
/**
 * Traducciones: los ficheros que trae el plugin y el idioma de reserva de las variantes sin traducción propia.
 */

use Formulario_Mensaje\Lifecycle\Locale_Fallback;
use Formulario_Mensaje\Lifecycle\Translations;

class TraduccionesTest extends Caso_Formulario {

	private const DOMINIO = 'formulario-mensaje';

	/** Carpeta temporal que hace de `wp-content/languages/plugins` o de carpeta con una traducción propia. */
	private string $temporal = '';

	public function set_up() {
		parent::set_up();

		$this->temporal = get_temp_dir() . 'fm-traducciones-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->temporal );
	}

	public function tear_down() {
		array_map( 'unlink', (array) glob( $this->temporal . '/*' ) );
		rmdir( $this->temporal );

		// El idioma de un test no debe quedarse cargado para el siguiente.
		unload_textdomain( self::DOMINIO, true );

		parent::tear_down();
	}

	/** Carpeta de traducciones del plugin. */
	private function carpeta(): string {
		return plugin_dir_path( FORMULARIO_MENSAJE_FILE ) . 'languages';
	}

	/** Pone la petición en un idioma, tenga o no WordPress sus propias traducciones para él. */
	private function en_idioma( string $locale ): void {
		add_filter( 'determine_locale', static fn (): string => $locale );
		unload_textdomain( self::DOMINIO, true );
	}

	private function reserva(): Locale_Fallback {
		return new Locale_Fallback( new Translations( FORMULARIO_MENSAJE_FILE ), $this->temporal );
	}

	public function test_las_traducciones_al_espanol_se_cargan_desde_el_plugin() {
		$this->assertTrue( switch_to_locale( 'es_ES' ), 'El idioma es_ES debe estar disponible en la suite de tests.' );
		$traducido = __( 'This field is required.', 'formulario-mensaje' );
		restore_previous_locale();

		$this->assertSame( 'Este campo es obligatorio.', $traducido );
	}

	public function test_en_ingles_se_ven_los_textos_del_codigo_sin_cargar_ningun_fichero() {
		$this->en_idioma( 'en_US' );

		$this->assertSame( 'This field is required.', __( 'This field is required.', 'formulario-mensaje' ) );
		$this->assertFileDoesNotExist( $this->carpeta() . '/formulario-mensaje-en_US.mo' );
	}

	public function test_una_variante_del_espanol_sin_traduccion_propia_usa_la_de_espana() {
		$this->en_idioma( 'es_MX' );

		$this->assertSame( 'Este campo es obligatorio.', __( 'This field is required.', 'formulario-mensaje' ) );
		$this->assertSame( '%d días', _n( '%d day', '%d days', 2, 'formulario-mensaje' ) );
		$this->assertSame( 'contacto', _x( 'contact', 'block keyword', 'formulario-mensaje' ) );
	}

	public function test_los_textos_del_panel_tambien_usan_el_idioma_de_reserva() {
		$this->en_idioma( 'es_AR' );

		wp_register_script( 'fm-prueba', plugins_url( 'build/admin/index.js', FORMULARIO_MENSAJE_FILE ), array(), '1', true );

		$textos = json_decode( (string) load_script_textdomain( 'fm-prueba', self::DOMINIO, $this->carpeta() ), true );

		wp_deregister_script( 'fm-prueba' );

		$this->assertSame( 'es_ES', $textos['locale_data']['messages']['']['lang'] ?? null );
		$this->assertSame( array( 'Mensajes' ), $textos['locale_data']['messages']['Messages'] ?? null );
	}

	public function test_un_idioma_sin_reserva_se_queda_en_ingles() {
		$this->en_idioma( 'fr_FR' );

		$this->assertSame( 'This field is required.', __( 'This field is required.', 'formulario-mensaje' ) );
	}

	public function test_el_filtro_anade_y_quita_idiomas_de_reserva() {
		add_filter( 'formulario_mensaje_locale_fallbacks', static fn ( array $reservas ): array => array( 'ca' => 'es_ES' ) );

		$this->en_idioma( 'ca' );
		$this->assertSame( 'Este campo es obligatorio.', __( 'This field is required.', 'formulario-mensaje' ) );

		// El español ya no está en la lista: sus variantes vuelven a quedarse en inglés.
		$this->en_idioma( 'es_MX' );
		$this->assertSame( 'This field is required.', __( 'This field is required.', 'formulario-mensaje' ) );
	}

	public function test_la_traduccion_propia_de_una_variante_tiene_prioridad_sobre_la_reserva() {
		$reserva = $this->reserva();
		$mo      = $this->temporal . '/formulario-mensaje-es_MX.mo';
		$json    = $this->carpeta() . '/formulario-mensaje-es_MX-0123456789abcdef0123456789abcdef.json';

		// Sin traducción propia, los dos ficheros pasan a ser los de es_ES que trae el plugin.
		$this->assertSame( $this->carpeta() . '/formulario-mensaje-es_ES.mo', $reserva->mo_file( $mo ) );
		$this->assertSame( $this->carpeta() . '/formulario-mensaje-es_ES-0123456789abcdef0123456789abcdef.json', $reserva->script_file( $json ) );

		// Con el .l10n.php basta: es el que WordPress carga primero.
		touch( $this->temporal . '/formulario-mensaje-es_MX.l10n.php' );
		$this->assertSame( $mo, $reserva->mo_file( $mo ) );

		// Los textos del panel que el sitio tenga instalados para la variante también mandan.
		touch( $this->temporal . '/formulario-mensaje-es_MX-0123456789abcdef0123456789abcdef.json' );
		$this->assertSame( $json, $reserva->script_file( $json ) );
	}

	public function test_la_reserva_no_toca_el_propio_es_es_ni_los_ficheros_de_otros_plugins() {
		$reserva = $this->reserva();

		foreach ( array( 'formulario-mensaje-es_ES.mo', 'formulario-mensaje-en_GB.mo', 'otro-plugin-es_MX.mo', 'formulario-mensaje-es_MX.txt' ) as $nombre ) {
			$this->assertSame( $this->temporal . '/' . $nombre, $reserva->mo_file( $this->temporal . '/' . $nombre ), $nombre );
		}

		foreach ( array( 'formulario-mensaje-es_ES-abc.json', 'formulario-mensaje-en_GB-abc.json', 'otro-plugin-es_MX-abc.json', 'formulario-mensaje-es_MX.json' ) as $nombre ) {
			$this->assertSame( $this->temporal . '/' . $nombre, $reserva->script_file( $this->temporal . '/' . $nombre ), $nombre );
		}
	}

	public function test_una_reserva_que_no_tiene_forma_de_locale_se_ignora() {
		add_filter( 'formulario_mensaje_locale_fallbacks', static fn (): array => array( 'es' => '../../../wp-config' ) );

		$mo = $this->temporal . '/formulario-mensaje-es_MX.mo';

		$this->assertSame( $mo, $this->reserva()->mo_file( $mo ) );
	}

	/**
	 * Los .po son la fuente y lo demás se compila con `rt i18n`: si alguien edita un .po y no lo ejecuta,
	 * WordPress seguiría cargando el .l10n.php anterior.
	 */
	public function test_cada_po_esta_completo_y_sus_compilados_estan_al_dia() {
		require_once ABSPATH . WPINC . '/pomo/po.php';

		$ficheros = (array) glob( $this->carpeta() . '/*.po' );

		$this->assertContains( $this->carpeta() . '/formulario-mensaje-es_ES.po', $ficheros );

		foreach ( $ficheros as $fichero ) {
			$locale = substr( basename( $fichero, '.po' ), strlen( self::DOMINIO ) + 1 );
			$base   = substr( $fichero, 0, -3 );
			$po     = new PO();
			$mo     = new MO();

			$this->assertTrue( $po->import_from_file( $fichero ), $locale );
			$this->assertSame( $locale, $po->get_header( 'Language' ), 'La cabecera del .po dice su idioma.' );
			$this->assertFileExists( $base . '.mo' );
			$this->assertFileExists( $base . '.l10n.php' );
			$this->assertNotEmpty( glob( $base . '-*.json' ), "Faltan los textos del panel en $locale." );
			$this->assertTrue( $mo->import_from_file( $base . '.mo' ) );

			$php = include $base . '.l10n.php';

			$this->assertNotEmpty( $po->entries );
			$this->assertCount( count( $po->entries ), $php['messages'], "El .l10n.php de $locale no tiene los mismos textos que el .po." );

			foreach ( $po->entries as $clave => $entrada ) {
				$this->assertNotContains( 'fuzzy', $entrada->flags, "Traducción dudosa en $locale: {$entrada->singular}" );
				$this->assertNotContains( '', $entrada->translations, "Sin traducir en $locale: {$entrada->singular}" );
				$this->assertCount( $entrada->is_plural ? 2 : 1, $entrada->translations, "Sin traducir en $locale: {$entrada->singular}" );
				$this->assertSame( $entrada->translations, $mo->entries[ $clave ]->translations ?? null, "El .mo de $locale no está al día: {$entrada->singular}" );
				$this->assertSame( implode( "\0", $entrada->translations ), $php['messages'][ $clave ] ?? null, "El .l10n.php de $locale no está al día: {$entrada->singular}" );
			}
		}
	}
}
