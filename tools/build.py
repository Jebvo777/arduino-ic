#!/usr/bin/env python3
"""Build the installable plugin without running checks."""
from pathlib import Path
import zipfile,hashlib,json,re

root=Path(__file__).resolve().parents[1]
dist=root/'dist';dist.mkdir(exist_ok=True)
version=re.search(r'^ \* Version: (\d+\.\d+\.\d+)$',(root/'mik-shop/mik-shop.php').read_text(),re.M).group(1)
target=dist/f'mik-shop-{version}.zip'
with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for p in sorted((root/'mik-shop').rglob('*')):
        if p.is_file():z.write(p,p.relative_to(root))
    for name in ['INSTALL.md','IMPORT.md','UPDATE-1.1.0.md','UPDATE-1.2.0.md','UPDATE-1.2.1.md','UPDATE-1.2.2.md']:
        z.write(root/'docs'/name,'mik-shop/docs/'+name)
print(json.dumps({'archive':str(target),'bytes':target.stat().st_size,'sha256':hashlib.sha256(target.read_bytes()).hexdigest()}))
