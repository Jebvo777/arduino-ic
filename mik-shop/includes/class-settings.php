<?php
namespace MIK;
defined('ABSPATH') || exit;

class Settings {
    public static function defaults(): array {
        return [
            'store_name'=>'МИКРИК', 'phone'=>'+7 (925) 888-67-76', 'email'=>get_option('admin_email'),
            'timezone'=>'Europe/Moscow', 'days'=>[1,2,3,4,5], 'open'=>'10:00', 'close'=>'19:00',
            'hours_label'=>'Пн–Пт, 10:00–19:00 МСК', 'telegram'=>'', 'ozon_store'=>'https://www.ozon.ru/search/?text=электронные+компоненты',
            'pickup'=>true, 'pickup_address'=>'Адрес самовывоза уточняется у менеджера',
            'guest_prices'=>true, 'currency'=>'RUB', 'usd_rate'=>0, 'tax_label'=>'Без НДС',
            'cart_slug'=>'cart-mik', 'wishlist_slug'=>'wishlist-mik', 'account_slug'=>'lk-mik', 'shop_slug'=>'shop',
            'per_page'=>24, 'seller'=>['name'=>'','inn'=>'','kpp'=>'','address'=>'','bank'=>'','bik'=>'','account'=>'','correspondent'=>''],
            'colors'=>['background'=>'#F2F4F7','heading'=>'#242A33','text'=>'#454D59','muted'=>'#657080','accent'=>'#F85F03','secondary'=>'#02ADB3'],
            'heading_font'=>'Manrope', 'body_font'=>'Inter', 'load_fonts'=>true,
            'labels'=>['cart'=>'Корзина','wishlist'=>'Избранное','account'=>'Личный кабинет','catalog'=>'Каталог','search'=>'Поиск по маркировке, коду или названию','order_success'=>'Спасибо за заказ. Наш менеджер свяжется с вами.'],
            'slides'=>[
                ['title'=>'Выгодные цены на электронные компоненты','text'=>'Комплектуйте заказ для ремонта, разработки и производства. Смотрите специальные предложения и цены для постоянных покупателей.','image'=>'','local_image'=>'slider-components.webp','button'=>'Выбрать компоненты','url'=>'/shop/','secondary'=>'Оптовым покупателям','secondary_url'=>'/wholesale-mik/','color'=>'#F85F03'],
                ['title'=>'Доставка СДЭК по России','text'=>'Получайте электронные компоненты в пункте выдачи или курьером. Менеджер согласует стоимость и срок отправки.','image'=>'','local_image'=>'slider-delivery.webp','button'=>'О доставке','url'=>'/purchase-mik/','secondary'=>'В каталог','secondary_url'=>'/shop/','color'=>'#02ADB3'],
                ['title'=>'Не подходит СДЭК? Найдите товар на Ozon','text'=>'Часть ассортимента можно заказать на Ozon. Ищите нужную позицию по маркировке и выбирайте удобный способ получения.','image'=>'','local_image'=>'slider-marketplace.webp','button'=>'Открыть Ozon','url'=>'https://www.ozon.ru/search/?text=электронные+компоненты','secondary'=>'Выбрать компонент','secondary_url'=>'/shop/','color'=>'#005BFF'],
            ],
            'pages'=>[
                'wholesale'=>['title'=>'Оптовым покупателям','content'=>'<h2>Персональные условия для постоянных клиентов</h2><p>Зарегистрируйтесь, заполните профиль и отправьте заявку постоянного покупателя. Менеджер назначит вашу ценовую группу. После входа вы сможете скачать свой прайс и сформировать заказ.</p>'],
                'purchase'=>['title'=>'Информация о покупке','content'=>'<h2>Как оформить заказ</h2><p>Найдите компоненты, добавьте нужное количество в корзину, заполните данные и отправьте заказ. Менеджер подтвердит наличие, стоимость доставки и способ оплаты.</p><h2>Оплата и доставка</h2><p>Т-Банк и СДЭК пока работают в режиме заявки. Онлайн-списание средств не производится. Доступен самовывоз, если он включён в настройках магазина.</p>'],
                'company'=>['title'=>'Информация о компании','content'=>'<p>Интернет-магазин электронных компонентов для ремонта, разработки и производства. Основное направление — микросхемы, транзисторы, диоды и комплектующие.</p>'],
                'contacts'=>['title'=>'Контакты','content'=>'<p>Свяжитесь с магазином в рабочее время. Контактные данные указаны ниже.</p>'],
                'legal'=>['title'=>'Правовая информация','content'=>'<p>Владелец магазина заполняет здесь условия продажи, реквизиты и политику обработки персональных данных до начала приёма заказов.</p>'],
            ],
            'carousel'=>['title'=>'Хиты продаж','limit'=>12,'columns'=>4,'source'=>'featured'],
            'blocks'=>[],
        ];
    }
    public static function all(): array { return array_replace_recursive(self::defaults(), (array)get_option('mik_settings', [])); }
    public static function get(string $key, $fallback=null) { return self::all()[$key] ?? $fallback; }
    public static function url(string $kind): string { return home_url('/'. trim((string)self::get($kind.'_slug',$kind.'-mik'),'/').'/'); }
    public static function save(array $in): void {
        $clean=self::all();
        foreach (['store_name','phone','hours_label','pickup_address','tax_label','heading_font','body_font'] as $key) {
            if (isset($in[$key])) { $clean[$key]=sanitize_text_field($in[$key]); }
        }
        foreach (['cart','wishlist','account','shop'] as $type) { if(isset($in[$type.'_slug'])){$clean[$type.'_slug']=sanitize_title($in[$type.'_slug']);} }
        foreach (['telegram','ozon_store'] as $key) { if(isset($in[$key])){$clean[$key]=esc_url_raw($in[$key],['https','http']);} }
        if(isset($in['email'])){$clean['email']=sanitize_email($in['email']);}
        foreach (['pickup','guest_prices','load_fonts'] as $key) { if(array_key_exists($key,$in)){$clean[$key]=(bool)$in[$key];} }
        if(isset($in['days'])){$clean['days']=array_values(array_filter(array_map('intval',(array)$in['days']),fn($v)=>$v>=1&&$v<=7));}
        foreach (['open','close'] as $key) { if(isset($in[$key])&&preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$in[$key])){$clean[$key]=$in[$key];} }
        if(isset($in['timezone'])&&in_array($in['timezone'],timezone_identifiers_list(),true)){$clean['timezone']=$in['timezone'];}
        if(isset($in['usd_rate'])){$clean['usd_rate']=max(0,(float)$in['usd_rate']);}
        if(isset($in['per_page'])){$clean['per_page']=max(6,min(60,(int)$in['per_page']));}
        foreach ((array)($in['seller']??[]) as $k=>$v) { if(array_key_exists($k,$clean['seller'])){$clean['seller'][$k]=sanitize_text_field($v);} }
        foreach ((array)($in['colors']??[]) as $k=>$v) { if(array_key_exists($k,$clean['colors'])&&sanitize_hex_color($v)){$clean['colors'][$k]=sanitize_hex_color($v);} }
        foreach ((array)($in['labels']??[]) as $k=>$v) { if(array_key_exists($k,$clean['labels'])){$clean['labels'][$k]=sanitize_text_field($v);} }
        foreach ((array)($in['pages']??[]) as $k=>$v) { if(isset($clean['pages'][$k])){$clean['pages'][$k]=['title'=>sanitize_text_field($v['title']??''),'content'=>wp_kses_post($v['content']??'')];} }
        if(isset($in['slides'])) {
            $clean['slides']=[];
            foreach(array_slice((array)$in['slides'],0,10) as $slide){
                $s=[];foreach(['title','text','button','secondary'] as $k){$s[$k]=sanitize_text_field($slide[$k]??'');}
                foreach(['image','url','secondary_url'] as $k){$s[$k]=esc_url_raw($slide[$k]??'',['http','https']);}
                $s['color']=sanitize_hex_color($slide['color']??'')?:'#F85F03';
                $s['local_image']=in_array($slide['local_image']??'',['slider-components.webp','slider-delivery.webp','slider-marketplace.webp'],true)?$slide['local_image']:'';
                $clean['slides'][]=$s;
            }
        }
        if(isset($in['carousel'])){$clean['carousel']=['title'=>sanitize_text_field($in['carousel']['title']??''),'limit'=>max(4,min(40,(int)($in['carousel']['limit']??12))),'columns'=>max(1,min(6,(int)($in['carousel']['columns']??4))),'source'=>in_array($in['carousel']['source']??'',['featured','new','all'],true)?$in['carousel']['source']:'featured'];}
        if(isset($in['blocks'])&&is_array($in['blocks'])){foreach($in['blocks'] as $k=>$block){if(in_array($k,array_values(Shortcodes::MAP),true)){$clean['blocks'][$k]=['title'=>sanitize_text_field($block['title']??''),'text'=>wp_kses_post($block['text']??'')];}}}
        update_option('mik_settings',$clean,false);
    }
    public static function hours(): array {
        $s=self::all();$now=new \DateTimeImmutable('now',new \DateTimeZone($s['timezone']));
        $day=(int)$now->format('N');$time=$now->format('H:i');
        $on=in_array($day,$s['days'],true)&&($s['open']<=$s['close']?($time>=$s['open']&&$time<$s['close']):($time>=$s['open']||$time<$s['close']));
        return ['open'=>$on,'phone'=>$on?$s['phone']:'','phone_raw'=>$on?preg_replace('/[^+\d]/','',$s['phone']):'','hours'=>$s['hours_label'],'status'=>$on?'Магазин работает':'Магазин закрыт'];
    }
}

