#!/usr/bin/env python3
"""Build the installable plugin without running checks."""
from pathlib import Path
import hashlib
import json
import re
import zipfile

root = Path(__file__).resolve().parents[1]
dist = root / 'dist'
dist.mkdir(exist_ok=True)
version = re.search(r'^ \* Version: (\d+\.\d+\.\d+)$', (root / 'mik-shop/mik-shop.php').read_text(), re.M).group(1)
target = dist / f'mik-shop-{version}.zip'
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted((root / 'mik-shop').rglob('*')):
        relative = path.relative_to(root / 'mik-shop')
        if path.is_file() and relative.parts[0] != 'docs':
            archive.write(path, Path('mik-shop') / relative)
    for path in sorted((root / 'docs').glob('*.md')):
        archive.write(path, 'mik-shop/docs/' + path.name)
print(json.dumps({'archive': str(target), 'bytes': target.stat().st_size, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest()}))
