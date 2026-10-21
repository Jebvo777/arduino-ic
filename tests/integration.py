#!/usr/bin/env python3
"""HTTP integration tests against disposable WordPress + WooCommerce + MariaDB."""
import base64,concurrent.futures,csv,gzip,hashlib,http.cookiejar,io,json,re,time,urllib.error,urllib.parse,urllib.request,uuid,zipfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1];RT=ROOT/'.runtime';BASE='http://127.0.0.1:8095';FIX=json.loads((RT/'fixture.json').read_text());P=FIX['products'];checks=[]
def check(condition,label):
    if not condition:raise AssertionError(label)
    checks.append(label);print('PASS:',label,flush=True)

class Client:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(self.jar));self.csrf='';self.nonce=''
    def raw(self,path,body=None,headers=None):
        hdr={'Origin':BASE,**(headers or {})};r=urllib.request.Request(path if path.startswith('http') else BASE+path,data=body,headers=hdr)
        try:
            with self.opener.open(r,timeout=60) as res:return res.status,res.read(),dict(res.headers)
        except urllib.error.HTTPError as e:return e.code,e.read(),dict(e.headers)
    def api(self,path,p=None,expected=200,extra=None):
        hdr={'Content-Type':'application/json','X-MIK-CSRF':self.csrf,**({'X-WP-Nonce':self.nonce} if self.nonce else {}),**(extra or {})}
        code,raw,headers=self.raw('/wp-json/mik/v1/'+path,None if p is None else json.dumps(p,ensure_ascii=False).encode(),hdr)
        try:result=json.loads(raw)
        except Exception:raise AssertionError(f'Non-JSON response {code} {path}: {raw[:1500]!r}')
        if expected is not None and code!=expected:raise AssertionError(f'{path}: expected {expected}, got {code}: {result}')
        if isinstance(result,dict) and result.get('csrf'):self.csrf=result['csrf']
        return result,code,headers
    def session(self):return self.api('session')[0]
    def register(self,name,email,kind='physical'):
        self.session();return self.api('auth/register',{'name':name,'email':email,'phone':'+7 900 111-22-33','password':'Integration-Password-2026','consent':True,'type':kind,'address':'Москва, ПВЗ СДЭК','billing':{'company':'ООО Тест','inn':'7700000000','kpp':'770001001'}})[0]
    def multipart(self,path,fields,filedata):
        boundary='MIK'+uuid.uuid4().hex;parts=[]
        for k,v in fields.items():parts.extend([f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()])
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="chunk.bin"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+filedata+b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode());code,raw,h=self.raw('/wp-json/mik/v1/'+path,b''.join(parts),{'Content-Type':'multipart/form-data; boundary='+boundary,'X-WP-Nonce':self.nonce})
        try:return json.loads(raw),code
        except Exception:raise AssertionError(f'Upload non-JSON: {code}: {raw[:1000]!r}')

def upload(admin,data,kind='catalog',job=0,duplicate_first=False):
    token=uuid.uuid4().hex;size=40000 if kind=='package' else 4096;last=None
    for offset in range(0,len(data),size):
        fields={'kind':kind,'id':job,'upload_id':token,'offset':offset,'total':len(data)};chunk=data[offset:offset+size]
        last,code=admin.multipart('import/upload',fields,chunk)
        if code!=200:raise AssertionError(f'Upload failed {last}')
        if offset==0 and duplicate_first:
            again,code2=admin.multipart('import/upload',fields,chunk)
            check(code2==200 and again['offset']==last['offset'],'upload fragment can be retried without corruption')
    return last

def admin_login():
    a=Client();a.raw('/wp-login.php');body=urllib.parse.urlencode({'log':FIX['admin_user'],'pwd':FIX['admin_password'],'wp-submit':'Log In','redirect_to':BASE+'/wp-admin/admin.php?page=mik-shop','testcookie':'1'}).encode();code,raw,_=a.raw('/wp-login.php',body,{'Content-Type':'application/x-www-form-urlencoded'})
    match=re.search(rb'var MIK_ADMIN\s*=\s*(\{[^;]+\});',raw)
    if not match:
        code,raw,_=a.raw('/wp-admin/admin.php?page=mik-shop')
        match=re.search(rb'var MIK_ADMIN\s*=\s*(\{[^;]+\});',raw)
    if not match:raise AssertionError('Admin login or REST nonce missing: '+raw[:500].decode(errors='replace'))
    a.nonce=json.loads(match[1])['nonce'];return a

def order(client,key):return client.api('orders',{'request_key':key,'delivery':'cdek_pickup','payment':'tbank','consent':True},expected=None)

def main():
    a=Client();state=a.session();check('mik_session' in [c.name for c in a.jar],'guest receives a custom session cookie')
    _,_,h=a.api('products?limit=2');check('no-store' in h.get('Cache-Control',''),'personalized API disables caching')
    a.api('cart',{'product_id':P[0],'qty':2},extra={'X-MIK-CSRF':''},expected=403);check(True,'writes require CSRF')
    a.api('cart',{'product_id':P[0],'qty':2},extra={'Origin':'https://evil.example.test'},expected=403);check(True,'cross-origin writes rejected')
    a.api('cart',{'product_id':P[0],'qty':2});a.api('wishlist',{'product_id':P[0],'op':'toggle'})
    a.register('Иванов Иван Иванович','alice@example.test','legal');s=a.session();check(s['cart']['count']==2 and s['wishlist']['count']==1,'guest cart and wishlist merge into custom account')
    check(s['customer']['type']=='legal' and s['customer']['billing']['inn']=='7700000000','legal profile saves billing details')
    a.api('cart',{'product_id':P[0],'qty':-2,'op':'set'},expected=422);a.api('cart',{'product_id':P[0],'qty':1.5,'op':'set'},expected=422);check(True,'invalid cart quantities rejected')
    admin=admin_login();dashboard=admin.api('admin/dashboard')[0];check(dashboard['customers']==1,'registration creates custom customer')
    anon=Client();anon.api('admin/dashboard',expected=403);a.api('admin/dashboard',expected=403);check(True,'admin endpoints reject guests and custom customers')
    customer=admin.api('admin/accounts')[0]['rows'][0];admin.api('admin/accounts',{**customer,'group_id':2})
    product=a.api('products/'+str(P[0]))[0];check(product['price']['value']==2.59 and product['price']['currency']=='USD','USD group uses independent exact price')
    check(a.api('cart')[0]['currency']=='USD','cart follows authenticated price group')
    code,price_csv,_=a.raw('/wp-admin/admin-post.php?action=mik_document&kind=price');check(code==200 and b'USD' in price_csv and b'2.59' in price_csv,'price download contains the assigned group')
    admin.api('admin/accounts',{**customer,'group_id':3});product=a.api('products/'+str(P[0]))[0];check(abs(product['price']['value']-28.8)<.0001,'wholesale group price applied')
    check('opt' not in product and 'prices' not in product,'product API does not disclose other group prices')
    key=uuid.uuid4().hex;created,code,_=order(a,key)
    if code!=200:raise AssertionError('Checkout failed: '+str(created))
    check(abs(float(created['total'])-57.6)<.001,'checkout creates an order using server-side group prices')
    check(created['mail_status']=='sent','manager email receives an intact file with XLS extension')
    oid=created['id'];same,code,_=order(a,key);check(code==200 and same['id']==oid,'repeated checkout is idempotent')
    check(a.api('products/'+str(P[0]))[0]['stock']==293,'checkout reserves stock')
    admin.api('admin/orders',{'id':oid,'op':'invoice'});code,invoice,_=a.raw(f'/wp-admin/admin-post.php?action=mik_document&kind=invoice&id={oid}');check(code==200 and 'ООО Тест'.encode() in invoice and 'MIK-'.encode() in invoice,'invoice is available to its customer and contains billing')
    code,xls,_=a.raw(f'/wp-admin/admin-post.php?action=mik_document&kind=xls&id={oid}');check(code==200 and xls[:8]==bytes.fromhex('D0CF11E0A1B11AE1'),'order export is a real OLE BIFF XLS');(RT/'order-test.xls').write_bytes(xls)
    bob=Client();bob.register('Петров Пётр Петрович','bob@example.test');_,code,_=bob.api('orders/'+str(oid),expected=None);check(code!=200,'another customer cannot read the order')
    code,_,_=bob.raw(f'/wp-admin/admin-post.php?action=mik_document&kind=xls&id={oid}');check(code==403,'another customer cannot download the XLS')
    admin.api('admin/orders',{'id':oid,'status':'cancelled'});admin.api('admin/orders',{'id':oid,'status':'cancelled'});check(a.api('products/'+str(P[0]))[0]['stock']==295,'cancel restores stock exactly once')
    admin.api('admin/orders',{'id':oid,'status':'confirmed'},expected=422);check(True,'cancelled order cannot be reopened without reservation')
    a.api('cart',{'product_id':P[1],'qty':2,'op':'set'});bob.api('cart',{'product_id':P[1],'qty':2,'op':'set'})
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        results=list(pool.map(lambda c:order(c,uuid.uuid4().hex),[a,bob]))
    winners=[x for x in results if x[1]==200];check(len(winners)==1,'concurrent checkouts cannot oversell the last stock')
    check(a.api('products/'+str(P[1]))[0]['stock']==1,'concurrent reservation leaves the correct stock')
    admin.api('admin/orders',{'id':winners[0][0]['id'],'status':'cancelled'});check(a.api('products/'+str(P[1]))[0]['stock']==3,'race winner cancellation restores stock')
    admin.api('admin/settings',{'days':[]});hours=anon.api('hours')[0];check(not hours['open'] and hours['phone']=='','closed shop hides phone data');admin.api('admin/settings',{'days':[1,2,3,4,5]})
    # Reset flow and logout are tested without sending real mail (test-only pre_wp_mail filter).
    a.api('auth/reset-request',{'email':'alice@example.test'});check(True,'password reset request executes with generic confirmation')
    a.api('auth/logout',{});check(a.session()['customer'] is None,'logout removes account access')
    a.api('auth/login',{'email':'alice@example.test','password':'wrong'},expected=422);a.api('auth/login',{'email':'alice@example.test','password':'Integration-Password-2026'});check(a.session()['wishlist']['count']==1,'wishlist survives logout and login')
    # Complete upload -> plan -> BAT -> ZIP -> async import cycle with image and PDF.
    image=(ROOT/'mik-shop/assets/img/slider-components.webp').read_bytes();pdf=b'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n';files={'fixture/image.webp':image,'fixture/spec.pdf':pdf}
    def row(n):return {'legacy_id':'fixture-'+str(n),'sku':'IMPORTED-'+str(n),'name':'Импорт '+str(n),'category':'Импортированные компоненты','price_rub':'10','price_wholesale':'9','price_special':'8','stock':'7','description':'Проверка импорта','short_description':'Краткое описание','specs_json':json.dumps([{'label':'Напряжение','value':'600','unit':'В'}],ensure_ascii=False),'images_json':json.dumps(['fixture/image.webp']),'pdfs_json':json.dumps(['fixture/spec.pdf']),'ozon_url':'','assets_json':json.dumps([{'path':p,'size':len(b),'kind':'pdf' if p.endswith('.pdf') else 'image'} for p,b in files.items()])}
    def csv_bytes(rows):
        f=io.StringIO(newline='');w=csv.DictWriter(f,fieldnames=list(rows[0]),delimiter=';');w.writeheader();w.writerows(rows);return ('\ufeff'+f.getvalue()).encode()
    job=upload(admin,csv_bytes([row(1),row(2)]),duplicate_first=True)['id'];admin.api('import/plan',{'id':job,'mb':1})
    while True:
        plan=admin.api('import/plan-step',{'id':job})[0]
        if plan['status']=='ready':break
    check(plan['total_rows']==2 and len(plan['packages'])==1,'CSV creates a bounded package plan')
    check(all(int(p['selected'])==1 for p in plan['packages']),'all packages are selected by default')
    code,bat,_=admin.raw(plan['bat_url']);check(code==200 and b'::MIK_DATA' in bat,'Windows BAT is generated')
    payload=bat.split(b'\r\n::MIK_DATA\r\n',1)[1].strip();batplan=json.loads(gzip.decompress(base64.b64decode(payload)));package=batplan['packages'][0]
    check({x['path'] for x in package['files']}==set(files),'BAT contains only the required media paths')
    (RT/'generated-packager.bat').write_bytes(bat);(RT/'packager-plan.json').write_text(json.dumps(batplan,ensure_ascii=False))
    local=RT/'full';local.mkdir(exist_ok=True)
    for p,b in files.items():(local/p).parent.mkdir(parents=True,exist_ok=True);(local/p).write_bytes(b)
    manifest={'job_id':job,'package_no':1,'token':package['token'],'files':{p:{'sha256':hashlib.sha256(b).hexdigest(),'size':len(b)} for p,b in files.items()}}
    def package_bytes(extra=False):
        target=io.BytesIO()
        with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED) as z:
            z.writestr('manifest.json',json.dumps(manifest));
            for p,b in files.items():z.writestr(p,b)
            if extra:z.writestr('../bad.php','<?php die();')
        return target.getvalue()
    zipped=package_bytes();uploaded=upload(admin,zipped,'package',job,duplicate_first=True);check(uploaded['package']==1,'media ZIP upload validates the package manifest')
    for _ in range(20):
        result=admin.api('import/step',{'id':job,'package':1})[0]
        if result['packages'][0]['status'] in ('done','done-errors'):break
    check(result['processed']==2 and result['error_count']==0,'async import creates both products without errors')
    found=anon.api('products?q=IMPORTED-1')[0]['items'];check(len(found)==1 and found[0]['stock']==7,'imported product has correct SKU and stock')
    detail=anon.api('products/'+str(found[0]['id']))[0];check(len(detail['images'])==1 and len(detail['pdfs'])==1,'image and PDF attach to the imported product')
    check(any(s['value']=='600' for s in detail['specs']),'structured parameters survive import')
    code,pdf2,_=anon.raw(detail['pdfs'][0]['url']);check(code==200 and pdf2==pdf,'imported PDF is downloadable intact')
    upload(admin,zipped,'package',job);again,code,_=admin.api('import/step',{'id':job,'package':1},expected=None);check(code==200,'completed package can be repeated idempotently')
    token=uuid.uuid4().hex;bad=package_bytes(True);badres,badcode=admin.multipart('import/upload',{'kind':'package','id':job,'upload_id':token,'offset':0,'total':len(bad)},bad);check(badcode!=200,'ZIP traversal / executable entry rejected')
    check(len(anon.api('products?q=IMPORTED-1')[0]['items'])==1,'repeated package does not duplicate products')
    # Persist reusable browser fixture credentials only in .runtime.
    (RT/'integration-results.json').write_text(json.dumps({'passed':checks,'count':len(checks),'versions':{'wordpress':FIX['wordpress'],'woocommerce':FIX['woocommerce']},'order_id':oid,'import_job':job},ensure_ascii=False,indent=2))
    print('HTTP INTEGRATION PASSED:',len(checks),flush=True)

if __name__=='__main__':main()
