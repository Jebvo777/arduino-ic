/* MIK Shop — scoped, dependency-free storefront. */
(() => {
  'use strict';
  if (!window.MIK) return;
  const cfg = window.MIK, roots = new Set();
  let state = null, statePromise;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const safeUrl = s => { try {const u=new URL(String(s||''), location.origin);return ['http:','https:'].includes(u.protocol)?u.href:'#';} catch{return '#';} };
  const money = (v, c = 'RUB') => new Intl.NumberFormat('ru-RU', {style:'currency',currency:c,maximumFractionDigits:2}).format(Number(v || 0));
  const price = p => p.price ? money(p.price.value, p.price.currency) : 'Цена по запросу';
  const icons = {
    cart:'<path d="M3 3h2l3 12h11l3-9H6M8 20h.01M18 20h.01"/>',
    heart:'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
    user:'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    search:'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
    menu:'<path d="M3 6h18M3 12h18M3 18h18"/>',
    left:'<path d="m15 5-7 7 7 7"/>',right:'<path d="m9 5 7 7-7 7"/>',
    close:'<path d="m5 5 14 14M5 19 19 5"/>',grid:'<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
    list:'<path d="M8 5h13M8 12h13M8 19h13M3 5h.01M3 12h.01M3 19h.01"/>',phone:'<path d="M5 3h4l1 5-3 2a15 15 0 0 0 7 7l2-3 5 1v4c0 2-3 3-6 2A23 23 0 0 1 3 9C2 6 3 3 5 3Z"/>'
  };
  const icon = (name, size = 22) => `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name] || ''}</svg>`;
  const docUrl = (kind,id) => cfg.document + '&kind=' + encodeURIComponent(kind) + (id ? '&id='+Number(id) : '');
  const loading = '<div class="mik-empty" role="status">Загрузка…</div>';
  const empty = (text, link = true) => `<div class="mik-empty"><p>${esc(text)}</p>${link?`<a class="mik-btn" href="${safeUrl(cfg.urls.shop)}">Перейти в каталог</a>`:''}</div>`;
  async function api(path, data, signal) {
    const method = data === undefined ? 'GET' : 'POST';
    const res = await fetch(cfg.api + path, {method, credentials:'same-origin',cache:'no-store',signal,headers:{'Content-Type':'application/json',...(state?.csrf?{'X-MIK-CSRF':state.csrf}:{})},...(method==='POST'?{body:JSON.stringify(data)}:{})});
    let body;try{body=await res.json();}catch{throw new Error('Сервер вернул некорректный ответ. Обновите страницу.');}
    if (!res.ok) throw new Error(body.message || 'Не удалось выполнить запрос.');
    return body;
  }
  async function refresh() { state = await api('session'); updateWidgets(); return state; }
  function updateWidgets() {
    for (const r of roots) {
      if (!r.isConnected) { roots.delete(r); continue; }
      const type=r.dataset.mik;
      if (['cart-icon','wishlist-icon','account-icon','store-status'].includes(type)) renderWidget(r,type);
    }
    $$('[data-mik-wish]').forEach(b=>{const active=state?.wishlist.items.some(p=>p.id===Number(b.dataset.mikWish));b.classList.toggle('is-active',!!active);b.setAttribute('aria-pressed',String(!!active));});
    document.dispatchEvent(new CustomEvent('mik:state', {detail:state}));
  }
  function toast(message, error = false) {
    let box=$('#mik-toast');if(!box){box=document.createElement('div');box.id='mik-toast';box.className='mik mik-toast';box.setAttribute('role','status');document.body.append(box);}
    box.textContent=message;box.classList.toggle('is-error',error);box.hidden=false;clearTimeout(box._timer);box._timer=setTimeout(()=>{box.hidden=true;},5000);
  }
  function error(root,e) {root.innerHTML=`<div class="mik-error" role="alert">${esc(e.message)}</div>`;}
  function specs(p) {return `<dl class="mik-specs">${(p.specs||[]).map(s=>`<div><dt>${esc(s.label)}</dt><i></i><dd>${esc(s.value)}${s.unit?' '+esc(s.unit):''}</dd></div>`).join('')}</dl>`;}
  function card(p, list=false) {
    const wished=state?.wishlist.items.some(x=>x.id===p.id);
    return `<article class="mik-card ${list?'mik-card-row':''}"><a class="mik-card-photo" href="${safeUrl(p.url)}"><img src="${safeUrl(p.image)}" alt="${esc(p.name)}" loading="lazy" width="480" height="360"></a><div class="mik-card-info"><span class="mik-stock ${p.stock?'':'is-zero'}">${p.stock?'В наличии '+esc(p.stock)+' шт.':'Нет в наличии'}</span><p class="mik-code">Код ${esc(p.sku)} · ${esc(p.category)}</p><a class="mik-card-title" href="${safeUrl(p.url)}">${esc(p.name)}</a>${specs(p)}</div><div class="mik-card-bottom"><strong class="mik-price">${esc(price(p))}</strong><div class="mik-actions"><button class="mik-icon-btn mik-wish ${wished?'is-active':''}" aria-label="В избранное" aria-pressed="${!!wished}" data-mik-wish="${p.id}">${icon('heart',19)}</button><button class="mik-icon-btn mik-add" aria-label="Добавить в корзину" data-mik-add="${p.id}" ${!p.stock?'disabled':''}>${icon('cart',21)}</button><a class="mik-ozon" href="${safeUrl(p.ozon)}" target="_blank" rel="noopener">OZON</a></div></div></article>`;
  }
  function formData(form) {
    const result={};for(const [k,v] of new FormData(form)){const parts=k.split('.');if(parts.length===2){result[parts[0]]||={};result[parts[0]][parts[1]]=v;}else{result[k]=v;}}
    return result;
  }
  const field = (name,label,value='',type='text',required=false) => `<label class="mik-field"><span>${esc(label)}${required?' *':''}</span><input name="${esc(name)}" type="${type}" value="${esc(value)}" ${required?'required':''} ${type==='password'?'minlength="12" autocomplete="new-password"':''}></label>`;
  const billingFields = (b={}) => `<div class="mik-billing mik-form-grid">${[['company','Организация'],['inn','ИНН'],['kpp','КПП'],['ogrn','ОГРН / ОГРНИП'],['legal_address','Юридический адрес'],['bank','Банк'],['bik','БИК'],['account','Расчётный счёт'],['correspondent','Корреспондентский счёт']].map(([k,l])=>field('billing.'+k,l,b[k]||'')).join('')}</div>`;
  function widgetOpen(r, open) {if(open)closePanels(r);const panel=$('.mik-popover',r),button=$('[data-popover]',r);if(panel){panel.hidden=!open;button?.setAttribute('aria-expanded',String(open));}}
  function renderWidget(r,type) {
    if(!state)return;
    if(type==='store-status') {const h=state.hours;r.innerHTML=`<div class="mik-store" aria-live="polite"><strong class="mik-store-status"><i class="mik-store-indicator ${h.open?'is-open':''}"></i>${esc(h.status)}</strong><span class="mik-store-hours">${esc(h.hours)}</span>${h.open?`<a class="mik-store-phone" href="tel:${esc(h.phone_raw)}">${esc(h.phone)}</a>`:''}</div>`;return;}
    const kind=type.split('-')[0],label=cfg.labels[kind]||kind,open=$('.mik-popover',r)?.hidden===false;
    let body='',count=0;
    if(kind==='account'){
      body=state.customer?`<strong>${esc(state.customer.name)}</strong><p class="mik-muted">${esc(state.group?.name)}</p><a class="mik-btn" href="${safeUrl(cfg.urls.account)}">Открыть кабинет</a><button class="mik-link" data-logout>Выйти</button>`:`<strong>Вход в личный кабинет</strong><form class="mik-mini-login">${field('email','Email','','email',true)}<label class="mik-field"><span>Пароль</span><input type="password" name="password" autocomplete="current-password" required></label><button class="mik-btn" type="submit">Войти</button></form><a href="${safeUrl(cfg.urls.account)}">Регистрация и восстановление пароля</a>`;
    } else {
      const list=state[kind];count=list.count;
      body=list.items.length?list.items.slice(0,3).map(p=>`<div class="mik-mini-item"><img src="${safeUrl(p.image)}" alt=""><div><a href="${safeUrl(p.url)}">${esc(p.name)}</a><span>${kind==='cart'?p.qty+' × ':''}${esc(price(p))}</span></div><button class="mik-icon-btn" aria-label="Удалить" data-mini-remove="${p.id}" data-kind="${kind}">${icon('close',16)}</button></div>`).join(''):`<p>${kind==='cart'?'Корзина пуста':'В избранном пока ничего нет'}</p>`;
      if(kind==='cart'&&list.items.length)body+=`<p class="mik-mini-total">Итого <strong>${esc(money(list.total,list.currency))}</strong></p>`;
      body+=`<a class="mik-btn" href="${safeUrl(cfg.urls[kind])}">Открыть ${kind==='cart'?'корзину':'избранное'}</a>`;
    }
    r.innerHTML=`<div class="mik-header-widget"><button class="mik-header-icon" data-popover aria-haspopup="dialog" aria-expanded="${!!open}" aria-label="${esc(label)}" title="${esc(label)}">${icon(kind==='account'?'user':kind==='wishlist'?'heart':'cart')}${kind!=='account'?`<b class="mik-badge" aria-label="${count}">${count>99?'99+':count}</b>`:state.customer?'<i class="mik-account-online" aria-label="Вы вошли"></i>':''}</button><div class="mik-popover" role="dialog" aria-label="${esc(label)}" ${!open?'hidden':''}>${body}</div></div>`;
  }
  async function search(r) {
    r.innerHTML=`<form class="mik-search-form" role="search"><input type="search" name="q" aria-label="Поиск товаров" aria-autocomplete="list" aria-expanded="false" placeholder="${esc(cfg.labels.search)}" autocomplete="off"><button type="submit" class="mik-search-submit" aria-label="Найти">${icon('search')}</button><div class="mik-search-results" role="listbox" hidden></div></form>`;
    const input=$('input',r),results=$('.mik-search-results',r);let timer,controller,serial=0;
    $('form',r).onsubmit=e=>{e.preventDefault();location.href=cfg.urls.shop+'?mik_q='+encodeURIComponent(input.value.trim());};
    input.addEventListener('focus',()=>closePanels(r));
    input.addEventListener('input',()=>{clearTimeout(timer);controller?.abort();const seq=++serial,q=input.value.trim();if(q.length<2){results.hidden=true;input.setAttribute('aria-expanded','false');return;}
      timer=setTimeout(async()=>{controller=new AbortController();try{const data=await api('products?q='+encodeURIComponent(q)+'&limit=6',undefined,controller.signal);if(seq!==serial)return;results.innerHTML=data.items.length?data.items.map(p=>`<a role="option" href="${safeUrl(p.url)}"><img src="${safeUrl(p.image)}" alt=""><span><strong>${esc(p.name)}</strong><small>Код ${esc(p.sku)} · ${esc(price(p))}</small></span></a>`).join('')+`<a class="mik-search-all" href="${safeUrl(cfg.urls.shop+'?mik_q='+encodeURIComponent(q))}">Все результаты (${data.total})</a>`:'<p>Ничего не найдено</p>';results.hidden=false;input.setAttribute('aria-expanded','true');}catch(e){if(e.name!=='AbortError'){results.innerHTML=`<p>${esc(e.message)}</p>`;results.hidden=false;}}},250);
    });
    input.addEventListener('keydown',e=>{if(e.key==='Escape'){results.hidden=true;}if(e.key==='ArrowDown'){e.preventDefault();$('a',results)?.focus();}});
  }
  let categoriesPromise;
  function categoriesData() {
    if(!categoriesPromise)categoriesPromise=api('categories').catch(e=>{categoriesPromise=null;throw e;});
    return categoriesPromise;
  }
  function categoryItems(cats,o={}){
    const ids=Array.isArray(o.ids)?o.ids:String(o.ids||'').split(',').filter(Boolean),byId=new Map(cats.map(c=>[Number(c.id),c]));
    let items=ids.length?ids.map(id=>byId.get(Number(id))).filter(Boolean):cats.filter(c=>Number(c.parent)===Number(o.parent||0));
    if(!items.length&&!Number(o.parent)&&ids.length)items=cats.filter(c=>!Number(c.parent));
    const limit=Number(o.limit);return limit>0?items.slice(0,Math.min(48,limit)):items;
  }
  function categoryCardsHTML(cats,o={}){
    const items=categoryItems(cats,o),children=Math.max(0,Math.min(6,Number(o.children??3)));
    return `<div class="mik-categories mik-category-cards-grid">${items.map(c=>`<article class="mik-category-tile ${['semiconductors','protection'].includes(c.key)?'is-dark':''} ${c.image?'has-image':''}"><a class="mik-category-title" href="${safeUrl(c.url)}">${esc(c.name)}</a><p class="mik-category-children">${cats.filter(x=>Number(x.parent)===Number(c.id)).slice(0,children).map(x=>`<a href="${safeUrl(x.url)}">${esc(x.name)}</a>`).join(' · ')}</p>${c.image?`<a class="mik-category-photo" href="${safeUrl(c.url)}" tabindex="-1" aria-hidden="true"><img src="${safeUrl(c.image)}" alt="" loading="lazy" width="230" height="180"></a>`:'<span class="mik-category-picture-empty" aria-hidden="true">'+icon('grid',58)+'</span>'}<a class="mik-btn mik-btn-secondary" href="${safeUrl(c.url)}" aria-label="Открыть категорию ${esc(c.name)}">Подробнее ${icon('right',15)}</a></article>`).join('')||empty('Категории появятся после настройки каталога.',false)}</div>`;
  }
  async function categoryCards(r,o){const cats=await categoriesData();r.innerHTML=categoryCardsHTML(cats,{limit:8,...o});}
  async function headerCategories(r,o){const cats=await categoriesData(),items=categoryItems(cats,{limit:8,...o});r.innerHTML=`<nav class="mik-header-category-nav" aria-label="Основные категории товаров">${items.map(c=>`<a href="${safeUrl(c.url)}">${esc(c.name)}</a>`).join('')}<a class="mik-header-category-all" href="${safeUrl(cfg.urls.shop)}">Весь каталог ${icon('right',14)}</a></nav>`;}
  function closePanels(except) {
    $$('.mik-popover,.mik-megamenu,.mik-search-results').forEach(p=>{if(!except?.contains(p)){p.hidden=true;const r=p.closest('[data-mik]');if(r)$('[aria-expanded]',r)?.setAttribute('aria-expanded','false');}});
  }
  async function categoryBrowser(r,o={}) {
    const cats=await categoriesData(),byId=new Map(cats.map(c=>[Number(c.id),c]));
    const childIds=new Set(cats.map(c=>Number(c.parent))),limit=o.footer?Math.max(4,Math.min(12,Number(o.limit)||8)):20;
    let parent=0,query='',shown=limit;
    r.innerHTML=`<div class="mik-category-finder"><label class="mik-category-search">${icon('search',17)}<input type="search" placeholder="Найти категорию" aria-label="Поиск по категориям" autocomplete="off"></label><div class="mik-category-trail"></div><div class="mik-category-rows"></div><button class="mik-category-more" hidden type="button">Показать ещё</button><a class="mik-category-all" href="${safeUrl(cfg.urls.shop)}">Весь каталог ${icon('right',15)}</a></div>`;
    const rows=$('.mik-category-rows',r),trail=$('.mik-category-trail',r),more=$('.mik-category-more',r),input=$('input',r);
    function render(){
      let items;
      if(query){const q=query.toLocaleLowerCase('ru');items=cats.filter(c=>c.name.toLocaleLowerCase('ru').includes(q));}
      else if(o.footer&&o.ids?.length){items=o.ids.map(id=>byId.get(Number(id))).filter(Boolean);}
      else{items=cats.filter(c=>Number(c.parent)===parent);if(!parent&&!items.length)items=cats;}
      const current=byId.get(parent);
      trail.innerHTML=current&&!query?`<button type="button" data-category-parent="${Number(current.parent)}">${icon('left',15)} Назад</button><span>${esc(current.name)}</span>`:'';
      rows.innerHTML=items.slice(0,shown).map(c=>`<div class="mik-category-row"><a href="${safeUrl(c.url)}"><span>${esc(c.name)}</span>${query&&Number(c.parent)&&byId.has(Number(c.parent))?`<small>${esc(byId.get(Number(c.parent)).name)}</small>`:''}</a>${!o.footer&&childIds.has(Number(c.id))?`<button type="button" data-category-parent="${Number(c.id)}" aria-label="Подкатегории: ${esc(c.name)}">${icon('right',17)}</button>`:''}</div>`).join('')||'<p class="mik-category-none">Категории не найдены.</p>';
      more.hidden=o.footer&&!query||shown>=items.length;
      more.textContent=`Показать ещё (${Math.min(limit,items.length-shown)})`;
    }
    input.addEventListener('input',()=>{query=input.value.trim();shown=limit;render();});
    r.addEventListener('click',e=>{const b=e.target.closest('[data-category-parent]');if(!b)return;parent=Number(b.dataset.categoryParent);query='';input.value='';shown=limit;render();});
    more.onclick=()=>{shown+=limit;render();};render();
  }
  async function catalogButton(r) {
    r.innerHTML=`<button class="mik-btn mik-catalog-toggle" type="button" aria-expanded="false" data-catalog-toggle>${icon('grid',19)}<span>${esc(cfg.labels.catalog)}</span><svg class="mik-catalog-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><div class="mik-megamenu" hidden><div class="mik-megamenu-head"><strong>Каталог компонентов</strong><button type="button" class="mik-icon-btn" data-catalog-close aria-label="Закрыть каталог">${icon('close',18)}</button></div><div data-category-dropdown></div></div>`;
    const b=$('[data-catalog-toggle]',r),panel=$('.mik-megamenu',r);let ready=false;
    const set=open=>{if(open)closePanels(r);panel.hidden=!open;b.setAttribute('aria-expanded',String(open));};
    b.onclick=async()=>{const open=panel.hidden;set(open);if(open&&!ready){const target=$('[data-category-dropdown]',r);target.innerHTML=loading;try{await categoryBrowser(target);ready=true;}catch(e){error(target,e);}}};
    $('[data-catalog-close]',r).onclick=()=>{set(false);b.focus();};
  }
  function mobileMenu(r) {
    const trigger=$('[data-mobile-open]',r),template=$('[data-mobile-template]',r);if(!trigger||!template)return;
    let dialog,overflow;
    trigger.onclick=()=>{
      if(!dialog){dialog=template.content.firstElementChild.cloneNode(true);document.body.append(dialog);mountAll(dialog);
        $('[data-mobile-close]',dialog).onclick=()=>dialog.close();
        dialog.addEventListener('close',()=>{document.documentElement.style.overflow=overflow||'';trigger.setAttribute('aria-expanded','false');trigger.focus();});
        dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
      }
      closePanels();overflow=document.documentElement.style.overflow;document.documentElement.style.overflow='hidden';trigger.setAttribute('aria-expanded','true');dialog.showModal();$('[data-mobile-close]',dialog).focus();
    };
  }
  function slider(r,o) {
    const slides=o.slides||[],photos=(o.photos||[]).filter(Boolean);if(!slides.length){r.innerHTML='';return;}
    r.innerHTML=`<section class="mik-slider" aria-roledescription="карусель" aria-label="Предложения магазина">${slides.map((s,i)=>`<article class="mik-slide ${i===0?'is-active':''}" ${i?'hidden':''} style="--mik-slide-accent:${esc(s.color)}"><div class="mik-slide-photo">${photos.length?photos.map((photo,j)=>`<img data-hero-photo="${j}" class="${!j?'is-active':''}" src="${safeUrl(photo)}" alt="" ${i||j?'loading="lazy"':''}>`).join(''):`<img class="is-active" src="${safeUrl(s.image)}" alt="" ${i?'loading="lazy"':''}>`}</div><div class="mik-slide-copy"><h2>${esc(s.title)}</h2><p>${esc(s.text)}</p><div class="mik-slide-buttons"><a class="mik-btn mik-slide-primary" href="${safeUrl(s.url)}">${esc(s.button)}</a>${s.secondary?`<a class="mik-btn mik-btn-outline" href="${safeUrl(s.secondary_url)}">${esc(s.secondary)}</a>`:''}</div></div></article>`).join('')}<div class="mik-slide-controls"><button data-slide-prev aria-label="Предыдущий слайд">${icon('left')}</button><div>${slides.map((_,i)=>`<button class="mik-dot ${!i?'is-active':''}" data-slide="${i}" aria-label="Слайд ${i+1}" aria-current="${!i}"></button>`).join('')}</div><button data-slide-next aria-label="Следующий слайд">${icon('right')}</button></div></section>`;
    let index=0,photoIndex=0,timer;const show=n=>{if(photos.length){photoIndex=(photoIndex+1)%photos.length; $$('[data-hero-photo]',r).forEach(img=>img.classList.toggle('is-active',Number(img.dataset.heroPhoto)===photoIndex));}index=(n+slides.length)%slides.length;$$('.mik-slide',r).forEach((s,i)=>{s.hidden=i!==index;s.classList.toggle('is-active',i===index);});$$('[data-slide]',r).forEach((b,i)=>{b.classList.toggle('is-active',i===index);b.setAttribute('aria-current',String(i===index));});};
    $('[data-slide-prev]',r).onclick=()=>show(index-1);$('[data-slide-next]',r).onclick=()=>show(index+1);$$('[data-slide]',r).forEach(b=>b.onclick=()=>show(Number(b.dataset.slide)));
    const stop=()=>clearInterval(timer),start=()=>{stop();if(!matchMedia('(prefers-reduced-motion: reduce)').matches)timer=setInterval(()=>{if(r.isConnected)show(index+1);else stop();},6500);};r.addEventListener('mouseenter',stop);r.addEventListener('mouseleave',start);r.addEventListener('focusin',stop);r.addEventListener('focusout',e=>{if(!r.contains(e.relatedTarget))start();});start();
  }
  async function carousel(r,o) {
    const params=new URLSearchParams({limit:Number(o.limit)||12,sort:o.source==='new'?'new':'new',source:o.source==='featured'?'featured':''});if(o.category)params.set('cat',o.category);
    const data=await api('products?'+params);r.innerHTML=`<div class="mik-section-head"><h2>${esc(o.title||cfg.carousel.title)}</h2><div class="mik-carousel-controls"><button class="mik-icon-btn" data-scroll="-1" aria-label="Предыдущие товары">${icon('left')}</button><button class="mik-icon-btn" data-scroll="1" aria-label="Следующие товары">${icon('right')}</button></div></div><div class="mik-carousel-track" style="--mik-columns:${Math.max(1,Math.min(6,Number(o.columns)||4))}">${data.items.map(p=>card(p)).join('')||empty('В этой подборке пока нет товаров.')}</div>`;
    $$('[data-scroll]',r).forEach(b=>b.onclick=()=>{const track=$('.mik-carousel-track',r);track.scrollBy({left:Number(b.dataset.scroll)*track.clientWidth,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});});
  }
  async function catalog(r,o) {
    const qs=new URLSearchParams(location.search);const categories=await categoriesData();let filters=[];
    const p={cat:qs.get('mik_cat')||o.category||'',q:qs.get('mik_q')||'',page:Number(qs.get('mik_page'))||1,limit:o.limit||24,sort:qs.get('mik_sort')||'new',min:qs.get('mik_min')||'',max:qs.get('mik_max')||'',stock:qs.get('mik_stock')||'',param:qs.get('mik_param')||'',value:qs.get('mik_value')||''};let view=o.view||'grid';
    const showCats=!p.cat&&!p.q&&!p.stock&&!p.min&&!p.max;
    if(showCats){r.innerHTML=`<h1>Каталог электронных компонентов</h1>${categoryCardsHTML(categories,{limit:0,children:3})}`;return;}
    try{filters=await api('filters');}catch{}
    const cat=categories.find(c=>String(c.id)===String(p.cat)||c.slug===p.cat);
    r.innerHTML=`<div class="mik-section-head"><div><a class="mik-back" href="${safeUrl(cfg.urls.shop)}">Каталог</a><h1>${esc(p.q?'Поиск: '+p.q:cat?.name||'Товары')}</h1><span data-result-count></span></div><div class="mik-view-switch"><button class="mik-icon-btn ${view==='grid'?'is-active':''}" data-view="grid" aria-label="Показать карточками">${icon('grid')}</button><button class="mik-icon-btn ${view==='list'?'is-active':''}" data-view="list" aria-label="Показать списком">${icon('list')}</button></div></div><div class="mik-catalog-layout"><aside class="mik-filters"><form data-filter-form><h3>Фильтры</h3><label class="mik-field"><span>Категория</span><select name="cat"><option value="">Все категории</option>${categories.map(c=>`<option value="${c.id}" ${String(c.id)===String(cat?.id)?'selected':''}>${esc((c.parent?'— ':'')+c.name)}</option>`).join('')}</select></label><label class="mik-field"><span>Поиск</span><input name="q" value="${esc(p.q)}"></label><div class="mik-price-filter">${field('min','Цена от',p.min,'number')}${field('max','Цена до',p.max,'number')}</div><label class="mik-check"><input type="checkbox" name="stock" value="1" ${p.stock?'checked':''}> Только в наличии</label><label class="mik-field"><span>Параметр</span><select name="param"><option value="">Любой параметр</option>${filters.map(f=>`<option value="${esc(f.key)}" ${p.param===f.key?'selected':''}>${esc(f.label)}</option>`).join('')}</select></label><label class="mik-field"><span>Значение</span><select name="value"></select></label><label class="mik-field"><span>Сортировка</span><select name="sort">${[['new','Сначала новые'],['name','По названию'],['price_asc','Цена по возрастанию'],['price_desc','Цена по убыванию']].map(([v,l])=>`<option value="${v}" ${p.sort===v?'selected':''}>${l}</option>`).join('')}</select></label><button class="mik-btn" type="submit">Применить</button><button class="mik-link" type="button" data-reset-filter>Сбросить фильтры</button></form></aside><div><div class="mik-results" aria-live="polite"></div><nav class="mik-pagination" aria-label="Страницы каталога"></nav></div></div>`;
    const form=$('[data-filter-form]',r),result=$('.mik-results',r);let serial=0;
    const values=()=>{const f=filters.find(x=>x.key===form.elements.param.value);form.elements.value.innerHTML='<option value="">Любое значение</option>'+(f?.values||[]).map(v=>`<option value="${esc(v)}" ${p.value===v?'selected':''}>${esc(v+' '+f.unit)}</option>`).join('');};values();form.elements.param.onchange=values;
    async function load() {const seq=++serial;result.innerHTML=loading;try{const data=await api('products?'+new URLSearchParams(p));if(seq!==serial)return;result.className='mik-results '+(view==='list'?'is-list':'is-grid');result.innerHTML=data.items.map(x=>card(x,view==='list')).join('')||empty('Товары не найдены. Измените фильтры.',false);$('[data-result-count]',r).textContent=`Найдено: ${data.total}${data.group?' · '+data.group:''}`;
      const pages=[];for(let n=Math.max(1,p.page-2);n<=Math.min(data.pages,p.page+2);n++)pages.push(n);
      $('.mik-pagination',r).innerHTML=data.pages>1?`<button data-page="${p.page-1}" ${p.page<=1?'disabled':''}>Назад</button>${pages.map(n=>`<button data-page="${n}" class="${p.page===n?'is-active':''}" ${p.page===n?'aria-current="page"':''}>${n}</button>`).join('')}<button data-page="${p.page+1}" ${p.page>=data.pages?'disabled':''}>Далее</button>`:'';
      $$('[data-page]',r).forEach(b=>b.onclick=()=>{p.page=Number(b.dataset.page);remember();load();r.scrollIntoView({block:'start'});});}catch(e){error(result,e);}}
    function remember(){const u=new URL(location.href);for(const [k,v] of Object.entries(p)){if(k==='limit')continue;if(v!==''&&v!==0)u.searchParams.set('mik_'+k,v);else u.searchParams.delete('mik_'+k);}history.replaceState(null,'',u);}
    form.onsubmit=e=>{e.preventDefault();Object.assign(p,{...formData(form),stock:form.elements.stock.checked?'1':'',page:1});remember();load();};$('[data-reset-filter]',r).onclick=()=>{form.reset();Object.assign(p,{q:'',min:'',max:'',stock:'',param:'',value:'',sort:'new',page:1});for(const k of ['q','min','max'])form.elements[k].value='';form.elements.stock.checked=false;form.elements.param.value='';values();remember();load();};
    $$('[data-view]',r).forEach(b=>b.onclick=()=>{view=b.dataset.view;$$('[data-view]',r).forEach(x=>x.classList.toggle('is-active',x===b));load();});await load();
  }
  async function product(r,o) {
    if(!o.id){r.innerHTML=empty('Откройте карточку товара. В предпросмотре Elementor можно указать id товара в шорткоде.',false);return;}
    const p=await api('products/'+Number(o.id));r.innerHTML=`<p class="mik-breadcrumb"><a href="${safeUrl(cfg.urls.shop)}">Каталог</a> / ${esc(p.category)}</p><div class="mik-product-layout"><section class="mik-gallery"><img class="mik-gallery-main" src="${safeUrl(p.images[0]?.url||p.image)}" alt="${esc(p.name)}">${p.images.length>1?`<div class="mik-gallery-thumbs">${p.images.map(i=>`<button data-gallery="${safeUrl(i.url)}" aria-label="Посмотреть изображение"><img src="${safeUrl(i.url)}" alt="${esc(i.alt)}"></button>`).join('')}</div>`:''}</section><section class="mik-product-info"><p class="mik-code">Код ${esc(p.sku)} · ${esc(p.category)}</p><h1>${esc(p.name)}</h1>${specs(p)}<p>${esc(p.short_description)}</p></section><aside class="mik-buy-box"><span class="mik-stock">${p.stock?'В наличии '+p.stock+' шт.':'Нет в наличии'}</span><strong class="mik-price">${esc(price(p))}</strong><div class="mik-quantity"><button data-qty-step="-1" aria-label="Уменьшить количество">−</button><input type="number" min="1" max="${p.stock}" value="1" aria-label="Количество"><button data-qty-step="1" aria-label="Увеличить количество">+</button></div><button class="mik-btn" data-mik-add="${p.id}" data-use-qty ${!p.stock?'disabled':''}>${icon('cart',19)} В корзину</button><a class="mik-btn mik-ozon" href="${safeUrl(p.ozon)}" target="_blank" rel="noopener">Купить на Ozon</a><button class="mik-btn mik-btn-outline" data-mik-wish="${p.id}">${icon('heart',18)} В избранное</button><p class="mik-muted">СДЭК: пункт выдачи или курьер. Стоимость согласовывается менеджером.</p>${state.pickup?`<p class="mik-muted">Самовывоз: ${esc(state.pickup_address)}</p>`:''}</aside></div><section class="mik-product-description"><h2>Описание и документация</h2>${p.description||'<p>Описание товара уточняется.</p>'}${p.pdfs.length?`<div class="mik-pdf-list">${p.pdfs.map(x=>`<a class="mik-btn mik-btn-outline" href="${safeUrl(x.url)}" target="_blank" rel="noopener">PDF · ${esc(x.name)}</a>`).join('')}</div>`:''}</section>${p.related.length?`<h2>Похожие товары</h2><div class="mik-results is-grid">${p.related.map(x=>card(x)).join('')}</div>`:''}`;
    $$('[data-gallery]',r).forEach(b=>b.onclick=()=>{$('.mik-gallery-main',r).src=b.dataset.gallery;});$$('[data-qty-step]',r).forEach(b=>b.onclick=()=>{const input=$('.mik-quantity input',r);input.value=String(Math.min(p.stock,Math.max(1,Number(input.value)+Number(b.dataset.qtyStep))));});updateWidgets();
  }
  async function wishlist(r) {const list=await api('wishlist');r.innerHTML=`<h1>${esc(cfg.labels.wishlist)}</h1>${list.items.length?`<div class="mik-results is-grid">${list.items.map(p=>card(p)).join('')}</div>`:empty('В избранном пока ничего нет.')}`;}
  async function cart(r) {
    const data=await api('cart');const c=state.customer||{};const draft=r._draft||{};
    r.innerHTML=`<h1>${esc(cfg.labels.cart)}</h1>${data.items.length?`<div class="mik-cart-layout"><div class="mik-cart-items">${data.items.map(p=>`<article class="mik-cart-item"><a href="${safeUrl(p.url)}"><img src="${safeUrl(p.image)}" alt="${esc(p.name)}"></a><div><a class="mik-card-title" href="${safeUrl(p.url)}">${esc(p.name)}</a><p class="mik-code">Код ${esc(p.sku)}</p><p>${esc(price(p))}</p>${p.qty>p.stock?`<p class="mik-error">Доступно ${p.stock} шт.</p>`:''}<a class="mik-ozon mik-ozon-small" href="${safeUrl(p.ozon)}" target="_blank" rel="noopener">OZON</a></div><div class="mik-cart-item-end"><div class="mik-quantity"><button data-cart-qty="${p.id}" data-qty="${p.qty-1}" aria-label="Уменьшить">−</button><input type="number" min="0" max="${p.stock}" value="${p.qty}" data-cart-input="${p.id}" aria-label="Количество"><button data-cart-qty="${p.id}" data-qty="${p.qty+1}" aria-label="Увеличить">+</button></div><strong>${esc(p.line_total===null?'По запросу':money(p.line_total,data.currency))}</strong><button class="mik-link" data-cart-qty="${p.id}" data-qty="0">Удалить</button></div></article>`).join('')}</div><aside class="mik-checkout"><h2>Оформление заказа</h2><form data-checkout><div class="mik-form-grid">${field('name','Имя / ФИО',draft.name??c.name??'','text',true)}${field('email','Email',draft.email??c.email??'','email',true)}${field('phone','Телефон',draft.phone??c.phone??'','tel',true)}<label class="mik-field"><span>Доставка</span><select name="delivery"><option value="cdek_pickup">СДЭК — пункт выдачи (заявка)</option><option value="cdek_courier">СДЭК — курьер (заявка)</option>${state.pickup?'<option value="pickup">Самовывоз</option>':''}</select></label><label class="mik-field"><span>Адрес / пункт выдачи СДЭК</span><textarea name="address">${esc(draft.address??c.address??'')}</textarea></label><label class="mik-field"><span>Оплата</span><select name="payment"><option value="tbank">Т-Банк (заявка, без списания)</option><option value="invoice">По счёту</option>${state.pickup?'<option value="pickup">При самовывозе</option>':''}</select></label><label class="mik-field"><span>Комментарий</span><textarea name="notes">${esc(draft.notes||'')}</textarea></label></div>${c.type==='legal'?`<p class="mik-muted">Реквизиты организации будут взяты из <a href="${safeUrl(cfg.urls.account)}">вашего профиля</a>.</p>`:''}<div class="mik-total"><span>Товары</span><strong>${esc(money(data.total,data.currency))}</strong></div><p class="mik-muted">Стоимость доставки согласовывается отдельно. Т-Банк и СДЭК пока работают в режиме заявки. Платёж не списывается.</p><label class="mik-check"><input name="consent" type="checkbox" value="1" required> Согласен на обработку данных для оформления заказа</label><button class="mik-btn mik-btn-wide" type="submit" ${!data.can_order?'disabled':''}>Отправить заказ менеджеру</button></form></aside></div>`:empty('Корзина пуста.')}`;
    const form=$('[data-checkout]',r);if(!form)return;for(const key of ['delivery','payment'])if(draft[key]&&form.elements[key].querySelector(`option[value="${draft[key]}"]`))form.elements[key].value=draft[key];form.oninput=()=>{r._draft=formData(form);};
    form.onsubmit=async e=>{e.preventDefault();const button=$('[type=submit]',form);button.disabled=true;try{
      const f=formData(form);let key=r._requestKey;if(!key){key=crypto.randomUUID().replaceAll('-','');r._requestKey=key;}
      const order=await api('orders',{request_key:key,customer:{name:f.name,email:f.email,phone:f.phone,address:f.address},delivery:f.delivery,payment:f.payment,notes:f.notes,consent:!!f.consent});r._requestKey=null;r._draft=null;
      r.innerHTML=`<div class="mik-order-success"><h1>Заказ № ${order.id} принят</h1><p>${esc(cfg.labels.order_success)}</p><p>Статус: ожидает подтверждения. Оплата не списана.</p><a class="mik-btn" href="${safeUrl(docUrl('xls',order.id))}">Скачать заказ XLS</a>${state.customer?`<a class="mik-btn mik-btn-outline" href="${safeUrl(cfg.urls.account)}">Мои заказы</a>`:''}${order.mail_status==='failed'?'<p>Заказ сохранён. Отправка письма менеджеру задерживается.</p>':''}</div>`;r._done=true;await refresh();
    }catch(err){toast(err.message,true);button.disabled=false;}};
  }
  const statuses={pending:'Ожидает подтверждения',confirmed:'Подтверждён',invoiced:'Выставлен счёт',paid:'Оплачен',shipped:'Отправлен',completed:'Выполнен',cancelled:'Отменён'};
  async function account(r) {
    const c=state.customer;const qs=new URLSearchParams(location.search);
    if(!c){
      let mode=r._authMode||'login';if(qs.has('mik_reset'))mode='confirm';
      r.innerHTML=`<div class="mik-auth"><h1>${mode==='register'?'Регистрация':mode==='login'?'Вход в личный кабинет':'Восстановление пароля'}</h1>${!['reset','confirm'].includes(mode)?`<div class="mik-tabs"><button data-auth-mode="login" class="${mode==='login'?'is-active':''}">Вход</button><button data-auth-mode="register" class="${mode==='register'?'is-active':''}">Регистрация</button></div>`:''}<form data-auth-form><div class="mik-form-grid">${field('email','Email',qs.get('email')||'','email',true)}${mode==='register'?`${field('name','ФИО','','text',true)}${field('phone','Телефон','','tel',true)}<label class="mik-field"><span>Тип покупателя</span><select name="type"><option value="physical">Физическое лицо</option><option value="legal">Юридическое лицо / ИП</option></select></label><label class="mik-field"><span>Адрес доставки СДЭК</span><textarea name="address"></textarea></label>`:''}${mode!=='reset'?`<label class="mik-field"><span>Пароль${mode!=='login'?' (не менее 12 символов)':''}</span><input name="password" type="password" required ${mode!=='login'?'minlength="12"':''} autocomplete="${mode==='login'?'current-password':'new-password'}"></label>`:''}</div>${mode==='register'?`<div data-legal-fields hidden>${billingFields()}</div><label class="mik-check"><input type="checkbox" name="consent" value="1" required> Согласен на обработку данных</label>`:''}<p class="mik-form-message" role="status"></p><button class="mik-btn" type="submit">${({login:'Войти',register:'Зарегистрироваться',reset:'Отправить ссылку',confirm:'Изменить пароль'})[mode]}</button></form><button class="mik-link" data-auth-mode="${mode==='login'?'reset':'login'}">${mode==='login'?'Забыли пароль?':'Вернуться ко входу'}</button></div>`;
      $$('[data-auth-mode]',r).forEach(b=>b.onclick=()=>{r._authMode=b.dataset.authMode;account(r);});const form=$('[data-auth-form]',r);if(form.elements.type)form.elements.type.onchange=()=>{$('[data-legal-fields]',r).hidden=form.elements.type.value!=='legal';};
      form.onsubmit=async e=>{e.preventDefault();const b=$('[type=submit]',form),msg=$('.mik-form-message',form);b.disabled=true;try{let payload=formData(form),endpoint=mode==='confirm'?'reset-password':mode==='reset'?'reset-request':mode;if(mode==='confirm'){payload.token=qs.get('mik_reset');}const result=await api('auth/'+endpoint,payload);if(result.csrf){state=result;updateWidgets();account(r);}else{msg.textContent=result.message;if(mode==='confirm'){history.replaceState(null,'',cfg.urls.account);r._authMode='login';}b.disabled=false;}}catch(e){msg.textContent=e.message;b.disabled=false;}};return;
    }
    const orders=await api('orders'),b=c.billing||{};
    r.innerHTML=`<div class="mik-section-head"><div><h1>Личный кабинет</h1><p>${esc(c.name)} · ${c.type==='legal'?'Юридическое лицо':'Физическое лицо'} · ${esc(state.group?.name)}</p></div><button class="mik-btn mik-btn-outline" data-logout>Выйти</button></div><div class="mik-account-layout"><nav class="mik-account-nav"><a href="#mik-orders">Мои заказы</a><a href="#mik-profile">Профиль и реквизиты</a><a href="#mik-price">Мой прайс</a><a href="#mik-permanent">Постоянный покупатель</a><a href="${safeUrl(cfg.urls.cart)}">Корзина</a></nav><div><section class="mik-panel" id="mik-orders"><h2>Мои заказы</h2>${orders.length?`<div class="mik-table-scroll"><table class="mik-table"><thead><tr><th>Заказ</th><th>Дата</th><th>Статус</th><th>Сумма</th><th>Документы</th></tr></thead><tbody>${orders.map(o=>`<tr><td>#${o.id}</td><td>${esc(o.created_at)}</td><td>${esc(statuses[o.status]||o.status)}</td><td>${esc(money(o.total,o.currency))}</td><td><a href="${safeUrl(docUrl('xls',o.id))}">XLS</a>${o.invoice_number?` · <a href="${safeUrl(docUrl('invoice',o.id))}" target="_blank" rel="noopener">Счёт</a>`:''}<button class="mik-link" data-order-detail="${o.id}">Состав</button></td></tr>`).join('')}</tbody></table></div><div data-order-content></div>`:'<p>У вас пока нет заказов.</p>'}</section><section class="mik-panel" id="mik-profile"><h2>Профиль покупателя</h2><form data-profile><div class="mik-form-grid">${field('name','ФИО',c.name,'text',true)}${field('phone','Телефон',c.phone,'tel',true)}<label class="mik-field"><span>Тип покупателя</span><select name="type"><option value="physical" ${c.type==='physical'?'selected':''}>Физическое лицо</option><option value="legal" ${c.type==='legal'?'selected':''}>Юридическое лицо / ИП</option></select></label><label class="mik-field"><span>Адрес доставки СДЭК</span><textarea name="address">${esc(c.address)}</textarea></label></div><div data-legal-fields ${c.type!=='legal'?'hidden':''}><h3>Реквизиты организации</h3>${billingFields(b)}</div><details><summary>Изменить пароль</summary>${field('current_password','Текущий пароль','','password')}${field('new_password','Новый пароль','','password')}</details><button class="mik-btn" type="submit">Сохранить профиль</button><p class="mik-form-message" role="status"></p></form></section><section class="mik-panel" id="mik-price"><h2>Прайс вашей группы</h2><p>Группа: ${esc(state.group?.name)}. Цены других групп в этот файл не включаются.</p><a class="mik-btn mik-btn-secondary" href="${safeUrl(docUrl('price'))}">Скачать прайс CSV</a><h3>Добавить позиции из прайса</h3><p>Заполните колонку «Количество» в CSV и загрузите файл. До 500 позиций за раз. При добавлении используются актуальные цены вашей группы.</p><input type="file" accept=".csv,text/csv" data-cart-file aria-label="Выберите заполненный прайс CSV"><div data-cart-file-result></div></section><section class="mik-panel" id="mik-permanent"><h2>Постоянный покупатель</h2><p>Статус заявки: ${esc(({none:'не подавалась',pending:'на рассмотрении',approved:'одобрена',rejected:'отклонена'})[c.permanent_status]||c.permanent_status)}</p><p>Перед отправкой заполните полное ФИО, телефон и адрес СДЭК в профиле.</p><button class="mik-btn" data-permanent ${['pending','approved'].includes(c.permanent_status)?'disabled':''}>Отправить заявку</button></section></div></div>`;
    const form=$('[data-profile]',r);form.elements.type.onchange=()=>{$('[data-legal-fields]',form).hidden=form.elements.type.value!=='legal';};form.onsubmit=async e=>{e.preventDefault();const button=$('[type=submit]',form);button.disabled=true;try{state=await api('profile',formData(form));updateWidgets();$('.mik-form-message',form).textContent='Профиль сохранён.';}catch(e){$('.mik-form-message',form).textContent=e.message;}button.disabled=false;};
    $('[data-permanent]',r).onclick=async e=>{e.currentTarget.disabled=true;try{state=await api('profile/permanent',{});updateWidgets();account(r);}catch(e){toast(e.message,true);$('[data-permanent]',r).disabled=false;}};
    $$('[data-order-detail]',r).forEach(btn=>btn.onclick=async()=>{try{const o=await api('orders/'+btn.dataset.orderDetail);$('[data-order-content]',r).innerHTML=`<h3>Заказ № ${o.id}</h3>${o.items.map(i=>`<p>${esc(i.sku)} · ${esc(i.name)} · ${i.qty} × ${esc(money(i.price,o.currency))}</p>`).join('')}`;}catch(e){toast(e.message,true);}});
    $('[data-cart-file]',r).onchange=async e=>{const f=e.target.files[0];if(!f)return;try{const rows=parseCsv(await f.text());const headers=rows.shift()||[];const si=headers.findIndex(v=>/^(артикул|sku)$/i.test(v.trim())),qi=headers.findIndex(v=>/^(количество|qty)$/i.test(v.trim()));if(si<0||qi<0)throw new Error('Нужны колонки «Артикул» и «Количество».');const selected=rows.filter(x=>Number(x[qi])>0).map(x=>({sku:x[si].replace(/^'/,''),qty:Number(x[qi])}));const result=await api('cart/import',{rows:selected});$('[data-cart-file-result]',r).innerHTML=`<p>Добавлено позиций: ${result.added}</p>${result.errors.map(x=>`<p>${esc(x.sku)}: ${esc(x.message)}</p>`).join('')}<a class="mik-btn" href="${safeUrl(cfg.urls.cart)}">В корзину</a>`;await refresh();}catch(err){toast(err.message,true);}};
  }
  function parseCsv(text) {
    text=text.replace(/^\uFEFF/,'');const sep=text.split(/\r?\n/,1)[0].includes(';')?';':',';const rows=[];let row=[],cell='',quoted=false;
    for(let i=0;i<text.length;i++){const c=text[i];if(c==='"'){if(quoted&&text[i+1]==='"'){cell+='"';i++;}else quoted=!quoted;}else if(c===sep&&!quoted){row.push(cell);cell='';}else if((c==='\n'||c==='\r')&&!quoted){if(c==='\r'&&text[i+1]==='\n')i++;row.push(cell);if(row.some(v=>v!==''))rows.push(row);row=[];cell='';}else cell+=c;}if(cell||row.length){row.push(cell);rows.push(row);}return rows;
  }
  async function mount(r) {
    if(r._mikMounted)return;r._mikMounted=true;roots.add(r);const type=r.dataset.mik;let o={};try{o=JSON.parse(r.dataset.options||'{}');}catch{}
    try {
      if(type==='category-cards'){await categoryCards(r,o);return;}if(type==='header-categories'){await headerCategories(r,o);return;}if(type==='static'){mountAll(r);return;}if(type==='slider'){slider(r,o);return;}if(type==='mobile-menu'){mobileMenu(r);return;}if(type==='category-browser'){await categoryBrowser(r,o);return;}if(type==='footer-categories'){await categoryBrowser(r,{...o,footer:true});return;}if(type==='catalog-button'){await catalogButton(r);return;}
      await statePromise;
      if(['cart-icon','wishlist-icon','account-icon','store-status'].includes(type)){renderWidget(r,type);return;}
      const fn={search, 'catalog-button':catalogButton,carousel,catalog,product,cart,wishlist,account}[type];if(fn)await fn(r,o);
    }catch(e){error(r,e);}
  }
  function mountAll(parent=document) {$$('[data-mik]',parent).forEach(mount);}
  document.addEventListener('click', async e=>{
    const inside=e.target.closest('.mik');
    if(!inside){$$('.mik-popover,.mik-search-results,.mik-megamenu').forEach(x=>x.hidden=true);$$('[aria-expanded=true].mik-header-icon,[aria-expanded=true].mik-catalog-toggle').forEach(x=>x.setAttribute('aria-expanded','false'));return;}
    const pop=e.target.closest('[data-popover]');if(pop){const r=pop.closest('[data-mik]');widgetOpen(r,$('.mik-popover',r).hidden);return;}
    const add=e.target.closest('[data-mik-add]'),wish=e.target.closest('[data-mik-wish]'),remove=e.target.closest('[data-mini-remove]'),cartQty=e.target.closest('[data-cart-qty]'),logout=e.target.closest('[data-logout]');
    const button=add||wish||remove||cartQty||logout;if(!button)return;e.preventDefault();if(button.disabled)return;button.disabled=true;
    try {
      if(logout){state=await api('auth/logout',{});updateWidgets();for(const r of roots)if(r.dataset.mik==='account')account(r);}
      else {
        const kind=wish?'wishlist':remove?.dataset.kind||'cart',id=Number((wish||add)?.dataset.mikWish||(add?.dataset.mikAdd)||remove?.dataset.miniRemove||cartQty?.dataset.cartQty);
        let qty=cartQty?Number(cartQty.dataset.qty):1;if(add?.hasAttribute('data-use-qty'))qty=Number($('.mik-quantity input',add.closest('.mik-buy-box')).value);
        await api(kind,{product_id:id,qty,op:wish?'toggle':remove?'remove':cartQty?'set':'add'});await refresh();
        for(const r of roots){if(r.dataset.mik==='cart'&&!r._done&&kind==='cart')await cart(r);if(r.dataset.mik==='wishlist'&&kind==='wishlist')await wishlist(r);}
        if(add)toast('Товар добавлен в корзину');
      }
    }catch(err){toast(err.message,true);}finally{if(button.isConnected)button.disabled=false;}
  });
  document.addEventListener('change',async e=>{const b=e.target.closest('[data-cart-input]');if(!b)return;try{await api('cart',{product_id:Number(b.dataset.cartInput),qty:Number(b.value),op:'set'});await refresh();await cart(b.closest('[data-mik="cart"]'));}catch(err){toast(err.message,true);await cart(b.closest('[data-mik="cart"]'));}});
  document.addEventListener('submit',async e=>{const f=e.target.closest('.mik-mini-login');if(!f)return;e.preventDefault();try{state=await api('auth/login',formData(f));updateWidgets();for(const r of roots)if(r.dataset.mik==='account')account(r);}catch(err){toast(err.message,true);}});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){$$('.mik-popover,.mik-megamenu,.mik-search-results').forEach(x=>x.hidden=true);$$('.mik [aria-expanded=true]').forEach(x=>x.setAttribute('aria-expanded','false'));}});
  function start(){statePromise=refresh();statePromise.catch(()=>{});mountAll();new MutationObserver(records=>{for(const rec of records)for(const n of rec.addedNodes)if(n.nodeType===1){if(n.matches('[data-mik]'))mount(n);mountAll(n);}}).observe(document.body,{childList:true,subtree:true});setInterval(()=>api('hours').then(h=>{if(state){state.hours=h;for(const r of roots)if(r.dataset.mik==='store-status')renderWidget(r,'store-status');}}).catch(()=>{}),60000);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
