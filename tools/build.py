#!/usr/bin/env python3
"""Build installable plugin before verification, per the requested delivery order."""
from pathlib import Path
import zipfile,hashlib,json

root=Path(__file__).resolve().parents[1]
dist=root/'dist';dist.mkdir(exist_ok=True)
target=dist/'mik-shop-1.0.0.zip'
with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for p in sorted((root/'mik-shop').rglob('*')):
        if p.is_file():z.write(p,p.relative_to(root))
    for name in ['INSTALL.md','IMPORT.md']:
        z.write(root/'docs'/name,'mik-shop/docs/'+name)
print(json.dumps({'archive':str(target),'bytes':target.stat().st_size,'sha256':hashlib.sha256(target.read_bytes()).hexdigest()}))
