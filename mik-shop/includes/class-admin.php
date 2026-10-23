<?php
namespace MIK;
defined('ABSPATH') || exit;

class Admin {
    public static function hooks(): void {
        add_action('admin_menu',function(){add_menu_page('MIK Shop','MIK Shop','manage_woocommerce','mik-shop',[self::class,'page'],'dashicons-store',56);});
        add_action('admin_enqueue_scripts',function($hook){
            $screen=get_current_screen();if($hook!=='toplevel_page_mik-shop'&&($screen->post_type??'')!=='product'){return;}
            wp_enqueue_style('mik-admin-fonts',MIK_URL.'assets/css/fonts.css',[],MIK_VERSION);wp_enqueue_style('mik-admin',MIK_URL.'assets/css/admin.css',[],MIK_VERSION);wp_enqueue_script('mik-admin',MIK_URL.'assets/js/admin.js',[],MIK_VERSION,true);
            wp_localize_script('mik-admin','MIK_ADMIN',['api'=>esc_url_raw(rest_url('mik/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'document'=>admin_url('admin-post.php?action=mik_document'),'edit_product'=>admin_url('post.php?action=edit&post='),'new_product'=>admin_url('post-new.php?post_type=product'),'chunk_size'=>max(16384,min(1048576,wp_max_upload_size()-65536)),'catalog_max'=>Importer::CATALOG_MAX,'package_max'=>Importer::PACKAGE_MAX,'store_url'=>home_url('/'),'store_name'=>Settings::get('store_name')]);
        });
    }
    private static function tabs(): array {
        return [
            'dashboard'=>['Обзор','Магазин сегодня: заказы, товары и покупатели','chip','Магазин'],
            'orders'=>['Заказы','Состав заказа, статусы и связь с покупателем','cart','Магазин'],
            'products'=>['Товары','Каталог, карточки и документы товаров','box','Магазин'],
            'stocks'=>['Остатки','Доступное для заказа количество и базовые цены','check','Магазин'],
            'accounts'=>['Покупатели','Кабинеты, реквизиты и персональные условия','user','Управление'],
            'groups'=>['Группы и цены','Ценовые группы и загрузка собственных прайсов','card','Управление'],
            'invoices'=>['Счета','Документы по согласованным заказам','document','Управление'],
            'parameters'=>['Характеристики','Справочник параметров и единиц измерения','chip','Управление'],
            'import'=>['Импорт товаров','Каталог, пакеты с медиа и пошаговая обработка','truck','Управление'],
            'content'=>['Контент сайта','Баннеры, информационные страницы и футер','document','Сайт'],
            'shortcodes'=>['Шорткоды','Размещение блоков и подписи элементов','help','Сайт'],
            'settings'=>['Настройки','Контакты, оформление, реквизиты и страницы','building','Сайт'],
        ];
    }
    public static function page(): void {
        if(!current_user_can('manage_woocommerce')){return;}$tabs=self::tabs();$active=sanitize_key($_GET['tab']??'dashboard');if(!isset($tabs[$active])){$active='dashboard';}$user=wp_get_current_user();
        echo '<div class="mik-admin mik-workspace"><button class="mik-sidebar-scrim" data-admin-nav-close aria-label="Закрыть навигацию" hidden></button><aside class="mik-admin-sidebar" id="mik-admin-navigation"><a class="mik-admin-brand" href="'.esc_url(admin_url('admin.php?page=mik-shop')).'"><img src="'.esc_url(MIK_URL.'assets/img/mikrik-favicon.png').'" alt=""><span>'.esc_html(Settings::get('store_name')).'<small>Управление магазином</small></span></a><nav aria-label="Разделы MIK Shop">';$last='';
        foreach($tabs as $key=>[$label,$description,$icon,$group]){if($last!==$group){echo '<span class="mik-nav-caption">'.esc_html($group).'</span>';$last=$group;}echo '<a class="mik-nav-item'.($key===$active?' is-active':'').'" '.($key===$active?'aria-current="page"':'').' href="'.esc_url(admin_url('admin.php?page=mik-shop&tab='.$key)).'">'.Layout::icon($icon,18).'<span>'.esc_html($label).'</span></a>';}
        echo '</nav><div class="mik-admin-sidebar-bottom"><a href="'.esc_url(home_url('/')).'" target="_blank" rel="noopener">Открыть сайт '.Layout::icon('arrow',16).'</a><span>MIK Shop · '.esc_html(MIK_VERSION).'</span></div></aside><main class="mik-admin-main"><div class="mik-admin-topbar"><div><button class="mik-admin-nav-toggle" data-admin-nav-toggle aria-label="Открыть разделы" aria-expanded="false" aria-controls="mik-admin-navigation">'.'<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>'.'</button><span>Магазин <i>/</i> '.esc_html($tabs[$active][0]).'</span></div><div class="mik-admin-user"><span class="mik-admin-avatar">'.esc_html(mb_substr($user->display_name?:$user->user_login,0,1)).'</span><span>'.esc_html($user->display_name?:$user->user_login).'<small>Управление магазином</small></span></div></div><div class="mik-admin-content"><header class="mik-admin-page-head"><div><span class="mik-admin-eyebrow">'.esc_html($tabs[$active][3]).'</span><h1>'.esc_html($tabs[$active][0]).'</h1><p>'.esc_html($tabs[$active][1]).'</p></div><a class="mik-admin-site-link" href="'.esc_url(home_url('/')).'" target="_blank" rel="noopener">На сайт '.Layout::icon('arrow',16).'</a></header><div id="mik-admin-messages" aria-live="polite"></div><div id="mik-admin-app" data-tab="'.esc_attr($active).'"><div class="mik-admin-loading"><span></span>Загружаем данные магазина…</div></div><footer class="mik-admin-footnote">'.esc_html(Settings::get('store_name')).' <span>MIK Shop '.esc_html(MIK_VERSION).'</span></footer></div></main></div>';
    }
    public static function data(string $tab,array $p=[]): array {
        global $wpdb;$page=max(1,(int)($p['page']??1));$offset=($page-1)*40;$q=sanitize_text_field($p['q']??'');$like='%'.$wpdb->esc_like($q).'%';
        if($tab==='dashboard'){
            $recent=$wpdb->get_results('SELECT id,status,currency,total,customer_json,created_at FROM '.DB::table('orders').' ORDER BY id DESC LIMIT 6',ARRAY_A);foreach($recent as &$o){$c=json_decode($o['customer_json'],true);$o['name']=$c['name']??'';unset($o['customer_json']);}unset($o);
            return ['customers'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('customers')),'orders'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('orders')),'pending'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM ".DB::table('orders')." WHERE status='pending'"),'products'=>(int)wp_count_posts('product')->publish,'groups'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('groups')),'recent'=>$recent,'low_stock'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('inventory')." i JOIN {$wpdb->posts} p ON p.ID=i.product_id WHERE p.post_status='publish' AND i.quantity BETWEEN 1 AND 5"),'zip'=>class_exists('ZipArchive'),'php'=>PHP_VERSION,'upload_limit'=>wp_max_upload_size()];
        }
        if($tab==='product'){return self::product_data(absint($p['id']??0));}
        if(in_array($tab,['orders','invoices'],true)){
            $where=$tab==='invoices'?" WHERE invoice_number<>''":" WHERE 1=1";if($q){$where.=$wpdb->prepare(' AND (id=%d OR customer_json LIKE %s)',(int)$q,$like);}
            $rows=$wpdb->get_results('SELECT id,status,currency,total,customer_json,delivery,payment,invoice_number,invoice_date,mail_status,created_at FROM '.DB::table('orders').$where.' ORDER BY id DESC LIMIT 40 OFFSET '.(int)$offset,ARRAY_A);foreach($rows as &$r){$r['customer']=json_decode($r['customer_json'],true);unset($r['customer_json']);}unset($r);return ['rows'=>$rows,'page'=>$page,'total'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('orders').$where)];
        }
        if(in_array($tab,['products','stocks'],true)){
            $where=" WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')";if($q){$where.=$wpdb->prepare(' AND (p.post_title LIKE %s OR m.sku LIKE %s)',$like,$like);}
            $from=" FROM {$wpdb->posts} p LEFT JOIN {$wpdb->wc_product_meta_lookup} m ON m.product_id=p.ID LEFT JOIN ".DB::table('inventory').' i ON i.product_id=p.ID';
            $rows=$wpdb->get_results('SELECT p.ID AS id,p.post_title AS name,p.post_status AS publication,m.sku,m.min_price AS price,COALESCE(i.quantity,m.stock_quantity,0) AS stock'.$from.$where.' ORDER BY p.ID DESC LIMIT 40 OFFSET '.(int)$offset,ARRAY_A);foreach($rows as &$row){$row['image']=get_the_post_thumbnail_url((int)$row['id'],'thumbnail')?:'';}unset($row);return ['rows'=>$rows,'page'=>$page,'total'=>(int)$wpdb->get_var('SELECT COUNT(*)'.$from.$where)];
        }
        if($tab==='accounts'){
            $where=$q?$wpdb->prepare(' WHERE email LIKE %s OR name LIKE %s OR phone LIKE %s',$like,$like,$like):'';
            $rows=$wpdb->get_results('SELECT * FROM '.DB::table('customers').$where.' ORDER BY id DESC LIMIT 40 OFFSET '.(int)$offset,ARRAY_A);return ['rows'=>array_map([Auth::class,'public_customer'],$rows),'groups'=>self::groups(),'page'=>$page,'total'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('customers').$where)];
        }
        if($tab==='groups'){return ['groups'=>self::groups()];}
        if($tab==='parameters'){return ['parameters'=>get_option('mik_parameters',[])];}
        if(in_array($tab,['settings','content'],true)){return ['settings'=>Settings::all()];}
        if($tab==='shortcodes'){return ['shortcodes'=>Shortcodes::MAP,'settings'=>Settings::all()];}
        if($tab==='order'){return Commerce::order(absint($p['id']??0),true);}
        if($tab==='import'){return Importer::status(absint($p['id']??0));}
        throw new \InvalidArgumentException('Неизвестная вкладка.');
    }
    private static function groups(): array { global $wpdb;return $wpdb->get_results('SELECT * FROM '.DB::table('groups').' ORDER BY id',ARRAY_A); }
    public static function save(string $tab,array $p): array {
        global $wpdb;
        if(in_array($tab,['settings','content','shortcodes'],true)){Settings::save($p);return ['saved'=>true];}
        if($tab==='product'){return self::save_product($p);}
        if($tab==='parameters'){
            $defs=[];$keys=[];foreach(array_slice((array)($p['parameters']??[]),0,200) as $d){$key=sanitize_key($d['key']??'');$label=sanitize_text_field($d['label']??'');if(!$key||!$label){continue;}if(isset($keys[$key])){throw new \InvalidArgumentException('Ключ параметра повторяется: '.$key);}$keys[$key]=1;$defs[]=['key'=>$key,'label'=>$label,'unit'=>sanitize_text_field($d['unit']??'')];}update_option('mik_parameters',$defs,false);return ['saved'=>true];
        }
        if($tab==='groups'){
            $id=absint($p['id']??0);$t=DB::table('groups');
            if(($p['op']??'')==='delete'){
                if($id<=1||(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('customers').' WHERE group_id=%d',$id))>0){throw new \InvalidArgumentException('Базовую группу и группы с покупателями удалять нельзя.');}
                $wpdb->delete($t,['id'=>$id]);$wpdb->delete(DB::table('prices'),['group_id'=>$id]);return ['saved'=>true];
            }
            $name=sanitize_text_field($p['name']??'');$currency=strtoupper(sanitize_text_field($p['currency']??'RUB'));if(!$name||!in_array($currency,['RUB','USD','EUR'],true)){throw new \InvalidArgumentException('Укажите название и поддерживаемую валюту.');}
            if($id===1&&$currency!=='RUB'){throw new \InvalidArgumentException('Базовая публичная цена должна оставаться в рублях.');}
            $data=['name'=>$name,'currency'=>$currency,'fallback_base'=>($currency==='RUB'&&!empty($p['fallback_base']))?1:0];if($id){DB::require_result($wpdb->update($t,$data,['id'=>$id]));}else{DB::require_result($wpdb->insert($t,$data));}return ['saved'=>true];
        }
        if($tab==='prices'){
            $group=absint($p['group_id']??0);if(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('groups').' WHERE id=%d',$group))){throw new \InvalidArgumentException('Группа не найдена.');}
            $rows=(array)($p['rows']??[]);if(count($rows)>500){throw new \InvalidArgumentException('Не более 500 строк за один запрос.');}$errors=[];$updated=0;
            foreach($rows as $r){$sku=sanitize_text_field($r['sku']??'');$id=wc_get_product_id_by_sku($sku);$value=str_replace(',','.',(string)($r['price']??''));if(!$id||!is_numeric($value)||(float)$value<0){$errors[]=$sku;continue;}$wpdb->replace(DB::table('prices'),['product_id'=>$id,'group_id'=>$group,'price'=>wc_format_decimal($value,4)]);$updated++;}return ['updated'=>$updated,'errors'=>$errors];
        }
        if($tab==='accounts'){
            $id=absint($p['id']??0);$group=absint($p['group_id']??1);if(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('groups').' WHERE id=%d',$group))){throw new \InvalidArgumentException('Группа не найдена.');}
            $data=['name'=>sanitize_text_field($p['name']??''),'phone'=>sanitize_text_field($p['phone']??''),'address'=>sanitize_textarea_field($p['address']??''),'group_id'=>$group,'status'=>($p['status']??'')==='disabled'?'disabled':'active','permanent_status'=>in_array($p['permanent_status']??'',['none','pending','approved','rejected'],true)?$p['permanent_status']:'none','type'=>($p['type']??'')==='legal'?'legal':'physical','billing'=>DB::json(Auth::billing((array)($p['billing']??[]))),'updated_at'=>DB::now()];DB::require_result($wpdb->update(DB::table('customers'),$data,['id'=>$id]));if($data['status']==='disabled'){$wpdb->delete(DB::table('sessions'),['customer_id'=>$id]);}return ['saved'=>true];
        }
        if($tab==='orders'){
            $id=absint($p['id']??0);if(($p['op']??'')==='invoice'){Commerce::invoice($id);}elseif(($p['op']??'')==='notify'){return ['sent'=>Commerce::notify($id)];}else{Commerce::status($id,sanitize_key($p['status']??''));}return ['saved'=>true];
        }
        if(in_array($tab,['products','stocks'],true)){
            $id=absint($p['id']??0);$product=wc_get_product($id);if(!$product||!$product->is_type('simple')){throw new \InvalidArgumentException('Нужен простой товар WooCommerce.');}
            if(filter_var($p['stock']??null,FILTER_VALIDATE_INT)===false||(int)$p['stock']<0){throw new \InvalidArgumentException('Остаток должен быть целым неотрицательным числом.');}
            DB::transaction(function()use($wpdb,$id,$p,$product){$t=DB::table('inventory');Catalog::stock($id);$old=(int)$wpdb->get_var($wpdb->prepare("SELECT quantity FROM $t WHERE product_id=%d FOR UPDATE",$id));$qty=(int)$p['stock'];$wpdb->update($t,['quantity'=>$qty,'updated_at'=>DB::now()],['product_id'=>$id]);$product->set_manage_stock(true);$product->save();Catalog::write_wc_stock($id,$qty);if(isset($p['price'])&&is_numeric($p['price'])&&(float)$p['price']>=0){$product=wc_get_product($id);$product->set_regular_price(wc_format_decimal($p['price']));$product->save();}$wpdb->insert(DB::table('movements'),['product_id'=>$id,'delta'=>$qty-$old,'reason'=>'Корректировка администратором','created_at'=>DB::now()]);});return ['saved'=>true];
        }
        if($tab==='setup'){return ['pages'=>self::create_pages()];}
        throw new \InvalidArgumentException('Неизвестное действие администратора.');
    }
    private static function product_data(int $id): array {
        $product=$id?wc_get_product($id):null;if($id&&(!$product||!$product->is_type('simple'))){throw new \InvalidArgumentException('Редактор поддерживает простые товары WooCommerce.');}
        $images=[];$pdfs=[];if($product){foreach(array_values(array_unique(array_filter(array_merge([$product->get_image_id()],$product->get_gallery_image_ids())))) as $aid){$url=wp_get_attachment_image_url($aid,'medium');if($url){$images[]=['id'=>(int)$aid,'url'=>$url,'name'=>get_the_title($aid)];}}foreach((array)get_post_meta($id,'_mik_pdfs',true) as $aid){$url=wp_get_attachment_url((int)$aid);if($url){$pdfs[]=['id'=>(int)$aid,'url'=>$url,'name'=>get_the_title($aid)];}}}
        return ['id'=>$id,'name'=>$product?$product->get_name():'','sku'=>$product?$product->get_sku():'','price'=>$product?$product->get_regular_price():'','stock'=>$product?Catalog::stock($id):0,'status'=>$product?$product->get_status():'draft','description'=>$product?wp_kses_post($product->get_description()):'','short_description'=>$product?wp_kses_post($product->get_short_description()):'','category_ids'=>$product?$product->get_category_ids():[],'categories'=>Catalog::categories(),'images'=>$images,'pdfs'=>$pdfs,'specs'=>$product?Catalog::specs($id):[],'definitions'=>get_option('mik_parameters',[]),'ozon_url'=>$id?(string)get_post_meta($id,'_mik_ozon_url',true):''];
    }
    private static function save_product(array $p): array {
        global $wpdb;$id=absint($p['id']??0);$name=sanitize_text_field($p['name']??'');$sku=wc_clean($p['sku']??'');$price=str_replace(',','.',(string)($p['price']??''));
        if(!$name||!$sku){throw new \InvalidArgumentException('Заполните название и артикул.');}
        if($price!==''&&(!is_numeric($price)||(float)$price<0)){throw new \InvalidArgumentException('Цена должна быть неотрицательным числом.');}
        if(filter_var($p['stock']??null,FILTER_VALIDATE_INT)===false||(int)$p['stock']<0||(int)$p['stock']>2147483647){throw new \InvalidArgumentException('Укажите целое неотрицательное количество.');}
        $product=$id?wc_get_product($id):new \WC_Product_Simple();if(!$product||!$product->is_type('simple')){throw new \InvalidArgumentException('Нужен простой товар.');}
        if($id&&!current_user_can('edit_post',$id)){throw new \RuntimeException('Нет права редактировать этот товар.');}
        if(!$id&&!current_user_can('edit_products')){throw new \RuntimeException('Нет права создавать товары.');}
        if(($p['status']??'')==='publish'&&!current_user_can('publish_products')){throw new \RuntimeException('Нет права публиковать товары.');}
        $existing=wc_get_product_id_by_sku($sku);if($existing&&(int)$existing!==$id){throw new \InvalidArgumentException('Этот артикул уже используется другим товаром.');}
        $images=[];foreach(array_slice((array)($p['images']??[]),0,30) as $a){$aid=absint(is_array($a)?($a['id']??0):$a);if($aid&&wp_attachment_is_image($aid)){$images[]=$aid;}}
        $pdfs=[];foreach(array_slice((array)($p['pdfs']??[]),0,30) as $a){$aid=absint(is_array($a)?($a['id']??0):$a);if($aid&&get_post_mime_type($aid)==='application/pdf'){$pdfs[]=$aid;}}
        $categories=[];foreach((array)($p['category_ids']??[]) as $term){$term=absint($term);$t=get_term($term,'product_cat');if($t&&!is_wp_error($t)){$categories[]=$term;}}
        $new_id=DB::transaction(function()use($wpdb,$product,$id,$p,$name,$sku,$price,$images,$pdfs,$categories){
            $old=$id?Catalog::stock($id):0;$inv=DB::table('inventory');if($id){$old=(int)$wpdb->get_var($wpdb->prepare("SELECT quantity FROM $inv WHERE product_id=%d FOR UPDATE",$id));}
            $product->set_name($name);$product->set_sku($sku);$product->set_status(($p['status']??'draft')==='publish'?'publish':'draft');$product->set_regular_price($price===''?'':wc_format_decimal($price,4));$product->set_manage_stock(true);$product->set_stock_quantity((int)$p['stock']);$product->set_stock_status((int)$p['stock']>0?'instock':'outofstock');$product->set_backorders('no');$product->set_description(wp_kses_post($p['description']??''));$product->set_short_description(wp_kses_post($p['short_description']??''));$product->set_category_ids($categories);$product->set_image_id($images[0]??0);$product->set_gallery_image_ids(array_slice($images,1));$pid=$product->save();
            DB::require_result($wpdb->replace($inv,['product_id'=>$pid,'quantity'=>(int)$p['stock'],'updated_at'=>DB::now()]));
            update_post_meta($pid,'_mik_ozon_url',esc_url_raw($p['ozon_url']??'',['https']));update_post_meta($pid,'_mik_pdfs',array_values(array_unique($pdfs)));Catalog::set_specs($pid,(array)($p['specs']??[]));
            if((int)$p['stock']!==$old){$wpdb->insert(DB::table('movements'),['product_id'=>$pid,'delta'=>(int)$p['stock']-$old,'reason'=>$id?'Редактирование в MIK Shop':'Новый товар MIK Shop','created_at'=>DB::now()]);}return $pid;
        });
        return ['saved'=>true,'id'=>(int)$new_id];
    }
    public static function upload_media(\WP_REST_Request $r): array {
        if(!current_user_can('upload_files')){throw new \RuntimeException('У аккаунта нет права загружать медиафайлы.');}
        $files=$r->get_file_params();$f=$files['file']??null;if(!$f||(int)$f['error']!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name'])){throw new \InvalidArgumentException('Не удалось получить файл. Выберите изображение или PDF заново.');}
        $allowed=['jpg|jpeg|jpe'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','pdf'=>'application/pdf'];
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/media.php';
        $type=wp_check_filetype_and_ext($f['tmp_name'],$f['name'],$allowed);if(empty($type['ext'])||empty($type['type'])){throw new \InvalidArgumentException('Допустимы JPG, PNG, WebP, GIF и PDF.');}
        $id=media_handle_sideload($f,0,null,['post_title'=>sanitize_text_field(pathinfo($f['name'],PATHINFO_FILENAME))]);if(is_wp_error($id)){throw new \RuntimeException('Не удалось сохранить медиафайл: '.$id->get_error_message());}
        return ['id'=>(int)$id,'name'=>get_the_title($id),'url'=>wp_get_attachment_url($id),'thumbnail'=>wp_attachment_is_image($id)?wp_get_attachment_image_url($id,'medium'):'','mime'=>get_post_mime_type($id)];
    }
    private static function create_pages(): array {
        $codes=['shop'=>['Каталог','mik_catalog'],'cart'=>['Корзина','mik_cart'],'wishlist'=>['Избранное','mik_wishlist'],'account'=>['Личный кабинет','mik_account'],'wholesale'=>['Оптовым покупателям','mik_wholesale'],'purchase'=>['Как купить','mik_purchase_info'],'company'=>['О компании','mik_company_info'],'contacts'=>['Контакты','mik_contacts'],'legal'=>['Правовая информация','mik_legal'],'business'=>['Юридическим лицам','mik_legal_entities'],'payment'=>['Оплата','mik_payment'],'delivery'=>['Доставка','mik_delivery'],'help'=>['Помощь','mik_help']];$list=[];$out=[];
        foreach($codes as $kind=>[$title,$code]){$list[Settings::get($kind.'_slug')]=[$title,'['.$code.']'];}
        foreach($list as $slug=>[$title,$code]){$page=get_page_by_path($slug);if(!$page){$id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$title,'post_name'=>$slug,'post_content'=>$code],true);if(is_wp_error($id)){throw new \RuntimeException($id->get_error_message());}}else{$id=$page->ID;}$out[]=['title'=>$title,'url'=>get_permalink($id),'existing'=>(bool)$page];if($slug===Settings::get('shop_slug')&&!get_option('woocommerce_shop_page_id')){update_option('woocommerce_shop_page_id',$id);}}
        return $out;
    }
}
