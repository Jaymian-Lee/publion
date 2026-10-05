"""Build only the installable plugin; exclude checkout instructions and old PDF claims."""
from pathlib import Path
import hashlib
import json
import re
import zipfile

repo = Path(__file__).resolve().parents[1]
plugin = repo / 'publion'
version = re.search(r'^Version: (.+)$', (plugin / 'publion.php').read_text(encoding='utf-8'), re.M).group(1)
assert re.search(r"define\( 'PUBLION_VERSION', '([^']+)' \);", (plugin / 'publion.php').read_text(encoding='utf-8')).group(1) == version
assert re.search(r'^Stable tag: (.+)$', (plugin / 'readme.txt').read_text(encoding='utf-8'), re.M).group(1) == version
output = repo / f'publion-wordpress-{version}.zip'
files = [p for p in sorted(plugin.rglob('*')) if p.is_file() and p.name not in {'AGENTS.md', 'publion-documentation.pdf'}]
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in files:
        archive.write(path, 'publion/' + path.relative_to(plugin).as_posix())
with zipfile.ZipFile(output) as archive:
    assert archive.testzip() is None
    names = archive.namelist()
    assert 'publion/publion.php' in names
    assert 'publion/includes/functions-safety.php' in names
    assert 'publion/includes/functions-evidence.php' in names
    assert 'publion/includes/functions-media-pipeline.php' in names
    assert all(n.startswith('publion/') and '..' not in n.split('/') for n in names)
    for path in files:
        assert archive.read('publion/' + path.relative_to(plugin).as_posix()) == path.read_bytes()
manifest = {'version': version, 'zip': output.name, 'sha256': hashlib.sha256(output.read_bytes()).hexdigest(), 'files': len(files), 'bytes': output.stat().st_size}
(repo / 'release-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
print(json.dumps(manifest))
