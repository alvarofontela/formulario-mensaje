"""Crea en dist/ el zip instalable del plugin.

Uso: python scripts/package.py <slug> <carpeta del plugin> <carpeta de salida> [<fuente>...]

Lo llama `rt package`. El zip se genera aquí, y no con el compresor de Windows, para que sea igual
que uno hecho en Linux, que es donde suele acabar instalándose:

- las rutas llevan siempre «/», nunca «\\»;
- cada entrada declara que viene de un sistema Unix y trae sus permisos: 644 los ficheros y 755
  las carpetas, que es lo que espera un servidor web (un zip hecho en Windows no trae permisos y
  cada herramienta se inventa los suyos al descomprimir);
- los nombres van en UTF-8;
- dentro hay una única carpeta con el slug del plugin, que es la que WordPress crea al instalarlo.

Empaqueta la carpeta del plugin tal como está: antes hay que haber pasado `rt build` e `rt i18n`
si se ha tocado src/ o algún texto.

Las fuentes que se indiquen al final (carpetas o ficheros) se añaden en la raíz del plugin con su mismo
nombre. Son el código legible de lo que va compilado en `build/`: WordPress.org exige que acompañe al
plugin o que esté en un sitio público (directriz 4 de su directorio).
"""

import re
import sys
import time
import zipfile
from pathlib import Path

# Lo que no forma parte del plugin aunque esté en su carpeta: restos del sistema y de editores.
SKIP_NAMES = {'.DS_Store', 'Thumbs.db', 'ehthumbs.db', 'desktop.ini', 'Desktop.ini'}
SKIP_SUFFIXES = {'.log', '.tmp', '.bak', '.orig', '.swp', '.map'}

FILE_MODE = 0o100644
DIR_MODE = 0o040755
UNIX = 3
MSDOS_DIRECTORY = 0x10


def skipped(path: Path) -> bool:
    """Indica si un fichero o carpeta se queda fuera del paquete."""
    return path.name in SKIP_NAMES or path.name.startswith('.') or path.suffix in SKIP_SUFFIXES or path.name.endswith('~')


def version_of(main_file: Path) -> str:
    """Versión declarada en la cabecera del fichero principal del plugin."""
    match = re.search(r'^\s*\*\s*Version:\s*(\S+)', main_file.read_text(encoding='utf-8'), flags=re.M)

    if not match:
        raise SystemExit(f'No se encuentra la versión en {main_file}.')

    return match.group(1)


def entry(name: str, mode: int, modified: float, directory: bool = False) -> zipfile.ZipInfo:
    """Entrada del zip con los atributos de un sistema Unix."""
    info = zipfile.ZipInfo(name, date_time=time.localtime(modified)[:6])

    info.create_system = UNIX
    info.external_attr = (mode << 16) | (MSDOS_DIRECTORY if directory else 0)
    info.compress_type = zipfile.ZIP_STORED if directory else zipfile.ZIP_DEFLATED

    return info


def main() -> None:
    if len(sys.argv) < 4:
        raise SystemExit(__doc__)

    slug = sys.argv[1]
    source = Path(sys.argv[2]).resolve()
    output = Path(sys.argv[3]).resolve()
    extras = [Path(extra).resolve() for extra in sys.argv[4:]]
    main_file = source / f'{slug}.php'

    if not main_file.is_file():
        raise SystemExit(f'No existe {main_file}.')

    target = output / f'{slug}-{version_of(main_file)}.zip'
    # Cada ruta con el nombre que tendrá dentro del plugin. Fuera lo que se salta y todo lo que cuelgue de
    # una carpeta que se salta.
    members = {path.relative_to(source).as_posix(): path for path in source.rglob('*') if not any(skipped(Path(part)) for part in path.relative_to(source).parts)}

    for extra in extras:
        if not extra.exists():
            raise SystemExit(f'No existe {extra}.')

        for path in [extra, *(extra.rglob('*') if extra.is_dir() else [])]:
            name = path.relative_to(extra.parent).as_posix()

            if any(skipped(Path(part)) for part in Path(name).parts):
                continue

            if name in members:
                raise SystemExit(f'{name} está en el plugin y también entre las fuentes que se añaden.')

            members[name] = path

    # En orden, para que el zip sea siempre igual.
    paths = sorted(members.items())

    output.mkdir(parents=True, exist_ok=True)

    files = 0

    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as package:
        package.writestr(entry(f'{slug}/', DIR_MODE, source.stat().st_mtime, True), b'')

        for relative, path in paths:
            name = f'{slug}/{relative}'

            if path.is_dir():
                package.writestr(entry(f'{name}/', DIR_MODE, path.stat().st_mtime, True), b'')
            else:
                package.writestr(entry(name, FILE_MODE, path.stat().st_mtime), path.read_bytes())
                files += 1

    # Se vuelve a abrir para comprobar que lo escrito se lee entero y como se esperaba.
    with zipfile.ZipFile(target) as package:
        broken = package.testzip()
        names = package.namelist()

        if broken is not None:
            raise SystemExit(f'El paquete está dañado: {broken}.')

        if any('\\' in name or not name.startswith(f'{slug}/') for name in names):
            raise SystemExit('El paquete tiene rutas que no son del plugin o con barras invertidas.')

    print(f'Paquete creado: {target} ({files} ficheros, {round(target.stat().st_size / 1024)} KB)')


if __name__ == '__main__':
    main()
