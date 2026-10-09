#requires -Version 7.0
# CLI del entorno local de desarrollo. Todo lo que descarga o genera vive en .runtime/.
# Sin bloque param(): así los argumentos tipo `-v` o `--path=...` llegan intactos a wp, php, etc.

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
# Salida en UTF-8 y sin códigos de color cuando la captura otro programa (tests, agentes, CI).
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
if ([Console]::IsOutputRedirected) { $PSStyle.OutputRendering = 'PlainText' }

$Command = if ($args.Count) { [string]$args[0] } else { 'help' }
$Rest = @($args | Select-Object -Skip 1)

$Root = Split-Path -Parent $PSScriptRoot
$Cfg = Import-PowerShellDataFile (Join-Path $PSScriptRoot 'runtime.config.psd1')
$RT = Join-Path $Root '.runtime'

$Dir = @{
    Cache    = "$RT\cache"
    Php      = "$RT\php"
    Apache   = "$RT\apache"
    MariaDb  = "$RT\mariadb"
    Mailpit  = "$RT\mailpit"
    Bin      = "$RT\bin"
    Conf     = "$RT\conf"
    Data     = "$RT\data"
    DbData   = "$RT\data\mariadb"
    Snaps    = "$RT\data\snapshots"
    Logs     = "$RT\logs"
    Tmp      = "$RT\tmp"
    Www      = "$RT\www"
    Browsers = "$RT\ms-playwright"
    Plugin   = Join-Path $Root $Cfg.PluginSource
}
$Exe = @{
    Php       = "$RT\php\php.exe"
    Httpd     = "$RT\apache\bin\httpd.exe"
    Mariadbd  = "$RT\mariadb\bin\mariadbd.exe"
    Mariadb   = "$RT\mariadb\bin\mariadb.exe"
    Dump      = "$RT\mariadb\bin\mariadb-dump.exe"
    Admin     = "$RT\mariadb\bin\mariadb-admin.exe"
    InstallDb = "$RT\mariadb\bin\mariadb-install-db.exe"
    Mailpit   = "$RT\mailpit\mailpit.exe"
    WpCli     = "$RT\bin\wp-cli.phar"
    Composer  = "$RT\bin\composer.phar"
}

$BaseUrl = "http://localhost:$($Cfg.Ports.Http)"
$MailApi = "http://127.0.0.1:$($Cfg.Ports.MailUi)"
$PluginLink = "$($Dir.Www)\wp-content\plugins\$($Cfg.Slug)"
$PluginMain = Join-Path $Dir.Plugin "$($Cfg.Slug).php"
# [client] de my.ini lleva host, puerto y credenciales de root; debe ir como primer argumento.
$DbConn = "--defaults-file=$($Dir.Conf)\my.ini"
$UserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'

# El directorio de PHP va en PATH para que Apache resuelva las DLL de las extensiones (ICU, OpenSSL, libssh2).
$env:PATH = "$($Dir.Php);$($Dir.MariaDb)\bin;$env:PATH"
$env:PHPRC = $Dir.Php
$env:WP_CLI_CACHE_DIR = "$($Dir.Cache)\wp-cli"
$env:WP_CLI_CONFIG_PATH = "$($Dir.Conf)\wp-cli.yml"
$env:COMPOSER_HOME = "$($Dir.Cache)\composer"
$env:PLAYWRIGHT_BROWSERS_PATH = $Dir.Browsers

$Services = [ordered]@{
    mariadb = @{ Exe = $Exe.Mariadbd; Port = $Cfg.Ports.Db; Args = @($DbConn) }
    mailpit = @{
        Exe  = $Exe.Mailpit; Port = $Cfg.Ports.MailUi
        Args = @('--smtp', "127.0.0.1:$($Cfg.Ports.Smtp)", '--listen', "127.0.0.1:$($Cfg.Ports.MailUi)", '--database', "$($Dir.Data)\mailpit.db", '--log-file', "$($Dir.Logs)\mailpit.log")
    }
    apache  = @{ Exe = $Exe.Httpd; Port = $Cfg.Ports.Http; Args = @('-f', "$($Dir.Conf)\httpd.conf") }
}

# ---------------------------------------------------------------- utilidades

function Step([string]$Message) { Write-Host "==> $Message" -ForegroundColor Cyan }
function Fwd([string]$Path) { $Path.Replace('\', '/') }
function Write-Text([string]$Path, [string]$Content) { [IO.File]::WriteAllText($Path, $Content) }

# Ejecuta un binario mostrando su salida y abortando si devuelve error.
function Run {
    $exe, $rest = $args
    & $exe @rest | Out-Host
    if ($LASTEXITCODE -ne 0) { throw "Falló (código $LASTEXITCODE): $exe $rest" }
}

function Wp { Run $Exe.Php $Exe.WpCli @args }

function In-Root([scriptblock]$Block) {
    Push-Location $Root
    try { & $Block } finally { Pop-Location }
}

function Test-Port([int]$Port) {
    $client = [Net.Sockets.TcpClient]::new()
    try { return ($client.ConnectAsync('127.0.0.1', $Port).Wait(400) -and $client.Connected) }
    catch { return $false }
    finally { $client.Dispose() }
}

function Get-Download([string[]]$Urls, [string]$Name, [string]$Sha256) {
    $dest = Join-Path $Dir.Cache $Name
    if (-not (Test-Path $dest)) {
        foreach ($url in $Urls) {
            Write-Host "    descargando $url"
            & curl.exe -fL -sS --retry 3 --retry-delay 2 -A $UserAgent -o "$dest.part" $url
            if ($LASTEXITCODE -eq 0) { Move-Item "$dest.part" $dest -Force; break }
        }
        if (-not (Test-Path $dest)) { throw "No se pudo descargar $Name" }
    }
    if ($Sha256 -and (Get-FileHash $dest -Algorithm SHA256).Hash -ne $Sha256) {
        Remove-Item $dest
        throw "El SHA-256 de $Name no coincide con el de runtime.config.psd1; se ha borrado de la caché."
    }
    return $dest
}

function Test-Component([string]$Path, [string]$Version) {
    $marker = Join-Path $Path '.rt-version'
    return (Test-Path $marker) -and ((Get-Content $marker -Raw).Trim() -eq $Version)
}

# Descomprime en un directorio temporal propio y mueve el resultado a su sitio.
# $Inner es la carpeta raíz del zip cuando el contenido no viene suelto.
function Install-Zip([string]$Name, [string]$Path, [string]$Version, [string[]]$Urls, [string]$File, [string]$Sha256, [string]$Inner) {
    if (Test-Component $Path $Version) { return }
    Step "Instalando $Name $Version"
    $zip = Get-Download $Urls $File $Sha256
    $tmp = Join-Path $Dir.Tmp "extract-$Name"
    foreach ($p in $tmp, $Path) { if (Test-Path $p) { Remove-Item $p -Recurse -Force } }
    New-Item -ItemType Directory $tmp | Out-Null
    Run "$env:SystemRoot\System32\tar.exe" -xf $zip -C $tmp
    if ($Inner) { Move-Item (Join-Path $tmp $Inner) $Path; Remove-Item $tmp -Recurse -Force }
    else { Move-Item $tmp $Path }
    Set-Content (Join-Path $Path '.rt-version') $Version -NoNewline
}

# ---------------------------------------------------------------- instalación

function Install-Binaries {
    $v = $Cfg.Versions

    # Las versiones antiguas de PHP se mueven a /archives cuando sale una nueva.
    Install-Zip 'PHP' $Dir.Php $v.Php.Version @(
        "https://windows.php.net/downloads/releases/$($v.Php.File)",
        "https://windows.php.net/downloads/releases/archives/$($v.Php.File)"
    ) $v.Php.File $v.Php.Sha256

    $xdebugMarker = "$($Dir.Php)\ext\.rt-xdebug"
    if (-not (Test-Path $xdebugMarker) -or (Get-Content $xdebugMarker -Raw) -ne $v.Xdebug.Version) {
        Step "Instalando Xdebug $($v.Xdebug.Version)"
        Copy-Item (Get-Download @("https://xdebug.org/files/$($v.Xdebug.File)") $v.Xdebug.File) "$($Dir.Php)\ext\php_xdebug.dll" -Force
        Set-Content $xdebugMarker $v.Xdebug.Version -NoNewline
    }
    if (-not (Test-Path "$($Dir.Php)\extras\ssl\cacert.pem")) {
        Copy-Item (Get-Download @('https://curl.se/ca/cacert.pem') 'cacert.pem') "$($Dir.Php)\extras\ssl\cacert.pem"
    }

    Install-Zip 'Apache' $Dir.Apache $v.Apache.Version @($v.Apache.Url) (Split-Path -Leaf $v.Apache.Url) '' 'Apache24'

    $mariaName = "mariadb-$($v.MariaDb.Version)-winx64"
    Install-Zip 'MariaDB' $Dir.MariaDb $v.MariaDb.Version @(
        "https://archive.mariadb.org/mariadb-$($v.MariaDb.Version)/winx64-packages/$mariaName.zip",
        "https://downloads.mariadb.org/rest-api/mariadb/$($v.MariaDb.Version)/$mariaName.zip"
    ) "$mariaName.zip" $v.MariaDb.Sha256 $mariaName

    Install-Zip 'Mailpit' $Dir.Mailpit $v.Mailpit.Version @(
        "https://github.com/axllent/mailpit/releases/download/v$($v.Mailpit.Version)/mailpit-windows-$($v.Mailpit.Arch).zip"
    ) "mailpit-$($v.Mailpit.Version)-windows-$($v.Mailpit.Arch).zip"

    Copy-Item (Get-Download @("https://github.com/wp-cli/wp-cli/releases/download/v$($v.WpCli.Version)/wp-cli-$($v.WpCli.Version).phar") "wp-cli-$($v.WpCli.Version).phar") $Exe.WpCli -Force
    Copy-Item (Get-Download @("https://getcomposer.org/download/$($v.Composer.Version)/composer.phar") "composer-$($v.Composer.Version).phar") $Exe.Composer -Force
}

function Get-XdebugMode {
    $file = "$($Dir.Conf)\xdebug-mode"
    if (Test-Path $file) { return (Get-Content $file -Raw).Trim() }
    return 'off'
}

function Write-PhpIni {
    $php = Fwd $Dir.Php
    $overrides = @"

; ===== .runtime: generado por scripts/rt.ps1, se sobrescribe en cada setup =====
extension_dir = "$php/ext"
extension = curl
extension = fileinfo
extension = gd
extension = intl
extension = mbstring
extension = exif
extension = mysqli
extension = openssl
extension = pdo_mysql
extension = sodium
extension = zip
zend_extension = opcache
zend_extension = xdebug

date.timezone = "$($Cfg.Site.Timezone)"
memory_limit = 512M
max_execution_time = 120
upload_max_filesize = 64M
post_max_size = 64M
log_errors = On
error_log = "$(Fwd $Dir.Logs)/php-error.log"
sys_temp_dir = "$(Fwd $Dir.Tmp)"
upload_tmp_dir = "$(Fwd $Dir.Tmp)"
session.save_path = "$(Fwd $Dir.Tmp)"
curl.cainfo = "$php/extras/ssl/cacert.pem"
openssl.cafile = "$php/extras/ssl/cacert.pem"

; Por si algo usa mail() directamente en vez de wp_mail(): también va a Mailpit.
SMTP = 127.0.0.1
smtp_port = $($Cfg.Ports.Smtp)
sendmail_from = wordpress@localhost.test

; Los cambios en el código se ven en la siguiente petición.
opcache.enable = 1
opcache.enable_cli = 0
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0

; El modo se cambia con 'rt xdebug <modo>'; en CLI manda la variable XDEBUG_MODE.
xdebug.mode = $(Get-XdebugMode)
xdebug.start_with_request = trigger
xdebug.client_host = 127.0.0.1
xdebug.client_port = 9003
xdebug.log = "$(Fwd $Dir.Logs)/xdebug.log"
xdebug.log_level = 3
"@
    Write-Text "$($Dir.Php)\php.ini" ((Get-Content "$($Dir.Php)\php.ini-development" -Raw) + $overrides)
}

function Write-Configs {
    Write-PhpIni
    $www = Fwd $Dir.Www
    $logs = Fwd $Dir.Logs
    $http = $Cfg.Ports.Http

    Write-Text "$($Dir.Conf)\httpd.conf" @"
# Generado por scripts/rt.ps1; se sobrescribe en cada setup.
Define SRVROOT "$(Fwd $Dir.Apache)"
ServerRoot "`${SRVROOT}"
ServerName localhost:$http
Listen 127.0.0.1:$http
Listen [::1]:$http
PidFile "$(Fwd $Dir.Tmp)/httpd.pid"

LoadModule alias_module modules/mod_alias.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule deflate_module modules/mod_deflate.so
LoadModule dir_module modules/mod_dir.so
LoadModule env_module modules/mod_env.so
LoadModule expires_module modules/mod_expires.so
LoadModule filter_module modules/mod_filter.so
LoadModule headers_module modules/mod_headers.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule php_module "$(Fwd $Dir.Php)/php8apache2_4.dll"

PHPIniDir "$(Fwd $Dir.Php)"
TypesConfig conf/mime.types
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
DirectoryIndex index.php index.html

DocumentRoot "$www"
<Directory "$www">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

ErrorLog "$logs/apache-error.log"
LogLevel warn
LogFormat "%h %l %u %t \"%r\" %>s %b %D" common
CustomLog "$logs/apache-access.log" common

# Ajustes habituales de Apache en Windows: sin AcceptEx ni sendfile/mmap (evitan bloqueos de
# ficheros mientras se editan) y pila amplia para las expresiones regulares de PHP.
AcceptFilter http none
EnableSendfile Off
EnableMMAP Off
<IfModule mpm_winnt_module>
    ThreadsPerChild 32
    ThreadStackSize 8388608
</IfModule>
"@

    Write-Text "$($Dir.Conf)\my.ini" @"
# Generado por scripts/rt.ps1; se sobrescribe en cada setup.
[mysqld]
basedir = $(Fwd $Dir.MariaDb)
datadir = $(Fwd $Dir.DbData)
tmpdir = $(Fwd $Dir.Tmp)
log-error = $logs/mariadb.err
port = $($Cfg.Ports.Db)
bind-address = 127.0.0.1
character-set-server = utf8mb4
max_allowed_packet = 64M
innodb_buffer_pool_size = 128M
# Entorno de pruebas: se prioriza la velocidad de escritura sobre la durabilidad.
innodb_flush_log_at_trx_commit = 2
# Con el nivel por defecto, cada comprobación de puerto de rt deja un aviso de conexión abortada.
log_warnings = 1

[client]
host = 127.0.0.1
port = $($Cfg.Ports.Db)
user = root
password = $($Cfg.Db.RootPassword)
default-character-set = utf8mb4
"@

    Write-Text "$($Dir.Conf)\wp-cli.yml" @"
path: $www
url: $BaseUrl
apache_modules:
  - mod_rewrite
"@

    # Fuente única de datos del entorno para PHPUnit, Playwright y cualquier script.
    $envData = [ordered]@{
        baseUrl = $BaseUrl
        slug    = $Cfg.Slug
        admin   = [ordered]@{ user = $Cfg.Site.AdminUser; password = $Cfg.Site.AdminPassword; email = $Cfg.Site.AdminEmail }
        db      = [ordered]@{ host = "127.0.0.1:$($Cfg.Ports.Db)"; name = $Cfg.Db.Name; testName = $Cfg.Db.TestName; user = $Cfg.Db.User; password = $Cfg.Db.Password }
        mailpit = [ordered]@{ url = $MailApi; smtpPort = $Cfg.Ports.Smtp }
        paths   = [ordered]@{ root = Fwd $Root; runtime = Fwd $RT; www = $www; plugin = Fwd $Dir.Plugin; pluginMain = Fwd $PluginMain; logs = $logs; php = Fwd $Exe.Php }
    }
    Write-Text "$($Dir.Conf)\env.json" ($envData | ConvertTo-Json -Depth 4)

    $salts = "$($Dir.Conf)\salts.php"
    if (-not (Test-Path $salts)) {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!#%&()*+,-./:;<=>?@[]^_{|}~'
        $lines = foreach ($key in 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT') {
            $value = -join (1..64 | ForEach-Object { $alphabet[[Security.Cryptography.RandomNumberGenerator]::GetInt32($alphabet.Length)] })
            "define( '$key', '$value' );"
        }
        Write-Text $salts ("<?php`n" + ($lines -join "`n") + "`n")
    }
}

function Write-WpConfig {
    Write-Text "$($Dir.Www)\wp-config.php" @"
<?php
// Generado por scripts/rt.ps1; se sobrescribe en cada setup. Los valores salen de scripts/runtime.config.psd1.

define( 'DB_NAME', '$($Cfg.Db.Name)' );
define( 'DB_USER', '$($Cfg.Db.User)' );
define( 'DB_PASSWORD', '$($Cfg.Db.Password)' );
define( 'DB_HOST', '127.0.0.1:$($Cfg.Ports.Db)' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
`$table_prefix = 'wp_';

require dirname( __DIR__ ) . '/conf/salts.php';

define( 'WP_HOME', '$BaseUrl' );
define( 'WP_SITEURL', '$BaseUrl' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', dirname( __DIR__ ) . '/logs/wp-debug.log' );
define( 'SCRIPT_DEBUG', true );

// Entorno reproducible: WordPress no se actualiza solo.
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'FS_METHOD', 'direct' );

define( 'RT_SMTP_HOST', '127.0.0.1' );
define( 'RT_SMTP_PORT', $($Cfg.Ports.Smtp) );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
"@
}

function Initialize-Database {
    if (-not (Test-Path "$($Dir.DbData)\mysql")) {
        Step 'Creando el directorio de datos de MariaDB'
        Run $Exe.InstallDb "--datadir=$($Dir.DbData)" "--password=$($Cfg.Db.RootPassword)"
    }
    Start-Svc mariadb
    $d = $Cfg.Db
    Invoke-Sql @"
CREATE DATABASE IF NOT EXISTS ``$($d.Name)``;
CREATE DATABASE IF NOT EXISTS ``$($d.TestName)``;
CREATE USER IF NOT EXISTS '$($d.User)'@'%' IDENTIFIED BY '$($d.Password)';
GRANT ALL PRIVILEGES ON ``$($d.Name)``.* TO '$($d.User)'@'%';
GRANT ALL PRIVILEGES ON ``$($d.TestName)``.* TO '$($d.User)'@'%';
FLUSH PRIVILEGES;
"@
}

function Set-PluginLink {
    New-Item -ItemType Directory -Force $Dir.Plugin | Out-Null
    $item = Get-Item $PluginLink -Force -ErrorAction SilentlyContinue
    if ($item) {
        if ($item.LinkType -eq 'Junction' -and $item.Target -eq $Dir.Plugin) { return }
        if (-not $item.LinkType) { throw "$PluginLink es una carpeta real, no un enlace. Revísala y quítala a mano." }
        Remove-PluginLink
    }
    New-Item -ItemType Junction -Path $PluginLink -Target $Dir.Plugin | Out-Null
}

# Borrado no recursivo: elimina el enlace y nunca el código del plugin al que apunta.
function Remove-PluginLink {
    $item = Get-Item $PluginLink -Force -ErrorAction SilentlyContinue
    if ($item -and $item.LinkType) { [IO.Directory]::Delete($PluginLink) }
}

function Install-WordPress {
    $s = $Cfg.Site
    if (-not (Test-Path "$($Dir.Www)\wp-load.php")) {
        # Se extrae con tar y no con `wp core download`: WP-CLI descomprime en una ruta temporal
        # tan larga que supera el límite de 260 caracteres de Windows.
        $version = $s.Version
        if ($version -eq 'latest') { $version = (Invoke-RestMethod 'https://api.wordpress.org/core/version-check/1.7/').offers[0].version }
        if (Test-Path $Dir.Www) { Remove-Item $Dir.Www -Recurse -Force }
        Install-Zip 'WordPress' $Dir.Www $version @("https://wordpress.org/wordpress-$version.zip") "wordpress-$version.zip" '' 'wordpress'
        foreach ($bundled in 'akismet', 'hello.php', 'hello-dolly') {
            $p = "$($Dir.Www)\wp-content\plugins\$bundled"
            if (Test-Path $p) { Remove-Item $p -Recurse -Force }
        }
    }
    Write-WpConfig
    New-Item -ItemType Directory -Force "$($Dir.Www)\wp-content\mu-plugins" | Out-Null
    Copy-Item "$PSScriptRoot\templates\rt-environment.php" "$($Dir.Www)\wp-content\mu-plugins\rt-environment.php" -Force
    Set-PluginLink

    & $Exe.Php $Exe.WpCli core is-installed 2>$null
    if ($LASTEXITCODE -eq 0) { return }

    Step 'Instalando WordPress'
    Wp core install "--url=$BaseUrl" "--title=$($s.Title)" "--admin_user=$($s.AdminUser)" "--admin_password=$($s.AdminPassword)" "--admin_email=$($s.AdminEmail)" --skip-email
    # Ya no se publican paquetes de WordPress por idioma: se instala el paquete de traducción aparte.
    if ($s.Locale -ne 'en_US') { Wp language core install $s.Locale --activate }
    Wp option update timezone_string $s.Timezone
    Wp rewrite structure '/%postname%/' --hard
    Save-Snapshot 'clean'
}

function Install-DevDeps {
    In-Root {
        if (Test-Path 'composer.json') {
            Step 'Instalando dependencias de Composer'
            Run $Exe.Php $Exe.Composer install --no-interaction --no-progress
        }
        if (Test-Path 'package.json') {
            Step 'Instalando dependencias de npm y Chromium para Playwright'
            Run npm.cmd install --no-fund --no-audit
            Run npx.cmd playwright install chromium
        }
    }
}

# ---------------------------------------------------------------- servicios

function Get-SvcProc([string]$Name) {
    $exe = $Services[$Name].Exe
    Get-Process -Name ([IO.Path]::GetFileNameWithoutExtension($exe)) -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $exe }
}

function Start-Svc([string]$Name) {
    $s = $Services[$Name]
    if (Get-SvcProc $Name) {
        if (Test-Port $s.Port) { return }
        Stop-Svc $Name
    }
    if (Test-Port $s.Port) { throw "El puerto $($s.Port) ($Name) lo usa otro programa. Cámbialo en scripts/runtime.config.psd1 y repite 'rt setup'." }
    if (-not (Test-Path $s.Exe)) { throw "Falta $($s.Exe). Ejecuta primero 'rt setup'." }

    # Un error de sintaxis en httpd.conf solo sale por la consola, así que se valida antes.
    if ($Name -eq 'apache') {
        $svcArgs = $s.Args
        $check = & $s.Exe -t @svcArgs 2>&1
        if ($LASTEXITCODE -ne 0) { throw "httpd.conf no es válido:`n$($check -join "`n")" }
    }

    # Sin redirigir la salida a propósito: así el servicio no hereda los descriptores de este script
    # y quien capture la salida de `rt` no se queda esperando a que el servicio termine.
    # Cada servicio escribe su propio log en .runtime/logs. Start-Process no entrecomilla los argumentos.
    $argLine = ($s.Args | ForEach-Object { if ($_ -match '\s') { "`"$_`"" } else { $_ } }) -join ' '
    Start-Process -FilePath $s.Exe -ArgumentList $argLine -WorkingDirectory $RT -WindowStyle Hidden

    $deadline = (Get-Date).AddSeconds(90)
    while (-not (Test-Port $s.Port)) {
        if (-not (Get-SvcProc $Name) -or (Get-Date) -gt $deadline) {
            $tail = Get-ChildItem $Dir.Logs -Filter "$Name*" | ForEach-Object { "--- $($_.Name)"; Get-Content $_.FullName -Tail 15 }
            throw "$Name no ha arrancado en el puerto $($s.Port).`n$($tail -join "`n")"
        }
        Start-Sleep -Milliseconds 300
    }
}

function Stop-Svc([string]$Name) {
    if (-not (Get-SvcProc $Name)) { return }
    if ($Name -eq 'mariadb' -and (Test-Port $Services[$Name].Port)) {
        & $Exe.Admin $DbConn shutdown 2>$null | Out-Null
        for ($i = 0; $i -lt 100 -and (Get-SvcProc $Name); $i++) { Start-Sleep -Milliseconds 200 }
    }
    Get-SvcProc $Name | Stop-Process -Force -ErrorAction SilentlyContinue
    for ($i = 0; $i -lt 50 -and (Get-SvcProc $Name); $i++) { Start-Sleep -Milliseconds 200 }
}

function Start-All { foreach ($name in $Services.Keys) { Start-Svc $name } }
function Stop-All { foreach ($name in @($Services.Keys)[($Services.Count - 1)..0]) { Stop-Svc $name } }

function Get-Status {
    $urls = @{ apache = $BaseUrl; mailpit = $MailApi; mariadb = "127.0.0.1:$($Cfg.Ports.Db)" }
    foreach ($name in $Services.Keys) {
        $procs = @(Get-SvcProc $name)
        [pscustomobject]@{
            servicio = $name
            estado   = if ($procs -and (Test-Port $Services[$name].Port)) { 'activo' } elseif ($procs) { 'arrancando' } else { 'parado' }
            puerto   = $Services[$name].Port
            pid      = $procs.Id -join ','
            url      = $urls[$name]
        }
    }
}

function Show-Status {
    Get-Status | Format-Table -AutoSize | Out-Host
    Write-Host "WordPress: $BaseUrl/wp-admin/  (usuario $($Cfg.Site.AdminUser) / contraseña $($Cfg.Site.AdminPassword))"
    Write-Host "Mailpit:   $MailApi"
}

# ---------------------------------------------------------------- datos

function Invoke-Sql([string]$Sql) {
    $Sql | & $Exe.Mariadb $DbConn | Out-Host
    if ($LASTEXITCODE -ne 0) { throw 'La consulta SQL ha fallado.' }
}

function Save-Snapshot([string]$Name) {
    Run $Exe.Dump $DbConn --databases $Cfg.Db.Name --add-drop-database "--result-file=$($Dir.Snaps)\$Name.sql"
    Write-Host "Snapshot guardado: $Name"
}

function Restore-Snapshot([string]$Name) {
    $file = "$($Dir.Snaps)\$Name.sql"
    if (-not (Test-Path $file)) { throw "No existe el snapshot '$Name'. Disponibles: $((Get-ChildItem $Dir.Snaps -Filter *.sql).BaseName -join ', ')" }
    # `source` lo lee el cliente directamente; pasarlo por la tubería de PowerShell corrompería los binarios.
    Run $Exe.Mariadb $DbConn -e "source $(Fwd $file)"
    Write-Host "Snapshot restaurado: $Name"
}

function Clear-Mail { Invoke-RestMethod -Method Delete "$MailApi/api/v1/messages" | Out-Null }

# ---------------------------------------------------------------- comandos

switch ($Command) {
    'setup' {
        foreach ($d in 'Cache', 'Bin', 'Conf', 'Data', 'Snaps', 'Logs', 'Tmp') { New-Item -ItemType Directory -Force $Dir[$d] | Out-Null }
        Stop-All
        Install-Binaries
        Write-Configs
        Initialize-Database
        Start-Svc mailpit
        Install-WordPress
        Start-Svc apache
        Install-DevDeps
        Step 'Entorno listo'
        Show-Status
    }
    'start' { Start-All; Show-Status }
    'stop' { Stop-All; Write-Host 'Servicios detenidos.' }
    'restart' { Stop-All; Start-All; Show-Status }
    'status' {
        if ($Rest -contains '--json') { ConvertTo-Json @(Get-Status) } else { Show-Status }
    }

    'wp' { & $Exe.Php $Exe.WpCli @Rest; exit $LASTEXITCODE }
    'php' { & $Exe.Php @Rest; exit $LASTEXITCODE }
    'sql' { & $Exe.Mariadb $DbConn $Cfg.Db.Name @Rest; exit $LASTEXITCODE }
    'composer' { In-Root { & $Exe.Php $Exe.Composer @Rest }; exit $LASTEXITCODE }

    'phpunit' {
        Start-Svc mariadb
        if (-not $env:XDEBUG_MODE) { $env:XDEBUG_MODE = if ($Rest -match '^--coverage') { 'coverage' } else { 'off' } }
        In-Root { & $Exe.Php vendor\phpunit\phpunit\phpunit @Rest }
        exit $LASTEXITCODE
    }
    'lint' {
        # `rt lint` revisa; `rt lint fix` corrige lo que PHPCS sabe arreglar solo.
        $env:XDEBUG_MODE = 'off'
        $tool, $lintArgs = if ($Rest[0] -eq 'fix') { 'phpcbf', @($Rest | Select-Object -Skip 1) } else { 'phpcs', $Rest }
        In-Root { & $Exe.Php "vendor/squizlabs/php_codesniffer/bin/$tool" @lintArgs }
        exit $LASTEXITCODE
    }
    'e2e' {
        Start-All
        In-Root { & npx.cmd playwright test @Rest }
        exit $LASTEXITCODE
    }
    'playwright' { In-Root { & npx.cmd playwright @Rest }; exit $LASTEXITCODE }
    'build' {
        # Compila src/ (constructor en React y bloque) a plugin/build con @wordpress/scripts.
        In-Root { & npm.cmd run build @Rest }
        exit $LASTEXITCODE
    }
    'shot' {
        # Captura de pantalla con el Chromium de .runtime; las opciones están en scripts/shot.mjs.
        Start-All
        In-Root { & node scripts/shot.mjs @Rest }
        exit $LASTEXITCODE
    }
    'i18n' {
        # Regenera la plantilla de traducción y, a partir de scripts/i18n/<locale>.json, los .po, .mo y .json
        # de plugin/languages. Falla si algún texto de la interfaz se ha quedado sin traducir.
        $languages = Join-Path $Dir.Plugin 'languages'
        New-Item -ItemType Directory -Force $languages | Out-Null
        Run $Exe.Php $Exe.WpCli i18n make-pot $Dir.Plugin "$languages\$($Cfg.Slug).pot" "--slug=$($Cfg.Slug)" "--domain=$($Cfg.Slug)" --skip-audit
        In-Root { & node scripts/i18n-build.mjs $Cfg.Slug }
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        Get-ChildItem $languages -Include *.json, *.l10n.php -File -Recurse | Remove-Item
        Run $Exe.Php $Exe.WpCli i18n make-mo $languages
        # WordPress carga antes el .l10n.php que el .mo: es un include de PHP, mucho más rápido.
        Run $Exe.Php $Exe.WpCli i18n make-php $languages
        Run $Exe.Php $Exe.WpCli i18n make-json $languages --no-purge
        exit 0
    }

    'snapshot' { Save-Snapshot ($Rest[0] ?? 'manual') }
    'restore' { Restore-Snapshot ($Rest[0] ?? 'manual') }
    'reset' {
        Start-All
        Restore-Snapshot 'clean'
        Get-ChildItem "$($Dir.Www)\wp-content\uploads" -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force
        Clear-Mail
        if (Test-Path $PluginMain) { Wp plugin activate $Cfg.Slug }
        Write-Host 'WordPress restaurado al estado recién instalado.'
    }

    'mail' {
        if ($Rest[0] -eq 'clear') { Clear-Mail; Write-Host 'Buzón vaciado.'; break }
        $limit = ($Rest | Where-Object { $_ -match '^\d+$' } | Select-Object -First 1) ?? 20
        $messages = (Invoke-RestMethod "$MailApi/api/v1/messages?limit=$limit").messages
        if ($Rest -contains '--json') { ConvertTo-Json @($messages) -Depth 6; break }
        if (-not $messages) { Write-Host 'No hay correos capturados.'; break }
        $messages | ForEach-Object {
            [pscustomobject]@{
                hora   = ([datetime]$_.Created).ToString('HH:mm:ss')
                de     = $_.From.Address
                para   = ($_.To | ForEach-Object { $_.Address }) -join ','
                asunto = $_.Subject
            }
        } | Format-Table -AutoSize
    }

    'logs' {
        $files = [ordered]@{
            wp      = 'wp-debug.log'; php = 'php-error.log'; apache = 'apache-error.log'; access = 'apache-access.log'
            mariadb = 'mariadb.err'; mailpit = 'mailpit.log'; xdebug = 'xdebug.log'
        }
        $name = $Rest[0]
        if ($name -eq 'clear') {
            # Solo los que PHP abre en cada escritura; Apache y MariaDB mantienen los suyos abiertos.
            foreach ($f in 'wp', 'php') { $p = Join-Path $Dir.Logs $files[$f]; if (Test-Path $p) { Clear-Content $p } }
            Write-Host 'Vaciados wp-debug.log y php-error.log.'
        }
        elseif ($name -and $files.Contains($name)) {
            $path = Join-Path $Dir.Logs $files[$name]
            if (Test-Path $path) { Get-Content $path -Tail ([int]($Rest[1] ?? 40)) } else { Write-Host "(vacío) $path" }
        }
        else {
            Write-Host "Uso: rt logs <$($files.Keys -join '|')|clear> [líneas]"
            Get-ChildItem $Dir.Logs -ErrorAction SilentlyContinue | Select-Object Name, Length, LastWriteTime | Format-Table -AutoSize
        }
    }

    'xdebug' {
        $mode = $Rest[0]
        if (-not $mode) { Write-Host "Modo actual: $(Get-XdebugMode)"; break }
        Write-Text "$($Dir.Conf)\xdebug-mode" $mode
        Write-PhpIni
        if (Get-SvcProc apache) { Stop-Svc apache; Start-Svc apache }
        Write-Host "Xdebug en modo '$mode'."
    }

    'destroy' {
        Stop-All
        Remove-PluginLink
        $keep = if ($Rest -contains '--all') { @() } else { @('cache') }
        Get-ChildItem $RT -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -notin $keep } | Remove-Item -Recurse -Force
        Write-Host "Eliminado .runtime$(if ($keep) { ' (se conserva la caché de descargas; usa --all para borrarla)' })."
    }

    default {
        Write-Host @"
Entorno local de WordPress en .runtime/   ->   $BaseUrl

  rt setup                 Descarga, configura e instala todo (idempotente)
  rt start | stop | restart | status [--json]
  rt reset                 Devuelve WordPress al estado recién instalado y activa el plugin
  rt snapshot [nombre]     Guarda la base de datos      rt restore [nombre]   La recupera
  rt destroy [--all]       Borra .runtime (--all incluye la caché de descargas)

  rt wp <args>             WP-CLI contra este WordPress
  rt php <args>            PHP $($Cfg.Versions.Php.Version) del runtime
  rt composer <args>       Composer con ese PHP
  rt sql [-e "consulta"]   Cliente MariaDB sobre la base de datos de WordPress

  rt phpunit [args]        Tests de integración (usa --coverage-text o similar para cobertura)
  rt lint [fix] [args]     PHPCS sobre plugin/: estándares de WordPress y compatibilidad con PHP 8.1+
  rt e2e [args]            Tests de Playwright      rt playwright <args>   Resto de comandos de Playwright
  rt build                 Compila src/ (React y bloque) a plugin/build
  rt i18n                  Regenera el .pot y las traducciones de plugin/languages desde scripts/i18n/*.json
  rt shot <ruta> <png> [opciones]   Captura de pantalla (opciones en scripts/shot.mjs)
  rt mail [n|clear] [--json]   Correos capturados por Mailpit
  rt logs [wp|php|apache|access|mariadb|mailpit|xdebug|clear] [líneas]
  rt xdebug [off|debug|develop,debug|coverage]
"@
    }
}
