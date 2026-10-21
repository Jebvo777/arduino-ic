import fs from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
const modulePath=process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES;
const { chromium }=modulePath?await import(pathToFileURL(path.join(modulePath,'playwright/index.mjs'))):await import('playwright');
const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');
const rt=path.join(root,'.runtime');
process.env.PLAYWRIGHT_BROWSERS_PATH ||= path.join(rt,'browsers');
const checks=[];
function check(ok,label){if(!ok)throw new Error(label);checks.push(label);process.stdout.write('PASS UI: '+label+'\n');}
const chrome=path.join(rt,'chrome-root/opt/google/chrome/chrome');
const exists=await fs.access(chrome).then(()=>true).catch(()=>false);
const browser=await chromium.launch({headless:true,...(exists?{executablePath:chrome}:{}),args:['--no-sandbox','--disable-dev-shm-usage']});
const page=await browser.newPage({viewport:{width:1440,height:1100},deviceScaleFactor:1});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
const base='http://127.0.0.1:8095';
const wait=async()=>{await page.waitForFunction(()=>!document.querySelector('.mik-loading,.mik-empty[role=status]'),{timeout:30000});};
try{
  await page.goto(base+'/mik-preview/');await page.locator('.mik-carousel .mik-card').first().waitFor({timeout:30000});
  check(await page.locator('.mik-slider .mik-slide.is-active').count()===1,'slider starts with exactly one active banner');
  await page.locator('[data-slide-next]').click();check(await page.locator('.mik-slide.is-active h2').innerText()==='Доставка СДЭК по России','slider next arrow changes the banner');
  await page.locator('[data-slide-next]').click();check((await page.locator('.mik-slide.is-active h2').innerText()).includes('Ozon'),'third slider banner opens');
  await page.screenshot({path:path.join(rt,'desktop-home.png'),fullPage:true});
  const styles=await page.locator('.mik-slide.is-active h2').evaluate(e=>({font:getComputedStyle(e).fontFamily,color:getComputedStyle(e).color}));
  check(styles.font.includes('Manrope')&&styles.color==='rgb(36, 42, 51)','reference heading font and color applied');
  const input=page.getByPlaceholder('Поиск по маркировке, коду или названию');await input.fill('IRFP');await page.locator('.mik-search-results a[role=option]').first().waitFor();
  check((await page.locator('.mik-search-results').innerText()).includes('IRFP4227'),'AJAX header search shows a preview result');
  await input.press('Escape');
  await page.locator('.mik-carousel').first().locator('[data-mik-wish]').first().click();await page.waitForFunction(()=>document.querySelector('.mik-wishlist-icon .mik-badge')?.textContent==='1');
  await page.locator('.mik-wishlist-icon [data-popover]').click();check((await page.locator('.mik-wishlist-icon .mik-popover').innerText()).includes('Открыть избранное'),'wishlist icon opens a populated mini window');
  await page.keyboard.press('Escape');
  await page.locator('.mik-carousel').first().locator('[data-mik-add]').first().click();await page.waitForFunction(()=>document.querySelector('.mik-cart-icon .mik-badge')?.textContent==='1');
  await page.locator('.mik-cart-icon [data-popover]').click();check((await page.locator('.mik-cart-icon .mik-popover').innerText()).includes('Итого'),'cart icon shows mini cart and total');
  await page.goto(base+'/cart-mik/');await page.locator('[data-checkout]').waitFor();
  await page.locator('[data-checkout] [name=name]').fill('Тестовый Покупатель');await page.locator('[data-checkout] [name=email]').fill('browser@example.test');await page.locator('[data-checkout] [name=phone]').fill('+7 900 222-33-44');await page.locator('[data-checkout] [name=address]').fill('Москва, ПВЗ СДЭК');await page.locator('[data-checkout] [name=consent]').check();
  await page.getByRole('button',{name:'Отправить заказ менеджеру'}).click();await page.locator('.mik-order-success').waitFor({timeout:30000});check((await page.locator('.mik-order-success').innerText()).includes('Оплата не списана'),'guest checkout ends with order confirmation');
  await page.goto(base+'/shop/');await page.locator('.mik-categories').waitFor();check(await page.locator('.mik-catalog .mik-card').count()===0,'shop root shows categories without product listing');
  await page.goto(base+'/mik-category-preview/');await page.locator('.mik-results .mik-card').first().waitFor();await page.locator('[data-view=list]').click();await page.locator('.mik-results.is-list .mik-card-row').first().waitFor();check(await page.locator('.mik-results.is-list').count()===1,'catalog switches to list view');
  await page.locator('[data-filter-form] [name=min]').fill('40');await page.locator('[data-filter-form] [name=sort]').selectOption('price_asc');await page.locator('[data-filter-form] button[type=submit]').click();await page.waitForFunction(()=>document.querySelector('[data-result-count]')?.textContent?.includes('Найдено: 2'));check(true,'catalog filters and sorting update results');
  await page.screenshot({path:path.join(rt,'desktop-catalog.png'),fullPage:true});
  await page.goto(base+'/mik-product-preview/');await page.locator('.mik-product-layout').waitFor();check(await page.locator('.mik-gallery-main').count()===1&&await page.locator('.mik-buy-box').count()===1,'custom product page shows gallery and action panel');
  await page.screenshot({path:path.join(rt,'desktop-product.png'),fullPage:true});
  await page.goto(base+'/lk-mik/');await page.locator('[data-auth-form]').waitFor();await page.locator('[data-auth-form] [name=email]').fill('alice@example.test');await page.locator('[data-auth-form] [name=password]').fill('Integration-Password-2026');await page.locator('[data-auth-form] button[type=submit]').click();await page.locator('[data-profile]').waitFor({timeout:30000});check(await page.locator('[data-profile] [name="billing.inn"]').inputValue()==='7700000000','custom login opens saved legal profile');
  await page.screenshot({path:path.join(rt,'desktop-account.png'),fullPage:true});
  for(const url of ['/mik-preview/','/mik-category-preview/','/mik-product-preview/','/cart-mik/','/lk-mik/']){
    await page.setViewportSize({width:390,height:844});await page.goto(base+url);await wait();await page.locator('.mik-loading').waitFor({state:'detached',timeout:30000}).catch(()=>{});
    const size=await page.evaluate(()=>({w:innerWidth,scroll:document.documentElement.scrollWidth}));check(size.scroll<=size.w+1,'mobile has no horizontal overflow: '+url);
    if(url==='/mik-preview/')await page.screenshot({path:path.join(rt,'mobile-home.png'),fullPage:true});
    if(url==='/mik-category-preview/')await page.screenshot({path:path.join(rt,'mobile-catalog.png'),fullPage:true});
  }
  check(errors.length===0,'storefront has no uncaught JavaScript errors');
  await fs.writeFile(path.join(rt,'browser-results.json'),JSON.stringify({count:checks.length,passed:checks,errors},null,2));
  process.stdout.write('BROWSER CHECKS PASSED: '+checks.length+'\n');
}catch(e){await page.screenshot({path:path.join(rt,'browser-failure.png'),fullPage:true}).catch(()=>{});await fs.writeFile(path.join(rt,'browser-failure.html'),await page.content()).catch(()=>{});throw e;}finally{await browser.close();}
