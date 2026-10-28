<?php
namespace MIK;
defined('ABSPATH') || exit;

/** Shared information pages, footer and the mobile navigation shell. */
class Layout {
    public const ICONS=['chip','box','truck','card','document','building','user','check','clock','help','search','heart','cart','arrow','mail','pin'];
    public static function icon(string $name,int $size=24): string {
        $paths=[
            'chip'=>'<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 3v3m6-3v3M9 18v3m6-3v3M3 9h3m-3 6h3m12-6h3m-3 6h3"/><rect x="9" y="9" width="6" height="6" rx="1"/>',
            'box'=>'<path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v10M7 5l10 4"/>',
            'truck'=>'<path d="M3 5h11v12H3zM14 9h4l3 4v4h-7"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
            'card'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
            'document'=>'<path d="M14 3H5v18h14V8l-5-5Z M14 3v6h5M8 13h8M8 17h6"/>',
            'building'=>'<path d="M4 21V5l8-2v18M12 8h8v13M2 21h20M7 8h2m-2 4h2m-2 4h2m6-4h2m-2 4h2"/>',
            'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'check'=>'<path d="m5 12 4 4L19 6"/><circle cx="12" cy="12" r="10"/>',
            'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'help'=>'<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4M12 17h.01"/>',
            'search'=>'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
            'heart'=>'<path d="M20 5a5 5 0 0 0-8 1 5 5 0 0 0-8-1c-5 5 4 12 8 16 4-4 13-11 8-16Z"/>',
            'cart'=>'<path d="M3 3h2l3 12h11l3-9H6M8 20h.01M18 20h.01"/>',
            'arrow'=>'<path d="M4 12h16m-6-6 6 6-6 6"/>',
            'mail'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
            'pin'=>'<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="2"/>',
        ];
        return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['chip']).'</svg>';
    }
    public static function page_defaults(): array {
        $card=fn($icon,$title,$text,$kind='',$label='Подробнее')=>compact('icon','title','text','kind','label');
        $step=fn($title,$text)=>compact('title','text');
        $faq=fn($question,$answer)=>compact('question','answer');
        $base=['title'=>'','eyebrow'=>'Покупателям','lead'=>'','icon'=>'chip','note'=>'Электронные компоненты для ваших задач','cards'=>[],'steps'=>[],'faq'=>[],'content'=>'','cta_title'=>'Нужна помощь с заказом?','cta_text'=>'Напишите нам: поможем уточнить маркировку, наличие и условия покупки.','cta_label'=>'Связаться с магазином','cta_kind'=>'contacts'];
        return array_map(fn($p)=>array_replace($base,$p),[
            'purchase'=>['title'=>'Как купить','eyebrow'=>'От поиска до получения','lead'=>'Найдите нужный компонент, соберите корзину и отправьте заказ. Мы уточним детали и поможем с оформлением.','icon'=>'cart','note'=>'Ваш заказ — в несколько понятных шагов','cards'=>[
                $card('search','Найдите компонент','Ищите по маркировке, артикулу или названию. В каталоге доступны категории, характеристики и фильтры.','shop','Перейти в каталог'),
                $card('heart','Сохраните подборку','Добавляйте интересующие позиции в избранное, чтобы вернуться к ним позже.','wishlist','Открыть избранное'),
                $card('user','Сохраните свои данные','В кабинете доступны профиль, история заказов и документы. Для организации можно указать реквизиты.','account','Личный кабинет')],
                'steps'=>[$step('Выберите товары','Откройте карточку, изучите характеристики и укажите количество.'),$step('Заполните корзину','Проверьте состав заказа, контакты, адрес и вариант получения.'),$step('Отправьте заказ','Менеджер подтвердит наличие, итоговую сумму и условия оплаты.'),$step('Получите компоненты','Выберите согласованную доставку или самовывоз.')],
                'faq'=>[$faq('Можно оформить заказ без регистрации?','Да, оставьте контакты в корзине. Кабинет удобен для истории заказов, сохранённых реквизитов и персонального прайса.'),$faq('Нужного количества нет в наличии. Что делать?','Напишите нам маркировку и необходимое количество. Менеджер уточнит возможность поставки.')]],
            'business'=>['title'=>'Юридическим лицам','eyebrow'=>'Для организаций и ИП','lead'=>'Закупайте компоненты для ремонта, разработки и производства. Сохраните реквизиты организации и получайте документы по своим заказам.','icon'=>'building','note'=>'Реквизиты, заказы и счета в одном кабинете','cards'=>[
                $card('building','Профиль организации','Выберите тип «Юридическое лицо / ИП» и заполните наименование, ИНН и банковские реквизиты.','account','Заполнить профиль'),
                $card('document','Счёт на оплату','После согласования менеджер выставит счёт. Документ станет доступен в личном кабинете.'),
                $card('box','Персональные условия','Для регулярных закупок можно запросить индивидуальные условия и ценовую группу.','wholesale','Об оптовых закупках')],
                'steps'=>[$step('Создайте кабинет','Укажите рабочий email и выберите юридическое лицо или ИП.'),$step('Добавьте реквизиты','Проверьте организацию, ИНН, адрес и банковские данные.'),$step('Согласуйте заказ','Отправьте корзину. Менеджер подтвердит сумму и подготовит счёт.')],
                'faq'=>[$faq('Где посмотреть выставленный счёт?','В личном кабинете, в разделе «Мои заказы». Ссылка появится после выставления счёта менеджером.'),$faq('Как изменить реквизиты?','Откройте «Профиль и реквизиты» в кабинете и сохраните изменения. Если счёт уже выпущен, свяжитесь с менеджером.')],
                'cta_title'=>'Подготовьте профиль организации','cta_text'=>'Реквизиты сохранятся для следующих заказов.','cta_label'=>'Перейти в кабинет','cta_kind'=>'account'],
            'payment'=>['title'=>'Оплата','eyebrow'=>'Понятные условия покупки','lead'=>'Сначала согласуем состав заказа и итоговую сумму. Менеджер сообщит доступный способ оплаты и необходимые данные.','icon'=>'card','note'=>'Оплата после подтверждения заказа','cards'=>[
                $card('card','Для частных покупателей','Выберите удобный вариант при оформлении. Дождитесь подтверждения и инструкций менеджера.'),
                $card('document','По счёту для организации','Заполните реквизиты юридического лица или ИП. Согласованный счёт будет доступен в кабинете.','business','Порядок для организаций'),
                $card('pin','При самовывозе','Возможность оплаты при получении согласуйте заранее вместе с адресом и временем визита.','contacts','Уточнить условия')],
                'steps'=>[$step('Отправьте заказ','В корзине укажите свои контакты и выбранный вариант оплаты.'),$step('Дождитесь подтверждения','Мы проверим наличие, доставку и итоговую сумму.'),$step('Получите инструкции','Используйте только согласованные с менеджером данные для оплаты.')],
                'faq'=>[$faq('Спишутся ли деньги сразу после оформления?','Нет. Отправка заказа сама по себе не списывает деньги. Дождитесь подтверждения магазина.'),$faq('Где взять документы по заказу?','Заказ можно скачать в формате XLS. Выставленный счёт доступен в кабинете покупателя.')]],
            'delivery'=>['title'=>'Доставка','eyebrow'=>'Выберите удобный способ получения','lead'=>'СДЭК, согласованный самовывоз или покупка доступных позиций на Ozon. Срок и стоимость отправки уточняются при подтверждении заказа.','icon'=>'truck','note'=>'Получайте компоненты удобным способом','cards'=>[
                $card('box','СДЭК — пункт выдачи','Укажите город и адрес подходящего пункта выдачи в данных заказа. Менеджер согласует отправку.'),
                $card('truck','СДЭК — курьер','Укажите полный адрес и контактный телефон. Стоимость и срок доставки подтвердит менеджер.'),
                $card('pin','Самовывоз','Если самовывоз доступен, заранее согласуйте адрес и время получения.','contacts','Контакты магазина'),
                $card('cart','Покупка на Ozon','В карточке товара есть кнопка Ozon: она откроет предложение или поиск по названию.','shop','Выбрать товар')],
                'steps'=>[$step('Укажите получение','Выберите доставку или самовывоз при оформлении корзины.'),$step('Согласуйте детали','Менеджер уточнит адрес, стоимость и срок.'),$step('Дождитесь отправки','Условия получения и дальнейшие сведения уточняйте по своему заказу.')],
                'faq'=>[$faq('Почему стоимость доставки уточняется отдельно?','Она зависит от адреса, габаритов, веса и выбранного способа. Итоговую сумму согласует менеджер до оплаты.'),$faq('Переносится ли корзина на Ozon?','Заказ на Ozon оформляется отдельно на стороне площадки. Используйте кнопку Ozon в карточке интересующего товара.')]],
            'help'=>['title'=>'Помощь','eyebrow'=>'Поддержка покупателей','lead'=>'Собрали ответы на частые вопросы о товарах, кабинете, заказах и документах. Если ответа нет — свяжитесь с магазином.','icon'=>'help','note'=>'Поможем разобраться с покупкой','cards'=>[
                $card('search','Подобрать компонент','Используйте маркировку, артикул и фильтры по характеристикам. По совместимости уточняйте точную модель у менеджера.','shop','Открыть каталог'),
                $card('user','Разобраться с кабинетом','Сохраните профиль, реквизиты и следите за своими заказами.','account','Открыть кабинет'),
                $card('mail','Задать вопрос','Отправьте маркировку, нужное количество и суть вопроса — так мы быстрее поможем.','contacts','Написать нам')],
                'faq'=>[$faq('Не получается войти в кабинет.','Проверьте email и пароль. Нажмите «Забыли пароль?» на странице входа, чтобы получить ссылку для восстановления.'),$faq('Как узнать статус заказа?','Войдите в личный кабинет и откройте «Мои заказы». По гостевому заказу свяжитесь с менеджером, указав его номер.'),$faq('Где найти характеристики и PDF?','Откройте карточку товара: доступные параметры, описание и документы размещены на ней. Документы есть не у каждой позиции.'),$faq('Как загрузить заказ из прайса?','В кабинете скачайте прайс своей группы, заполните колонку «Количество» и загрузите CSV обратно. Сервер применит текущие цены.'),$faq('Как изменить или отменить заказ?','Свяжитесь с менеджером и сообщите номер заказа и необходимые изменения.'),$faq('Есть вопрос по полученному товару.','Напишите номер заказа, маркировку и описание ситуации. При необходимости приложите фотографии в переписке с менеджером.')]],
            'wholesale'=>['title'=>'Оптовым покупателям','eyebrow'=>'Регулярные закупки','lead'=>'Комплектуйте заказы для ремонта и производства. Персональная ценовая группа, собственный прайс и сохранённые реквизиты помогут ускорить повторные закупки.','icon'=>'box','note'=>'От подборки компонентов к регулярным поставкам','cards'=>[
                $card('card','Персональная цена','После согласования менеджер назначит подходящую ценовую группу.'),
                $card('document','Прайс вашей группы','Скачивайте актуальный CSV в кабинете и заполняйте нужные количества.','account','Открыть кабинет'),
                $card('cart','Заказ из файла','Загрузите заполненный CSV: доступные позиции попадут в корзину. Итоговые цены проверяются магазином.')],
                'steps'=>[$step('Зарегистрируйтесь','Укажите полное имя, телефон и данные доставки.'),$step('Отправьте заявку','В кабинете откройте раздел «Постоянный покупатель».'),$step('Получите условия','Менеджер рассмотрит заявку и согласует ценовую группу.')],
                'cta_title'=>'Покупаете компоненты регулярно?','cta_text'=>'Создайте кабинет и отправьте заявку постоянного покупателя.','cta_label'=>'Зарегистрироваться','cta_kind'=>'account'],
            'company'=>['title'=>'О компании','eyebrow'=>'МИКРИК · электронные компоненты','lead'=>'Компоненты для тех, кто ремонтирует, разрабатывает и создаёт электронику. Удобный поиск, характеристики и понятное оформление заказа.','icon'=>'chip','note'=>'От нужной маркировки — к вашему проекту','cards'=>[
                $card('chip','Для ремонта','Микросхемы, полупроводники и комплектующие для обслуживания электроники.'),
                $card('box','Для разработки','Подбор компонентов по маркировке и техническим параметрам.'),
                $card('building','Для производства','Кабинет организации, реквизиты, счета и условия для повторных закупок.','business','Юридическим лицам')],
                'cta_title'=>'Начните с нужного компонента','cta_text'=>'Найдите маркировку в каталоге или задайте вопрос магазину.','cta_label'=>'Открыть каталог','cta_kind'=>'shop'],
            'contacts'=>['title'=>'Контакты','eyebrow'=>'На связи с вами','lead'=>'Уточните наличие, условия заказа или получение компонентов. В сообщении укажите маркировку либо номер заказа.','icon'=>'mail','note'=>'Все способы связи с магазином','cards'=>[],'cta_title'=>'Уже выбрали компоненты?','cta_text'=>'Сохраните подборку в корзине — менеджеру будет проще уточнить детали.','cta_label'=>'Перейти в корзину','cta_kind'=>'cart'],
            'legal'=>['title'=>'Правовая информация','eyebrow'=>'Документы и условия','lead'=>'Информация о продавце, условиях оформления заказов и обработке данных покупателя.','icon'=>'document','note'=>'Информация для покупателей','cards'=>[
                $card('cart','Оформление заказа','Отправленная корзина передаётся магазину для подтверждения наличия, итоговой суммы и условий получения.','purchase','Как купить'),
                $card('user','Данные покупателя','Контакты и реквизиты используются для оформления заказа и связи с покупателем.'),
                $card('help','Вопросы по покупке','По оплате, документам и полученным товарам обратитесь в магазин с номером заказа.','contacts','Связаться')]],
        ]);
    }
    public static function brand(bool $dark=false): string {
        $s=Settings::all();$url=$dark?$s['logo_dark_url']:$s['logo_url'];$url=$url?:MIK_URL.'assets/img/'.($dark?'store-logo-dark.svg':'store-logo.svg');
        $image='<img class="mik-brand-image" src="'.esc_url($url).'" alt="Купить микросхемы и транзисторы" width="345" height="80">';
        return '<a class="mik-brand'.($dark?' is-dark':'').'" href="'.esc_url(home_url('/')).'" aria-label="Купить микросхемы и транзисторы — главная">'.$image.'</a>';
    }
    public static function nav(array $keys,string $class=''): string {
        $nav=Settings::navigation();$out='<nav class="'.esc_attr($class).'">';
        foreach($keys as $key){if(isset($nav[$key])){$n=$nav[$key];$out.='<a href="'.esc_url($n['url']).'">'.esc_html($n['title']).'</a>';}}
        return $out.'</nav>';
    }
    public static function page(string $type): string {
        $p=Settings::get('pages')[$type];$nav=Settings::navigation();$cta=$p['cta_kind']??'contacts';
        $out='<article class="mik-info-page mik-info-'.esc_attr($type).'"><div class="mik-info-crumbs"><a href="'.esc_url(home_url('/')).'">Главная</a><span>/</span><span>'.esc_html($p['title']).'</span></div>';
        $out.='<header class="mik-info-hero"><div><span class="mik-eyebrow">'.esc_html($p['eyebrow']).'</span><h1>'.esc_html($p['title']).'</h1><p>'.esc_html($p['lead']).'</p><a class="mik-inline-arrow" href="'.esc_url(Settings::url('shop')).'">Каталог компонентов '.self::icon('arrow',18).'</a></div><div class="mik-info-emblem"><span class="mik-emblem-icon">'.self::icon($p['icon'],72).'</span><span>'.esc_html($p['note']).'</span><i></i></div></header>';
        if($type==='contacts'){$out.=self::contacts();}
        if(!empty($p['cards'])){$out.='<section class="mik-info-cards" aria-label="Полезная информация">';foreach($p['cards'] as $c){$out.='<article class="mik-info-card"><span class="mik-info-icon">'.self::icon($c['icon']).'</span><h2>'.esc_html($c['title']).'</h2><p>'.esc_html($c['text']).'</p>';if(!empty($c['kind'])&&isset($nav[$c['kind']])){$out.='<a class="mik-inline-arrow" href="'.esc_url($nav[$c['kind']]['url']).'">'.esc_html($c['label']?:'Подробнее').' '.self::icon('arrow',17).'</a>';}$out.='</article>';}$out.='</section>';}
        if(!empty($p['steps'])){$out.='<section class="mik-info-section"><div class="mik-info-section-title"><span class="mik-eyebrow">Пошагово</span><h2>Как это работает</h2></div><ol class="mik-info-steps">';foreach($p['steps'] as $i=>$step){$out.='<li><span class="mik-step-number">'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT).'</span><div><h3>'.esc_html($step['title']).'</h3><p>'.esc_html($step['text']).'</p></div></li>';}$out.='</ol></section>';}
        if($type==='legal'){$seller=Settings::get('seller');$labels=['name'=>'Продавец','inn'=>'ИНН','kpp'=>'КПП','address'=>'Адрес','bank'=>'Банк','bik'=>'БИК','account'=>'Расчётный счёт','correspondent'=>'Корреспондентский счёт'];$rows='';foreach($labels as $key=>$label){if(!empty($seller[$key])){$rows.='<div><dt>'.esc_html($label).'</dt><dd>'.esc_html($seller[$key]).'</dd></div>';}}if($rows){$out.='<section class="mik-info-section"><h2>Реквизиты продавца</h2><dl class="mik-company-details">'.$rows.'</dl></section>';}}
        if(trim($p['content']??'')!==''){$out.='<section class="mik-info-section mik-info-rich">'.wp_kses_post($p['content']).'</section>';}
        if(!empty($p['faq'])){$out.='<section class="mik-info-section mik-info-faq"><div><span class="mik-eyebrow">Вопросы и ответы</span><h2>Полезно знать</h2></div><div>';foreach($p['faq'] as $q){$out.='<details><summary>'.esc_html($q['question']).'<span>+</span></summary><p>'.esc_html($q['answer']).'</p></details>';}$out.='</div></section>';}
        if(!empty($p['cta_title'])){$out.='<aside class="mik-info-cta"><div><h2>'.esc_html($p['cta_title']).'</h2><p>'.esc_html($p['cta_text']).'</p></div><a class="mik-btn" href="'.esc_url($nav[$cta]['url']??Settings::url('contacts')).'">'.esc_html($p['cta_label']).' '.self::icon('arrow',18).'</a></aside>';}
        return $out.'</article>';
    }
    private static function contacts(): string {
        $s=Settings::all();$out='<section class="mik-info-cards mik-contact-cards"><article class="mik-info-card"><span class="mik-info-icon">'.self::icon('clock').'</span><h2>Телефон и время работы</h2><div data-mik="store-status"></div><p>Звонки принимаем в рабочие часы магазина.</p></article><article class="mik-info-card"><span class="mik-info-icon">'.self::icon('mail').'</span><h2>Напишите нам</h2><a class="mik-contact-value" href="mailto:'.esc_attr($s['email']).'">'.esc_html($s['email']).'</a>';if($s['telegram']){$out.='<a class="mik-inline-arrow" href="'.esc_url($s['telegram']).'" target="_blank" rel="noopener">Telegram '.self::icon('arrow',18).'</a>';}$out.='<p>Укажите маркировку или номер заказа.</p></article><article class="mik-info-card"><span class="mik-info-icon">'.self::icon('pin').'</span><h2>Получение заказа</h2><p>'.esc_html($s['pickup']?$s['pickup_address']:'Доступные варианты получения согласуйте с менеджером.').'</p><a class="mik-inline-arrow" href="'.esc_url(Settings::url('delivery')).'">О доставке '.self::icon('arrow',18).'</a></article></section>';return $out;
    }
    public static function footer(): string {
        $s=Settings::all();$f=$s['footer'];
        $out='<footer class="mik-site-footer"><div class="mik-footer-main"><div class="mik-footer-about">'.self::brand(true).'<p>'.esc_html($f['description']).'</p><a class="mik-footer-catalog" href="'.esc_url(Settings::url('shop')).'">'.self::icon('chip',20).' Каталог компонентов '.self::icon('arrow',18).'</a></div><section><h2>Покупателям</h2>'.self::nav(['purchase','payment','delivery','help','legal'],'mik-footer-links').'</section><section><h2>Компания</h2>'.self::nav(['company','business','wholesale','contacts'],'mik-footer-links').'</section><section><h2>Категории товаров</h2><div data-mik="footer-categories" data-options="'.esc_attr(DB::json(['limit'=>$f['category_limit'],'ids'=>$f['category_ids']])).'"><a href="'.esc_url(Settings::url('shop')).'">Открыть все категории</a></div></section></div>';
        $out.='<div class="mik-footer-contact"><div><span class="mik-footer-caption">Магазин на связи</span><div data-mik="store-status"></div></div><div><span class="mik-footer-caption">Написать нам</span><a href="mailto:'.esc_attr($s['email']).'">'.esc_html($s['email']).'</a>';if($s['telegram']){$out.='<a href="'.esc_url($s['telegram']).'" target="_blank" rel="noopener">Telegram '.self::icon('arrow',14).'</a>';}$out.='</div><div><span class="mik-footer-caption">Получение заказа</span><span>'.esc_html($s['pickup']?$s['pickup_address']:'Условия доставки согласуйте с менеджером').'</span></div></div>';
        $out.='<div class="mik-footer-bottom"><span>© '.wp_date('Y').' '.esc_html($s['store_name']).'. '.esc_html($f['copyright']).'</span>'.self::nav(['account','wishlist','cart'],'mik-footer-personal').'</div></footer>';return $out;
    }
    public static function mobile(): string {
        $nav=Settings::navigation();$links='';foreach($nav as $key=>$n){if(in_array($key,['account','wishlist','cart','shop'],true)){continue;}$links.='<a href="'.esc_url($n['url']).'">'.esc_html($n['title']).self::icon('arrow',16).'</a>';}
        return '<button class="mik-mobile-trigger" data-mobile-open aria-label="Открыть меню сайта" aria-expanded="false" aria-haspopup="dialog"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><template data-mobile-template><dialog class="mik mik-mobile-dialog" aria-label="Меню сайта"><div class="mik-mobile-top">'.self::brand().'<button class="mik-mobile-close" data-mobile-close aria-label="Закрыть меню"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button></div><div class="mik-mobile-scroll"><div class="mik mik-search" data-mik="search"></div><div class="mik-mobile-services"><div class="mik-mobile-service"><div data-mik="account-icon"></div><a href="'.esc_url(Settings::url('account')).'">Личный кабинет</a></div><div class="mik-mobile-service"><div data-mik="wishlist-icon"></div><a href="'.esc_url(Settings::url('wishlist')).'">Избранное</a></div><div class="mik-mobile-service"><div data-mik="cart-icon"></div><a href="'.esc_url(Settings::url('cart')).'">Корзина</a></div></div><section class="mik-mobile-catalog"><div class="mik-mobile-section-head"><h2>Каталог товаров</h2><a href="'.esc_url(Settings::url('shop')).'">Весь каталог '.self::icon('arrow',15).'</a></div><div data-mik="category-browser"></div></section><section class="mik-mobile-pages"><h2>Информация</h2><nav>'.$links.'</nav></section><div class="mik-mobile-contact"><div data-mik="store-status"></div><a href="mailto:'.esc_attr(Settings::get('email')).'">'.esc_html(Settings::get('email')).'</a></div></div></dialog></template>';
    }
}
