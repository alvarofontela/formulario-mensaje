<?php
/**
 * Subida de ficheros: tipos cerrados, almacén privado, adjuntos, enlaces firmados y borrado.
 *
 * El movimiento del fichero se hace con un doble, porque en PHPUnit no hay subidas HTTP; la subida
 * real, con wp_handle_upload(), la prueban los tests de navegador.
 */

use Formulario_Mensaje\Actions\Action_Runner;
use Formulario_Mensaje\Antispam\Spam_Guard;
use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Forms\Field;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use Formulario_Mensaje\Mail\Email_Message;
use Formulario_Mensaje\Mail\Mail_Settings;
use Formulario_Mensaje\Mail\Resend_Transport;
use Formulario_Mensaje\Plugin;
use Formulario_Mensaje\Queue\Queue_Dispatcher;
use Formulario_Mensaje\Secrets\Secret_Cipher;
use Formulario_Mensaje\Secrets\Secret_Vault;
use Formulario_Mensaje\Storage\Submission_Journal;
use Formulario_Mensaje\Submissions\Confirmation_Builder;
use Formulario_Mensaje\Submissions\Reference_Generator;
use Formulario_Mensaje\Submissions\Submission_Channel;
use Formulario_Mensaje\Submissions\Submission_Handler;
use Formulario_Mensaje\Submissions\Submission_Request_Factory;
use Formulario_Mensaje\Submissions\Submission_Result;
use Formulario_Mensaje\Submissions\Submission_Validator;
use Formulario_Mensaje\Support\Clock;
use Formulario_Mensaje\Uploads\Download_Controller;
use Formulario_Mensaje\Uploads\Download_Links;
use Formulario_Mensaje\Uploads\File_Repository;
use Formulario_Mensaje\Uploads\Stored_File;
use Formulario_Mensaje\Uploads\Submission_Uploads;
use Formulario_Mensaje\Uploads\Upload_Collector;
use Formulario_Mensaje\Uploads\Upload_Directory;
use Formulario_Mensaje\Uploads\Upload_Store;
use Formulario_Mensaje\Uploads\Uploaded_File;

class FicherosTest extends Caso_Formulario {

	private Upload_Directory $directorio;

	private Reloj_Falso $reloj;

	/** @var string[] Ficheros temporales creados por el test. */
	private array $temporales = array();

	/** @var Email_Message[] Correos que han pasado por el filtro del plugin. */
	private array $correos = array();

	public function set_up() {
		parent::set_up();

		$this->directorio = new Upload_Directory();
		$this->reloj      = new Reloj_Falso( time() );
		$this->correos    = array();

		add_filter(
			'formulario_mensaje_email_message',
			function ( Email_Message $mensaje ): Email_Message {
				$this->correos[] = $mensaje;

				return $mensaje;
			}
		);
	}

	public function tear_down() {
		$this->directorio->remove();
		array_map( 'wp_delete_file', $this->temporales );
		$_GET = array();

		parent::tear_down();
	}

	// --- Utilidades ---------------------------------------------------------------------------

	private function pdf( int $relleno = 0 ): string {
		return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n" . str_repeat( ' ', $relleno );
	}

	private function png(): string {
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
	}

	/** Deja un contenido en un fichero temporal, como hace PHP con una subida. */
	private function temporal( string $contenido ): string {
		$ruta = tempnam( get_temp_dir(), 'fm' );
		file_put_contents( $ruta, $contenido );
		$this->temporales[] = $ruta;

		return $ruta;
	}

	private function fichero( string $nombre, string $contenido, int $error = UPLOAD_ERR_OK ): Uploaded_File {
		return new Uploaded_File( $nombre, $this->temporal( $contenido ), strlen( $contenido ), $error );
	}

	/**
	 * Compone lo que PHP dejaría en `$_FILES['fm']`.
	 *
	 * @param array<string, array<int, array{0: string, 1: string}>> $por_campo Nombre y contenido de cada fichero, por campo.
	 * @return array<string, array<string, array<int, mixed>>>
	 */
	private function subida( array $por_campo ): array {
		$subida = array();

		foreach ( $por_campo as $campo => $ficheros ) {
			foreach ( $ficheros as list( $nombre, $contenido ) ) {
				$subida['name'][ $campo ][]     = $nombre;
				$subida['type'][ $campo ][]     = 'application/x-lo-que-diga-el-navegador';
				$subida['tmp_name'][ $campo ][] = $this->temporal( $contenido );
				$subida['error'][ $campo ][]    = UPLOAD_ERR_OK;
				$subida['size'][ $campo ][]     = strlen( $contenido );
			}
		}

		return $subida;
	}

	/** @param array<string, mixed> $ajustes Ajustes del campo de ficheros. */
	private function formulario_con_fichero( array $ajustes = array(), array $cambios = array() ): int {
		return $this->crear_formulario(
			array_merge(
				array(
					'fields' => array(
						array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre', 'required' => true ) ),
						array( 'id' => 'adjunto', 'type' => 'file', 'settings' => array_merge( array( 'label' => 'Adjunto', 'max_files' => '3' ), $ajustes ) ),
					),
				),
				$cambios
			)
		);
	}

	private function almacen(): Upload_Store {
		return new Upload_Store(
			Plugin::service( File_Repository::class ),
			new Movedor_De_Pruebas(),
			$this->directorio,
			Plugin::service( Field_Type_Registry::class ),
			$this->reloj
		);
	}

	/** El servicio de envío del plugin, con el almacén de ficheros de los tests. */
	private function servicio(): Submission_Handler {
		return new Submission_Handler(
			Plugin::service( Form_Repository::class ),
			Plugin::service( Spam_Guard::class ),
			Plugin::service( Submission_Validator::class ),
			Plugin::service( Submission_Journal::class ),
			Plugin::service( Action_Runner::class ),
			Plugin::service( Queue_Dispatcher::class ),
			new Submission_Uploads( Plugin::service( Upload_Collector::class ), $this->almacen(), new Download_Links( $this->reloj ), Plugin::service( Form_Repository::class ) ),
			Plugin::service( Confirmation_Builder::class ),
			Plugin::service( Reference_Generator::class ),
			Plugin::service( Clock::class )
		);
	}

	/**
	 * @param array<string, mixed>                                   $valores
	 * @param array<string, array<int, array{0: string, 1: string}>> $ficheros
	 */
	private function enviar_con_ficheros( int $formulario, array $valores, array $ficheros ): Submission_Result|WP_Error {
		$token = $this->rest( 'POST', "/forms/$formulario/token" )->get_data()['token'] ?? '';

		return $this->servicio()->handle(
			Plugin::service( Submission_Request_Factory::class )->create( $formulario, $valores, '', $token, Submission_Channel::Rest, '', $this->subida( $ficheros ) )
		);
	}

	/** @return Stored_File[] */
	private function guardados( int $formulario, string $referencia ): array {
		return Plugin::service( File_Repository::class )->for_reference( $formulario, $referencia );
	}

	private function campo( array $ajustes = array() ): Field {
		return new Field( 'adjunto', 'file', array_merge( array( 'label' => 'Adjunto', 'file_types' => array( 'images', 'pdf' ), 'max_files' => '2', 'max_size' => '1' ), $ajustes ) );
	}

	// --- Definición y HTML --------------------------------------------------------------------

	public function test_los_tipos_permitidos_salen_de_una_lista_cerrada() {
		$formulario = $this->formulario_con_fichero( array( 'file_types' => array( 'pdf', 'php', 'images', array( 'x' ) ), 'max_files' => '99', 'max_size' => '5000' ) );

		$this->como_administrador();
		$ajustes = $this->rest( 'GET', "/forms/$formulario" )->get_data()['definition']['fields'][1]['settings'];

		$this->assertSame( array( 'images', 'pdf' ), $ajustes['file_types'], 'Solo los grupos conocidos, en el orden de la lista.' );
		$this->assertSame( '1', $ajustes['max_files'], 'Un valor fuera de la lista vuelve al de partida.' );
		$this->assertSame( '5', $ajustes['max_size'] );
	}

	public function test_el_html_lleva_el_control_de_ficheros_y_el_formulario_se_envia_como_multipart() {
		$formulario = $this->formulario_con_fichero( array( 'required' => true ) );
		$html       = do_shortcode( "[formulario_mensaje id=\"$formulario\"]" );

		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertMatchesRegularExpression( '#<input class="fm-control fm-control--file" type="file" id="[^"]+" name="fm\[adjunto\]\[\]" accept="\.jpg,\.jpeg,\.jpe,\.png,\.gif,\.webp,\.pdf" multiple required#', $html );
		$this->assertStringContainsString( 'data-fm-max-files="3"', $html );
		$this->assertStringContainsString( 'data-fm-max-bytes="' . ( 5 * MB_IN_BYTES ) . '"', $html );
		$this->assertStringContainsString( 'Allowed: JPG, JPEG, JPE, PNG, GIF, WEBP, PDF. Up to 3 files, 5 MB in total.', $html );

		$sin_ficheros = do_shortcode( '[formulario_mensaje id="' . $this->crear_formulario() . '"]' );
		$this->assertStringNotContainsString( 'enctype', $sin_ficheros );
	}

	// --- Validación ---------------------------------------------------------------------------

	public function test_se_valida_el_contenido_real_y_no_el_nombre_ni_lo_que_diga_el_navegador() {
		$tipo = Plugin::service( Field_Type_Registry::class )->get_upload( 'file' );

		$this->assertNull( $tipo->validate_files( $this->campo(), array( $this->fichero( 'informe.pdf', $this->pdf() ), $this->fichero( 'foto.png', $this->png() ) ) ) );

		$codigo = $tipo->validate_files( $this->campo(), array( $this->fichero( 'informe.pdf', '<?php echo "hola";' ) ) );
		$this->assertStringContainsString( 'This type of file is not allowed', $codigo, 'Un script con nombre de PDF no es un PDF.' );

		$this->assertStringContainsString( 'not allowed', $tipo->validate_files( $this->campo(), array( $this->fichero( 'foto.png', $this->pdf() ) ) ), 'El contenido no corresponde a la extensión.' );
		$this->assertStringContainsString( 'not allowed', $tipo->validate_files( $this->campo(), array( $this->fichero( 'pagina.html', '<html></html>' ) ) ) );
		$this->assertStringContainsString( 'not allowed', $tipo->validate_files( $this->campo(), array( $this->fichero( 'script.php', '<?php echo 1;' ) ) ) );
		$this->assertStringContainsString( 'not allowed', $tipo->validate_files( $this->campo(), array( $this->fichero( 'dibujo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>' ) ) ) );
		$this->assertStringContainsString( 'not allowed', $tipo->validate_files( $this->campo( array( 'file_types' => array( 'pdf' ) ) ), array( $this->fichero( 'foto.png', $this->png() ) ) ), 'Un tipo válido que el campo no admite.' );
	}

	public function test_se_limitan_el_numero_y_el_tamano_de_los_ficheros() {
		$tipo = Plugin::service( Field_Type_Registry::class )->get_upload( 'file' );
		$pdf  = fn (): Uploaded_File => $this->fichero( 'a.pdf', $this->pdf() );

		$this->assertSame( 'You can upload 2 files at most.', $tipo->validate_files( $this->campo(), array( $pdf(), $pdf(), $pdf() ) ) );
		$this->assertSame( 'You can upload 1 file at most.', $tipo->validate_files( $this->campo( array( 'max_files' => '1' ) ), array( $pdf(), $pdf() ) ) );

		$grande = $this->fichero( 'grande.pdf', $this->pdf( 600 * KB_IN_BYTES ) );
		$this->assertNull( $tipo->validate_files( $this->campo(), array( $grande ) ) );
		$this->assertSame( 'The files are too large. The maximum is 1 MB in total.', $tipo->validate_files( $this->campo(), array( $grande, $grande ) ), 'El límite es de todos los ficheros del campo juntos.' );

		// El tamaño que cuenta es el del fichero, no el que declara la petición.
		$mentira = new Uploaded_File( 'grande.pdf', $this->temporal( $this->pdf( 2 * MB_IN_BYTES ) ), 10, UPLOAD_ERR_OK );
		$this->assertStringContainsString( 'too large', $tipo->validate_files( $this->campo(), array( $mentira ) ) );

		$this->assertStringContainsString( 'This site accepts files up to', $tipo->validate_files( $this->campo(), array( $this->fichero( 'a.pdf', $this->pdf(), UPLOAD_ERR_INI_SIZE ) ) ) );
		$this->assertStringContainsString( 'could not be uploaded', $tipo->validate_files( $this->campo(), array( $this->fichero( 'a.pdf', $this->pdf(), UPLOAD_ERR_PARTIAL ) ) ) );
		$this->assertStringContainsString( 'could not be uploaded', $tipo->validate_files( $this->campo(), array( new Uploaded_File( 'a.pdf', '/no/existe', 10, UPLOAD_ERR_OK ) ) ) );
	}

	public function test_solo_se_recogen_los_ficheros_de_los_campos_de_subida_y_con_el_nombre_saneado() {
		$formulario = Plugin::service( Form_Repository::class )->find( $this->formulario_con_fichero() );
		$recogida   = Plugin::service( Upload_Collector::class );

		$subida = $this->subida(
			array(
				'adjunto' => array( array( '../../carpeta/Mi informe (1).pdf', $this->pdf() ), array( 'C:\\Users\\ana\\foto.png', $this->png() ) ),
				'nombre'  => array( array( 'colado.pdf', $this->pdf() ) ),
				'otro'    => array( array( 'colado.pdf', $this->pdf() ) ),
			)
		);

		$ficheros = $recogida->collect( $formulario, $subida );

		$this->assertSame( array( 'adjunto' ), array_keys( $ficheros ), 'Lo que llega para un campo que no es de ficheros se ignora.' );
		$this->assertSame( array( 'Mi-informe-1.pdf', 'foto.png' ), array_map( static fn ( Uploaded_File $fichero ): string => $fichero->name, $ficheros['adjunto'] ) );

		// Un único fichero, sin corchetes en el nombre del control, llega como texto y no como lista.
		$uno = array(
			'name'     => array( 'adjunto' => 'a.pdf' ),
			'tmp_name' => array( 'adjunto' => $this->temporal( $this->pdf() ) ),
			'error'    => array( 'adjunto' => UPLOAD_ERR_OK ),
			'size'     => array( 'adjunto' => 10 ),
		);
		$this->assertCount( 1, $recogida->collect( $formulario, $uno )['adjunto'] );

		// El hueco vacío del selector no es un fichero, y una estructura rota no rompe nada.
		$vacio = array( 'name' => array( 'adjunto' => array( '' ) ), 'tmp_name' => array( 'adjunto' => array( '' ) ), 'error' => array( 'adjunto' => array( UPLOAD_ERR_NO_FILE ) ), 'size' => array( 'adjunto' => array( 0 ) ) );
		$this->assertSame( array(), $recogida->collect( $formulario, $vacio ) );
		$this->assertSame( array(), $recogida->collect( $formulario, 'no es un array' ) );
		$this->assertSame( array(), $recogida->collect( $formulario, array( 'name' => array( 'adjunto' => array( array( 'anidado' ) ) ) ) ) );
	}

	public function test_un_campo_de_ficheros_obligatorio_exige_al_menos_uno() {
		$formulario = $this->formulario_con_fichero( array( 'required' => true ) );

		$sin = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array() );
		$this->assertSame( array( 'adjunto' ), array_keys( $sin->get_error_data()['errors'] ) );

		$malo = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'virus.pdf', '<?php echo 1;' ) ) ) );
		$this->assertStringContainsString( 'not allowed', $malo->get_error_data()['errors']['adjunto'] );
		$this->assertDirectoryDoesNotExist( $this->directorio->base(), 'Un envío rechazado no deja nada en el almacén.' );

		// El nombre del fichero no sirve como valor: mandarlo como texto no cuenta como fichero.
		$texto = $this->enviar( $formulario, array( 'nombre' => 'Ana', 'adjunto' => array( 'informe.pdf' ) ) );
		$this->assertSame( 422, $texto->get_status() );
		$this->assertSame( array( 'adjunto' ), array_keys( $texto->get_data()['data']['errors'] ) );
	}

	// --- Flujo del envío ----------------------------------------------------------------------

	public function test_los_ficheros_se_guardan_fuera_del_alcance_de_la_web_se_adjuntan_y_quedan_con_el_envio() {
		$formulario = $this->formulario_con_fichero();
		$resultado  = $this->enviar_con_ficheros(
			$formulario,
			array( 'nombre' => 'Ana' ),
			array( 'adjunto' => array( array( 'Informe anual.pdf', $this->pdf() ), array( 'foto.png', $this->png() ) ) )
		);

		$this->assertInstanceOf( Submission_Result::class, $resultado );

		$guardados = $this->guardados( $formulario, $resultado->to_array()['reference'] );

		$this->assertCount( 2, $guardados );
		$this->assertSame( 'Informe-anual.pdf', $guardados[0]->name );
		$this->assertSame( 'application/pdf', $guardados[0]->mime );
		$this->assertSame( strlen( $this->pdf() ), $guardados[0]->size );
		$this->assertGreaterThan( 0, $guardados[0]->submission_id, 'Quedan asociados al envío guardado.' );

		// En disco: nombre aleatorio, sin nada del original y con una extensión que nadie ejecuta ni interpreta.
		$this->assertMatchesRegularExpression( '#^[a-f0-9]{2}/[a-f0-9]{32}\.dat$#', $guardados[0]->path );
		$this->assertFileExists( $this->directorio->base() . '/' . $guardados[0]->path );
		$this->assertNotSame( $guardados[0]->token, $guardados[1]->token );
		$this->assertNotSame( $guardados[0]->path, $guardados[1]->path );

		// La ruta en disco no se deduce del identificador que viaja en los enlaces de descarga:
		// tener un enlace, aunque esté caducado, no dice dónde está el fichero.
		foreach ( $guardados as $guardado ) {
			$this->assertStringNotContainsString( $guardado->token, $guardado->path );
			$this->assertStringNotContainsString( substr( $guardado->token, 0, 16 ), $guardado->path );
			$this->assertFileDoesNotExist( $this->directorio->base() . '/' . substr( $guardado->token, 0, 2 ) . '/' . $guardado->token . '.dat' );
		}

		// El almacén no se sirve ni se lista por URL.
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $this->directorio->base() . '/.htaccess' ) );
		$this->assertFileExists( $this->directorio->base() . '/index.html' );

		// El correo lleva los dos adjuntos con su nombre original, y el texto dice qué se ha recibido.
		$this->assertCount( 1, $this->correos );
		$this->assertSame( array( 'Informe-anual.pdf', 'foto.png' ), array_column( $this->correos[0]->attachments, 'name' ) );
		$this->assertStringContainsString( 'Informe-anual.pdf (' . size_format( strlen( $this->pdf() ), 1 ) . ')', $this->correos[0]->body );
		$this->assertStringNotContainsString( 'admin-post.php', $this->correos[0]->body, 'Sin pedirlo, no hay enlace de descarga.' );

		$enviado = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertMatchesRegularExpression( '#filename="?Informe-anual\.pdf"?#', $enviado->body );
		$this->assertMatchesRegularExpression( '#filename="?foto\.png"?#', $enviado->body );

		// En la administración, el detalle del envío lista los ficheros con un enlace que exige sesión.
		$this->como_administrador();
		$detalle = $this->rest( 'GET', '/submissions/' . $guardados[0]->submission_id )->get_data();

		$this->assertSame( array( 'Informe-anual.pdf', 'foto.png' ), array_column( $detalle['files'], 'name' ) );
		$this->assertSame( 'adjunto', $detalle['files'][0]['field_id'] );
		$this->assertStringContainsString( 'admin-post.php?action=formulario_mensaje_file&file=' . $guardados[0]->token . '&_wpnonce=', $detalle['files'][0]['url'] );
		$this->assertStringNotContainsString( 'sig=', $detalle['files'][0]['url'] );
	}

	public function test_un_fichero_que_solo_se_adjunta_se_borra_al_terminar_el_envio() {
		$formulario = $this->formulario_con_fichero( array( 'keep' => false ) );
		$resultado  = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );

		$this->assertInstanceOf( Submission_Result::class, $resultado );
		$this->assertSame( array( 'informe.pdf' ), array_column( $this->correos[0]->attachments, 'name' ), 'El correo salió con el adjunto.' );
		$this->assertSame( array(), $this->guardados( $formulario, $resultado->to_array()['reference'] ) );
		$this->assertSame( array(), glob( $this->directorio->base() . '/*/*.dat' ) );
	}

	public function test_un_fichero_que_solo_se_adjunta_espera_al_reintento_si_la_notificacion_falla() {
		$campos = array(
			array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre', 'required' => true ) ),
			array( 'id' => 'adjunto', 'type' => 'file', 'settings' => array( 'label' => 'Adjunto', 'keep' => false ) ),
		);
		$rota   = $this->definicion()['actions'][0];

		$rota['settings']['to'] = array();

		$formulario = $this->crear_formulario( array( 'fields' => $campos, 'actions' => array( $rota ) ) );

		// Dos envíos cuya notificación falla: uno se reintentará y el otro se descartará.
		$primero = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );
		$segundo = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Luis' ), array( 'adjunto' => array( array( 'otro.pdf', $this->pdf() ) ) ) );

		$this->assertInstanceOf( Submission_Result::class, $primero, 'El visitante recibe su confirmación: el envío está guardado.' );
		$this->assertSame( array(), $this->correos );

		// El correo no ha salido, así que el fichero que solo existía para adjuntarlo sigue ahí.
		$fichero_primero = $this->guardados( $formulario, $primero->to_array()['reference'] );
		$fichero_segundo = $this->guardados( $formulario, $segundo->to_array()['reference'] );

		$this->assertCount( 1, $fichero_primero );
		$this->assertCount( 1, $fichero_segundo );
		$this->assertFileExists( $this->directorio->base() . '/' . $fichero_primero[0]->path );

		// Se corrige el formulario y se reintenta la primera entrega: ahora sí lleva su adjunto.
		$this->como_administrador();

		$buena = $this->definicion()['actions'][0];
		$this->rest( 'PUT', "/forms/$formulario", array( 'definition' => array( 'fields' => $campos, 'actions' => array( $buena ) ) ) );

		$fallidas = array_column( $this->rest( 'GET', '/deliveries' )->get_data(), 'id', 'submission_id' );
		$this->assertCount( 2, $fallidas );

		$reintento = $this->rest( 'POST', '/deliveries/' . $fallidas[ $fichero_primero[0]->submission_id ] . '/retry' );

		$this->assertSame( 'success', $reintento->get_data()['status'] );
		$this->assertCount( 1, $this->correos );
		$this->assertSame( array( 'informe.pdf' ), array_column( $this->correos[0]->attachments, 'name' ) );

		// Entregado, el fichero ya no hace falta; el del otro envío, que sigue fallido, se queda.
		$this->assertSame( array(), $this->guardados( $formulario, $primero->to_array()['reference'] ) );
		$this->assertFileDoesNotExist( $this->directorio->base() . '/' . $fichero_primero[0]->path );
		$this->assertCount( 1, $this->guardados( $formulario, $segundo->to_array()['reference'] ) );

		// Descartar la entrega también lo libera: nadie va a reintentarla.
		$this->assertSame( 200, $this->rest( 'POST', '/deliveries/' . $fallidas[ $fichero_segundo[0]->submission_id ] . '/dismiss' )->get_status() );
		$this->assertSame( array(), $this->guardados( $formulario, $segundo->to_array()['reference'] ) );
		$this->assertFileDoesNotExist( $this->directorio->base() . '/' . $fichero_segundo[0]->path );
	}

	public function test_un_fichero_que_esperaba_al_reintento_se_borra_cuando_caduca_el_registro_de_entregas() {
		global $wpdb;

		$campos = array(
			array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre', 'required' => true ) ),
			array( 'id' => 'adjunto', 'type' => 'file', 'settings' => array( 'label' => 'Adjunto', 'keep' => false ) ),
			array( 'id' => 'contrato', 'type' => 'file', 'settings' => array( 'label' => 'Contrato', 'keep' => true ) ),
		);
		$rota   = $this->definicion()['actions'][0];

		$rota['settings']['to'] = array();

		$formulario = $this->crear_formulario( array( 'fields' => $campos, 'actions' => array( $rota ) ) );
		$resultado  = $this->enviar_con_ficheros(
			$formulario,
			array( 'nombre' => 'Ana' ),
			array(
				'adjunto'  => array( array( 'informe.pdf', $this->pdf() ) ),
				'contrato' => array( array( 'contrato.pdf', $this->pdf() ) ),
			)
		);

		$this->assertInstanceOf( Submission_Result::class, $resultado );

		$referencia = $resultado->to_array()['reference'];
		$ficheros   = array_column( $this->guardados( $formulario, $referencia ), null, 'field_id' );

		// La notificación ha fallado: el fichero que solo se adjuntaba espera a que alguien la reintente.
		$this->assertSame( array( 'adjunto', 'contrato' ), array_keys( $ficheros ) );

		// Nadie la reintenta ni la descarta, y pasa el plazo del registro de entregas: la limpieza diaria la borra.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at = %s', $this->esquema()->deliveries_table(), '2020-01-01 00:00:00' ) );
		do_action( Maintenance_Schedule::HOOK );

		$this->como_administrador();
		$this->assertSame( array(), $this->rest( 'GET', '/deliveries' )->get_data(), 'La entrega fallida ya no está en el registro.' );

		// Sin entrega que reintentar, ese fichero ya no tiene para qué quedarse; el que el campo manda conservar, sí.
		$this->assertSame( array( 'contrato' ), array_column( $this->guardados( $formulario, $referencia ), 'field_id' ) );
		$this->assertFileDoesNotExist( $this->directorio->base() . '/' . $ficheros['adjunto']->path );
		$this->assertFileExists( $this->directorio->base() . '/' . $ficheros['contrato']->path );
	}

	public function test_un_fichero_que_se_conserva_no_se_borra_al_resolverse_una_entrega_fallida() {
		$campos = array(
			array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre', 'required' => true ) ),
			array( 'id' => 'adjunto', 'type' => 'file', 'settings' => array( 'label' => 'Adjunto', 'keep' => true ) ),
		);
		$rota   = $this->definicion()['actions'][0];

		$rota['settings']['to'] = array();

		$formulario = $this->crear_formulario( array( 'fields' => $campos, 'actions' => array( $rota ) ) );
		$resultado  = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );

		$this->como_administrador();

		$fallida = $this->rest( 'GET', '/deliveries' )->get_data()[0]['id'];

		$this->assertSame( 200, $this->rest( 'POST', "/deliveries/$fallida/dismiss" )->get_status() );
		$this->assertCount( 1, $this->guardados( $formulario, $resultado->to_array()['reference'] ), 'El campo manda conservarlo con el envío.' );
	}

	public function test_si_el_formulario_no_guarda_los_envios_tampoco_guarda_sus_ficheros() {
		$formulario = $this->formulario_con_fichero( array( 'signed_link' => true ), array( 'settings' => array( 'store_enabled' => false ) ) );
		$resultado  = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );

		$this->assertInstanceOf( Submission_Result::class, $resultado );
		$this->assertCount( 1, $this->correos[0]->attachments );
		$this->assertStringNotContainsString( 'admin-post.php', $this->correos[0]->body, 'No se enlaza un fichero que se va a borrar.' );
		$this->assertSame( array(), $this->guardados( $formulario, $resultado->to_array()['reference'] ) );
		$this->assertSame( array(), glob( $this->directorio->base() . '/*/*.dat' ) );
	}

	public function test_un_campo_que_no_adjunta_deja_el_fichero_solo_en_el_envio_guardado() {
		$formulario = $this->formulario_con_fichero( array( 'attach' => false ) );
		$resultado  = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );

		$this->assertSame( array(), $this->correos[0]->attachments );
		$this->assertCount( 1, $this->guardados( $formulario, $resultado->to_array()['reference'] ) );
	}

	public function test_los_ficheros_de_un_campo_oculto_por_su_condicion_se_descartan() {
		$formulario = $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre' ) ),
					array(
						'id'        => 'adjunto',
						'type'      => 'file',
						'settings'  => array( 'label' => 'Adjunto', 'required' => true ),
						'condition' => array( 'match' => 'all', 'rules' => array( array( 'field' => 'nombre', 'operator' => 'equals', 'value' => 'Ana' ) ) ),
					),
				),
			)
		);

		$resultado = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Luis' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );

		$this->assertInstanceOf( Submission_Result::class, $resultado, 'Oculto, ni es obligatorio ni se valida.' );
		$this->assertSame( array(), $this->guardados( $formulario, $resultado->to_array()['reference'] ) );
		$this->assertSame( array(), $this->correos[0]->attachments );
	}

	public function test_una_peticion_que_supera_el_limite_del_servidor_se_explica_al_visitante() {
		$formulario = $this->formulario_con_fichero();
		$fabrica    = new Submission_Request_Factory(
			Plugin::service( Formulario_Mensaje\Antispam\Client_Ip_Resolver::class ),
			new Servidor_Falso( array( 'CONTENT_LENGTH' => (string) ( wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ) + 1 ) ) )
		);

		// PHP descarta el contenido entero: no llegan ni los valores ni el token.
		$error = $this->servicio()->handle( $fabrica->create( $formulario, array(), '', '', Submission_Channel::Rest ) );

		$this->assertSame( 'formulario_mensaje_request_too_large', $error->get_error_code() );
		$this->assertSame( 413, $error->get_error_data()['status'] );
	}

	public function test_la_validacion_de_un_paso_no_espera_ficheros() {
		$formulario = $this->crear_formulario(
			array(
				'fields' => array(
					array( 'id' => 'p1', 'type' => 'step', 'settings' => array( 'label' => 'Documentos' ) ),
					array( 'id' => 'nombre', 'type' => 'text', 'settings' => array( 'label' => 'Nombre', 'required' => true ) ),
					array( 'id' => 'adjunto', 'type' => 'file', 'settings' => array( 'label' => 'Adjunto', 'required' => true ) ),
					array( 'id' => 'p2', 'type' => 'step', 'settings' => array( 'label' => 'Mensaje' ) ),
					array( 'id' => 'mensaje', 'type' => 'textarea', 'settings' => array( 'label' => 'Mensaje' ) ),
				),
			)
		);

		$this->assertSame( 200, $this->rest( 'POST', "/forms/$formulario/validate", array( 'fm' => array( 'nombre' => 'Ana' ), 'step' => 0 ) )->get_status(), 'El fichero obligatorio se comprueba al enviar, no al pasar de paso.' );
		$this->assertStringContainsString( 'data-fm-required-message="This field is required."', do_shortcode( "[formulario_mensaje id=\"$formulario\"]" ) );

		$sin_fichero = $this->enviar( $formulario, array( 'nombre' => 'Ana' ) );
		$this->assertSame( array( 'adjunto' ), array_keys( $sin_fichero->get_data()['data']['errors'] ) );
	}

	// --- Enlaces de descarga ------------------------------------------------------------------

	public function test_el_enlace_firmado_va_solo_en_la_notificacion_caduca_y_no_se_puede_alterar() {
		$formulario = $this->formulario_con_fichero( array( 'signed_link' => true, 'link_days' => '7' ) );
		$resultado  = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );
		$guardado   = $this->guardados( $formulario, $resultado->to_array()['reference'] )[0];
		$enlaces    = new Download_Links( $this->reloj );

		$this->assertSame( 1, preg_match( '#https?://\S+/wp-admin/admin-post\.php\?\S+#', $this->correos[0]->body, $encontrado ) );
		parse_str( (string) wp_parse_url( $encontrado[0], PHP_URL_QUERY ), $parametros );

		$this->assertSame( 'formulario_mensaje_file', $parametros['action'] );
		$this->assertSame( $guardado->token, $parametros['file'] );
		$this->assertSame( $this->reloj->now() + 7 * DAY_IN_SECONDS, (int) $parametros['expires'] );
		$this->assertTrue( $enlaces->is_valid_signature( $parametros['file'], (int) $parametros['expires'], $parametros['sig'] ) );

		// Ni otro fichero ni otra caducidad valen con la misma firma.
		$this->assertFalse( $enlaces->is_valid_signature( str_repeat( 'a', 32 ), (int) $parametros['expires'], $parametros['sig'] ) );
		$this->assertFalse( $enlaces->is_valid_signature( $parametros['file'], (int) $parametros['expires'] + DAY_IN_SECONDS, $parametros['sig'] ) );
		$this->assertFalse( $enlaces->is_valid_signature( $parametros['file'], (int) $parametros['expires'], '' ) );

		$this->reloj->avanzar( 7 * DAY_IN_SECONDS + 1 );
		$this->assertFalse( $enlaces->is_valid_signature( $parametros['file'], (int) $parametros['expires'], $parametros['sig'] ), 'Caducado.' );

		// Con el envío guardado no queda el enlace: quien lo ve en la administración descarga con su sesión.
		$this->como_administrador();
		$detalle = $this->rest( 'GET', '/submissions/' . $guardado->submission_id )->get_data();

		$this->assertStringNotContainsString( 'sig=', wp_json_encode( $detalle['entries'] ) );
		$this->assertStringContainsString( 'informe.pdf', wp_json_encode( $detalle['entries'] ) );
	}

	public function test_la_descarga_se_niega_sin_firma_valida_ni_permiso() {
		$formulario = $this->formulario_con_fichero();
		$resultado  = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );
		$guardado   = $this->guardados( $formulario, $resultado->to_array()['reference'] )[0];
		$enlaces    = new Download_Links( $this->reloj );
		$descarga   = new Download_Controller( $this->almacen(), $enlaces );

		$intentar = function ( array $parametros ) use ( $descarga ): string {
			$_GET = $parametros;

			try {
				$descarga->handle();
			} catch ( WPDieException $error ) {
				return $error->getMessage();
			}

			return 'servido';
		};

		parse_str( (string) wp_parse_url( $enlaces->signed( $guardado, 1 ), PHP_URL_QUERY ), $firmado );

		// Anónimo: sin firma, con una firma inventada, con la de otro fichero o caducada.
		$this->assertStringContainsString( 'not valid or has expired', $intentar( array( 'file' => $guardado->token ) ) );
		$this->assertStringContainsString( 'not valid or has expired', $intentar( array_merge( $firmado, array( 'sig' => str_repeat( 'a', 64 ) ) ) ) );
		$this->assertStringContainsString( 'not valid or has expired', $intentar( array_merge( $firmado, array( 'file' => str_repeat( 'b', 32 ) ) ) ) );

		$this->reloj->avanzar( 2 * DAY_IN_SECONDS );
		$this->assertStringContainsString( 'not valid or has expired', $intentar( $firmado ) );

		// Con sesión pero sin el permiso de ver envíos, ni con un nonce válido.
		$this->como( 'editor' );
		parse_str( (string) wp_parse_url( $enlaces->admin( $guardado ), PHP_URL_QUERY ), $de_administracion );
		$this->assertStringContainsString( 'not valid or has expired', $intentar( $de_administracion ) );

		// Con el permiso pero sin nonce, o con el de otro fichero.
		$this->como_administrador();
		$this->assertTrue( current_user_can( Capabilities::VIEW_SUBMISSIONS ) );
		$this->assertStringContainsString( 'not valid or has expired', $intentar( array( 'file' => $guardado->token ) ) );
		$this->assertStringContainsString( 'not valid or has expired', $intentar( array( 'file' => $guardado->token, '_wpnonce' => wp_create_nonce( $enlaces->nonce_action( str_repeat( 'c', 32 ) ) ) ) ) );
	}

	public function test_una_ruta_guardada_no_puede_salir_del_almacen() {
		$this->assertNotNull( $this->directorio->prepare( 'ab/' . str_repeat( 'a', 32 ) . '.dat' ) );
		file_put_contents( $this->directorio->base() . '/ab/' . str_repeat( 'a', 32 ) . '.dat', 'x' );

		$this->assertNotNull( $this->directorio->resolve( 'ab/' . str_repeat( 'a', 32 ) . '.dat' ) );
		$this->assertNull( $this->directorio->resolve( '../../../wp-config.php' ) );
		$this->assertNull( $this->directorio->resolve( 'ab/../../../../wp-load.php' ) );
		$this->assertNull( $this->directorio->resolve( 'ab' ), 'Una carpeta no es un fichero.' );
		$this->assertNull( $this->directorio->resolve( 'ab/no-existe.dat' ) );

		// El identificador de un fichero es siempre hexadecimal: cualquier otra cosa ni se busca.
		$this->assertNull( $this->almacen()->find( '../../wp-config' ) );
		$this->assertNull( $this->almacen()->find( "' OR 1=1 -- " ) );
	}

	// --- Borrado ------------------------------------------------------------------------------

	public function test_al_borrar_el_envio_se_borran_sus_ficheros() {
		$formulario = $this->formulario_con_fichero();
		$resultado  = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );
		$otro       = $this->enviar_con_ficheros( $formulario, array( 'nombre' => 'Luis' ), array( 'adjunto' => array( array( 'otro.pdf', $this->pdf() ) ) ) );
		$guardado   = $this->guardados( $formulario, $resultado->to_array()['reference'] )[0];
		$ruta       = $this->directorio->base() . '/' . $guardado->path;

		$this->assertFileExists( $ruta );

		$this->como_administrador();
		$this->assertSame( 200, $this->rest( 'POST', '/submissions/delete', array( 'ids' => array( $guardado->submission_id ) ) )->get_status() );

		$this->assertFileDoesNotExist( $ruta );
		$this->assertSame( array(), $this->guardados( $formulario, $resultado->to_array()['reference'] ) );
		$this->assertCount( 1, $this->guardados( $formulario, $otro->to_array()['reference'] ), 'Los de otros envíos no se tocan.' );
	}

	public function test_los_restos_de_envios_que_no_se_completaron_se_limpian_al_dia_siguiente() {
		$formulario = Plugin::service( Form_Repository::class )->find( $this->formulario_con_fichero() );
		$almacen    = $this->almacen();

		// Un fichero guardado cuyo envío nunca llegó a registrarse.
		$guardados = $almacen->persist( $formulario, 'ABCD-2345', array( 'adjunto' => array( $this->fichero( 'informe.pdf', $this->pdf() ) ) ) );
		$ruta      = $this->directorio->base() . '/' . $guardados['adjunto'][0]->path;

		$this->reloj->avanzar( 23 * HOUR_IN_SECONDS );
		$this->assertSame( 0, $almacen->delete_orphans(), 'Un envío en curso no pierde sus ficheros.' );
		$this->assertFileExists( $ruta );

		$this->reloj->avanzar( 2 * HOUR_IN_SECONDS );
		$this->assertSame( 1, $almacen->delete_orphans() );
		$this->assertFileDoesNotExist( $ruta );

		// Los de un envío guardado no son restos, por viejos que sean.
		$resultado = $this->enviar_con_ficheros( $formulario->id, array( 'nombre' => 'Ana' ), array( 'adjunto' => array( array( 'informe.pdf', $this->pdf() ) ) ) );
		$this->reloj->avanzar( 30 * DAY_IN_SECONDS );

		$this->assertSame( 0, $almacen->delete_orphans() );
		$this->assertCount( 1, $this->guardados( $formulario->id, $resultado->to_array()['reference'] ) );
	}

	public function test_si_un_fichero_no_se_puede_guardar_no_queda_ninguno_del_envio() {
		$formulario = Plugin::service( Form_Repository::class )->find( $this->formulario_con_fichero() );

		// El segundo no pasa la comprobación del contenido al moverlo.
		$resultado = $this->almacen()->persist(
			$formulario,
			'ABCD-2345',
			array( 'adjunto' => array( $this->fichero( 'informe.pdf', $this->pdf() ), $this->fichero( 'falso.pdf', '<?php echo 1;' ) ) )
		);

		$this->assertWPError( $resultado );
		$this->assertSame( array(), $this->guardados( $formulario->id, 'ABCD-2345' ) );
		$this->assertSame( array(), glob( $this->directorio->base() . '/*/*.dat' ) );
	}

	// --- Correo -------------------------------------------------------------------------------

	public function test_los_adjuntos_de_un_correo_llevan_un_nombre_seguro_y_no_se_pisan() {
		$ruta    = $this->temporal( $this->pdf() );
		$mensaje = new Email_Message(
			array( 'a@example.org' ),
			'Asunto',
			'Texto',
			'',
			'',
			array(
				array( 'path' => $ruta, 'name' => 'informe.pdf' ),
				array( 'path' => $ruta, 'name' => 'informe.pdf' ),
				array( 'path' => $ruta, 'name' => "raro\r\nBcc: x@example.org.pdf" ),
				array( 'path' => '/no/existe.pdf', 'name' => 'fantasma.pdf' ),
				'no es un adjunto',
			)
		);

		$adjuntos = $mensaje->safe_attachments();

		$this->assertSame( array( 'informe.pdf', '2-informe.pdf' ), array_slice( array_keys( $adjuntos ), 0, 2 ) );
		$this->assertCount( 3, $adjuntos, 'El fichero que no existe no se adjunta.' );
		$this->assertStringNotContainsString( "\n", array_keys( $adjuntos )[2] );
		$this->assertStringNotContainsString( ' ', array_keys( $adjuntos )[2] );
	}

	public function test_resend_recibe_los_adjuntos_codificados() {
		$almacen = new Secret_Vault( new Secret_Cipher() );
		$almacen->set( Mail_Settings::SECRET_RESEND_API_KEY, 're_clave_secreta' );

		$peticion = array();
		$simular  = static function ( $respuesta, array $argumentos ) use ( &$peticion ) {
			$peticion = $argumentos;

			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '{"id":"49a3999c"}', 'headers' => array(), 'cookies' => array() );
		};

		add_filter( 'pre_http_request', $simular, 10, 2 );

		$resend = new Resend_Transport( new Ajustes_Correo_En_Memoria( new Mail_Settings( transport: 'resend', from_email: 'web@example.org' ) ), $almacen );
		$envio  = $resend->send( new Email_Message( array( 'ventas@example.org' ), 'Asunto', 'Texto', '', '', array( array( 'path' => $this->temporal( $this->pdf() ), 'name' => 'informe.pdf' ) ) ) );

		remove_filter( 'pre_http_request', $simular );

		$this->assertTrue( $envio );
		$this->assertSame(
			array( array( 'filename' => 'informe.pdf', 'content' => base64_encode( $this->pdf() ) ) ),
			json_decode( $peticion['body'], true )['attachments']
		);
	}
}
