"""Prepara la publicación de una versión a partir de su tag.

Uso: python scripts/release.py <tag> <carpeta del plugin> <carpeta de salida>

Lo llama el flujo de GitHub Actions (.github/workflows/release.yml) cuando se sube un tag de versión.
Comprueba que el tag es una versión SemVer y que es la misma que declara el plugin, y escribe en la
carpeta de salida:

- release.json: lo que el plugin instalado en una web necesita saber de la versión para ofrecerla
  como actualización (versión, requisitos, fecha y registro de cambios). Lo lee
  plugin/includes/updates/class-release-manifest.php. No dice de dónde se descarga el paquete: esa
  dirección la compone el plugin a partir de la versión.
- release-notes.md: las notas de la release de GitHub, que son la entrada de esa versión en el
  registro de cambios del readme.txt.

Termina con error, y no se publica nada, si el tag y el plugin no cuentan lo mismo.
"""

import json
import re
import sys
import time
from pathlib import Path

# SemVer 2.0.0 sin metadatos de compilación: «+» no puede ir en la dirección de descarga.
SEMVER = re.compile(r'^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-(0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?$')

# Versiones del registro de cambios que viajan en release.json, de la más nueva a la más antigua.
CHANGELOG_ENTRIES = 10


def header(text: str, name: str, source: str) -> str:
    """Valor de una cabecera como «Version: 1.0.0», en el fichero principal o en el readme.txt."""
    match = re.search(rf'^[ \t/*#@]*{re.escape(name)}:[ \t]*(\S.*?)[ \t]*$', text, flags=re.M | re.I)

    if not match:
        raise SystemExit(f'Falta «{name}» en {source}.')

    return match.group(1)


def changelog_entries(readme: str) -> list[tuple[str, str]]:
    """Entradas del registro de cambios del readme.txt, como pares (versión, texto), en su orden."""
    section = re.search(r'^== Changelog ==[ \t]*\n(.*?)(?=^== |\Z)', readme, flags=re.M | re.S)

    if not section:
        raise SystemExit('El readme.txt no tiene la sección «== Changelog ==».')

    parts = re.split(r'^= *(\S+?) *=[ \t]*$', section.group(1), flags=re.M)

    # parts: lo anterior a la primera entrada y, después, versión y texto alternados.
    return [(parts[index], parts[index + 1].strip()) for index in range(1, len(parts) - 1, 2)]


def main() -> None:
    if len(sys.argv) != 4:
        raise SystemExit(__doc__)

    tag = sys.argv[1]
    plugin = Path(sys.argv[2]).resolve()
    output = Path(sys.argv[3]).resolve()

    if not SEMVER.match(tag):
        raise SystemExit(f'El tag «{tag}» no es una versión SemVer (1.2.3 o 1.2.3-beta.1, sin «v» delante).')

    # El fichero principal es el que lleva la cabecera del plugin; uninstall.php no la tiene.
    candidates = [path for path in sorted(plugin.glob('*.php')) if re.search(r'^\s*\*\s*Plugin Name:', path.read_text(encoding='utf-8'), flags=re.M)]

    if len(candidates) != 1:
        raise SystemExit(f'Se esperaba un único fichero con la cabecera «Plugin Name» en {plugin}.')

    main_file = candidates[0]
    code = main_file.read_text(encoding='utf-8')
    readme = (plugin / 'readme.txt').read_text(encoding='utf-8').replace('\r\n', '\n')
    constant = re.search(r"define\(\s*'[A-Z_]+_VERSION'\s*,\s*'([^']+)'\s*\)", code)

    declared = {
        f'la cabecera «Version» de {main_file.name}': header(code, 'Version', main_file.name),
        f'la constante de versión de {main_file.name}': constant.group(1) if constant else '',
        '«Stable tag» del readme.txt': header(readme, 'Stable tag', 'readme.txt'),
    }

    wrong = [f'{where} dice «{value}»' for where, value in declared.items() if value != tag]

    if wrong:
        raise SystemExit(f'El tag es «{tag}», pero ' + '; '.join(wrong) + '.')

    entries = changelog_entries(readme)
    notes = next((text for version, text in entries if version == tag), '')

    if not notes:
        raise SystemExit(f'El readme.txt no tiene la entrada «= {tag} =» en su registro de cambios.')

    manifest = {
        'version': tag,
        'requires': header(code, 'Requires at least', main_file.name),
        'requires_php': header(code, 'Requires PHP', main_file.name),
        'tested': header(readme, 'Tested up to', 'readme.txt'),
        'released': time.strftime('%Y-%m-%d', time.gmtime()),
        'changelog': '\n\n'.join(f'= {version} =\n{text}' for version, text in entries[:CHANGELOG_ENTRIES]),
    }

    output.mkdir(parents=True, exist_ok=True)
    (output / 'release.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8', newline='\n')
    (output / 'release-notes.md').write_text(notes + '\n', encoding='utf-8', newline='\n')

    print(f'Versión {tag} lista: release.json y release-notes.md en {output}.')


if __name__ == '__main__':
    main()
