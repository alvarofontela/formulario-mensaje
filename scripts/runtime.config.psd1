@{
    # Slug del plugin: nombre de la carpeta dentro de wp-content/plugins y del fichero principal (<slug>.php).
    Slug         = 'formulario-mensaje'
    # Carpeta del proyecto con el código del plugin; se enlaza por junction dentro del WordPress.
    PluginSource = 'plugin'

    Ports = @{
        Http   = 8088   # WordPress (Apache)
        Db     = 3307   # MariaDB
        Smtp   = 1025   # Mailpit SMTP
        MailUi = 8025   # Mailpit interfaz web + API
    }

    Site = @{
        Version       = 'latest'
        Locale        = 'es_ES'
        Title         = 'Formulario Mensaje (dev)'
        AdminUser     = 'admin'
        AdminPassword = 'admin'
        AdminEmail    = 'admin@example.test'
        Timezone      = 'Europe/Madrid'
    }

    Db = @{
        Name         = 'wordpress'
        TestName     = 'wordpress_tests'   # la usa PHPUnit; se vacía en cada ejecución
        User         = 'wp'
        Password     = 'wp'
        RootPassword = 'root'
    }

    # Binarios portables. Al cambiar una versión, `rt setup` reinstala solo ese componente.
    # Todos son x64: no hay builds ARM64 para Windows salvo Mailpit.
    Versions = @{
        Php = @{
            Version = '8.3.35'
            File    = 'php-8.3.35-Win32-vs16-x64.zip'   # thread-safe, necesario para mod_php
            Sha256  = 'b454bed828a74536125d8a96119eae8ffbb186b305759dd6cf8d9bb4b55968b1'
        }
        Apache = @{
            Version = '2.4.69'
            Url     = 'https://www.apachelounge.com/download/VS18/binaries/httpd-2.4.69-261002-Win64-VS18.zip'
        }
        MariaDb = @{
            Version = '11.8.9'
            Sha256  = '830c46727d9278eae212ae3eca44eeb9e71b2a68704e95f344a64fba7b1963f5'
        }
        Mailpit  = @{ Version = '1.31.4'; Arch = 'arm64' }
        Xdebug   = @{ Version = '3.5.3';  File = 'php_xdebug-3.5.3-8.3-ts-vs16-x86_64.dll' }
        WpCli    = @{ Version = '2.12.0' }
        Composer = @{ Version = '2.10.3' }
    }
}
