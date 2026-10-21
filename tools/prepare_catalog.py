#!/usr/bin/env python3
"""Read an existing export and SQL as data, never execute SQL. No customer tables are read.

python tools/prepare_catalog.py --xlsx catalog.xlsx --sql legacy.sql --map site-map.csv --out data
"""
import argparse, base64, csv, hashlib, html, json, re, zipfile, sqlite3
from collections import Counter, defaultdict
from pathlib import Path
from urllib.parse import unquote, urlparse
import xml.etree.ElementTree as ET

NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}

def sheet(z, number):
    """The supplied workbook stores inline strings; also support shared strings."""
    shared=[]
    if 'xl/sharedStrings.xml' in z.namelist():
        shared=[''.join(t.text or '' for t in n.findall('.//m:t',NS)) for n in ET.fromstring(z.read('xl/sharedStrings.xml'))]
    with z.open(f'xl/worksheets/sheet{number}.xml') as stream:
        for _, row in ET.iterparse(stream,events=('end',)):
            if row.tag != '{'+NS['m']+'}row': continue
            vals=[]
            for cell in row:
                v=cell.find('m:v',NS)
                value=v.text if v is not None else ''.join(t.text or '' for t in cell.findall('.//m:t',NS))
                if cell.get('t')=='s':value=shared[int(value)]
                col=re.sub(r'\d','',cell.get('r','A')); index=0
                for c in col:index=index*26+ord(c)-64
                while len(vals)<index:vals.append('')
                vals[index-1]=value or ''
            if int(row.get('r','0'))>=6:yield vals
            row.clear()

def plain(s):
    s=re.sub(r'<(?:script|style)\b[^>]*>.*?</(?:script|style)>','',str(s),flags=re.S|re.I)
    s=re.sub(r'<(?:br|/p|/div|/li|/tr)\b[^>]*>','\n',s,flags=re.I)
    return re.sub(r'[ \t]+',' ',html.unescape(re.sub('<[^>]+>','',s))).strip()

def number(s):
    s=str(s).replace('\xa0','').replace(' ','').replace(',','.')
    m=re.match(r'^-?\d+(?:\.\d+)?',s)
    return max(0,float(m.group())) if m else None

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--xlsx',required=True);ap.add_argument('--sql',required=True);ap.add_argument('--map',required=True);ap.add_argument('--out',required=True);a=ap.parse_args()
    out=Path(a.out);out.mkdir(parents=True,exist_ok=True)
    with open(a.map,encoding='utf-8-sig') as f:
        mapping={r['Путь'].replace('\\','/'):int(r['Размер_байт']) for r in csv.DictReader(f,delimiter=';') if r['Тип']=='Файл'}
    def path_for(s):
        s=unquote(html.unescape(s)).replace('\\','/').split('?',1)[0].split('#',1)[0]
        if s.startswith(('http://','https://')):
            u=urlparse(s)
            if u.hostname not in ('arduino-ic.ru','www.arduino-ic.ru'):return ''
            s=u.path
        while s.startswith(('../','./')):s=s.split('/',1)[1]
        s=s.lstrip('/')
        for candidate in (s,'search/'+s):
            if candidate in mapping:return candidate
            parent,_,filename=candidate.rpartition('/')
            for prefix in ('d_','s_'):
                thumb=parent+'/'+prefix+filename
                if thumb in mapping:return thumb
        return s if not '..' in s.split('/') else ''
    specs=defaultdict(list);images=defaultdict(list)
    cache=sqlite3.connect(str(out/'source-texts.sqlite'))
    cache.execute('CREATE TABLE IF NOT EXISTS texts (id TEXT, body TEXT)')
    cache.execute('DELETE FROM texts')
    with zipfile.ZipFile(a.xlsx) as z:
        for r in sheet(z,2):
            if len(r)>=5 and r[0]:specs[r[0]].append({'label':plain(r[3]),'value':plain(r[4]),'unit':''})
        for r in sheet(z,3):
            if len(r)>4 and r[4]:images[r[0]].append(r[4])
        for r in sheet(z,6):
            if len(r)>7:cache.execute('INSERT INTO texts VALUES (?,?)',(r[0],r[7]))
        cache.execute('CREATE INDEX IF NOT EXISTS texts_id ON texts(id)');cache.commit()
    # Decode embedded images from product HTML only, never parse customer/order INSERTs.
    embedded={}
    reading_products=False
    for block in Path(a.sql).open(encoding='utf-8',errors='replace'):
        if block.startswith('INSERT INTO '):reading_products=block.startswith('INSERT INTO `aleksnet_document`')
        if not reading_products or 'data:image/' not in block:continue
        for m in re.finditer(r'data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)',block):
            try:
                b=base64.b64decode(re.sub(r'\\[rn]|\s','',m[2]),validate=True)
                if len(b)>20:embedded['mik-embedded/'+hashlib.sha256(b).hexdigest()+'.'+('jpg' if m[1]=='jpeg' else m[1])]=b
            except (ValueError,base64.binascii.Error):pass
    # XLSX image rows already name embedded files by their hash; resolve those against SQL bytes.
    hash_paths={p.rsplit('/',1)[1].split('.')[0]:p for p in embedded}
    z=zipfile.ZipFile(a.xlsx)
    products=sheet(z,1)
    counts=Counter();seen=set();rows=[];missing=set();direct_ozon=0;source_rows=0;recovered=[]
    for r in products:
        source_rows+=1
        if len(r)<3:continue
        r += [''] * max(0,43-len(r))
        unnamed=not plain(r[2])
        if unnamed:
            r[2]=r[21] or r[4] or ('Товар '+r[0])
            recovered.append({'legacy_id':r[0],'original_sku':r[1],'recovered_name':r[2],'note':'В источнике пустое наименование. Использовано альтернативное; SKU заменён на legacy-ID. Цены и остатки сохранены без исправления.'})
        lid,original_sku,name=r[0],r[1].strip(),plain(r[2])
        counts[original_sku]+=1
        if name.lower() in ('test1','test','тест') and r[6] in ('','0'):continue
        sku=f'legacy-{lid}' if unnamed else (original_sku or f'legacy-{lid}')
        if sku in seen:sku=f'{sku}-L{lid}'
        seen.add(sku)
        paths=[]
        for s in images[lid]+re.split(r'[\n|]+',r[12]):
            if not s.strip():continue
            p=path_for(s.strip())
            key=Path(p).stem
            if p not in mapping and key in hash_paths:p=hash_paths[key]
            if p and p not in paths:paths.append(p)
        texts='\n'.join(x[0] for x in cache.execute('SELECT body FROM texts WHERE id=?',(lid,)))
        for s in re.findall(r'(?:href|src)\s*=\s*[\"\']([^\"\']+)',html.unescape(texts),re.I):
            if re.search(r'\.(?:pdf|jpe?g|png|gif|webp)(?:[?#]|$)',s,re.I):
                p=path_for(s)
                if p and p not in paths:paths.append(p)
        inferred=f'search/img/aleksnet_document/pdf_{lid}.pdf'
        if inferred in mapping and inferred not in paths:paths.append(inferred)
        ozon=re.search(r'https://(?:www\.)?ozon\.ru/product/[^\s\"<>\\]+',html.unescape(texts),re.I)
        if ozon:direct_ozon+=1
        assets=[];photos=[];pdfs=[]
        for p in paths:
            ext=Path(p).suffix.lower()
            if ext not in ('.jpg','.jpeg','.png','.webp','.gif','.pdf'):continue
            size=mapping.get(p,len(embedded[p]) if p in embedded else 0)
            if not size:missing.add(p)
            assets.append({'path':p,'size':size,'kind':'pdf' if ext=='.pdf' else 'image'})
            (pdfs if ext=='.pdf' else photos).append(p)
        stock=number(r[9]);price=number(r[6])
        description=plain(r[15]);short=plain(r[14] or r[16])
        # Preserve all source characteristics while providing a compact useful starter list.
        ps=specs[lid]
        if not ps:
            for label,regex,unit in [('Напряжение',r'(?<!\w)([-+]?\d+(?:[.,]\d+)?)\s*V\b','В'),('Ток',r'(?<!\w)([-+]?\d+(?:[.,]\d+)?)\s*A\b','А'),('Мощность',r'(?<!\w)(\d+(?:[.,]\d+)?)\s*W\b','Вт')]:
                m=re.search(regex,short,re.I)
                if m:ps.append({'label':label,'value':m[1],'unit':unit})
        if r[4]:ps.insert(0,{'label':'Производитель','value':plain(r[4]),'unit':''})
        if r[5]:ps.insert(0,{'label':'Корпус','value':plain(r[5]),'unit':''})
        row={'legacy_id':lid,'sku':sku,'source_sku':original_sku,'name':name,'category':plain(r[3] or r[17] or 'Прочие компоненты'),'brand':plain(r[4]),'package':plain(r[5]),'price_rub':'' if price is None else price,'price_wholesale':number(r[7]),'price_special':number(r[8]),'stock':int(stock or 0),'stock_note':r[9],'description':description,'short_description':short,'specs_json':json.dumps(ps,ensure_ascii=False),'images_json':json.dumps(photos,ensure_ascii=False),'pdfs_json':json.dumps(pdfs,ensure_ascii=False),'ozon_url':ozon[0] if ozon else '','assets_json':json.dumps(assets,ensure_ascii=False),'source_url':f'https://arduino-ic.ru/search/sell/info.php?id={lid}','new':'1' if 'Новинки' in r[38] else '0','featured':'1' if any(w in r[38] for w in ('Популярное','Хиты','Лидеры')) else '0'}
        rows.append(row)
    with (out/'catalog.csv').open('w',encoding='utf-8-sig',newline='') as f:
        w=csv.DictWriter(f,fieldnames=list(rows[0]),delimiter=';');w.writeheader();w.writerows(rows)
    # Embedded media is supplemental; it is not a copy of FULL.
    used={p for r in rows for p in json.loads(r['images_json']) if p in embedded}
    if used:
        with zipfile.ZipFile(out/'embedded-media.zip','w',zipfile.ZIP_DEFLATED) as z:
            for p in sorted(used):z.writestr(p,embedded[p])
    summary={'products':len(rows),'source_product_rows':source_rows,'duplicate_source_skus':sum(n-1 for n in counts.values() if n>1),'direct_ozon_links':direct_ozon,'unique_assets':len({x['path'] for r in rows for x in json.loads(r['assets_json'])}),'missing_from_map':sorted(missing),'embedded_media_files':len(used),'photos':sum(len(json.loads(r['images_json'])) for r in rows),'pdf_links':sum(len(json.loads(r['pdfs_json'])) for r in rows)}
    (out/'catalog-report.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2))
    (out/'source-field-notes.json').write_text(json.dumps(recovered,ensure_ascii=False,indent=2))
    print(json.dumps({k:v for k,v in summary.items() if k!='missing_from_map'},ensure_ascii=False))
    print('Missing paths:',len(missing))
    cache.close();(out/'source-texts.sqlite').unlink();z.close()

if __name__=='__main__':main()
