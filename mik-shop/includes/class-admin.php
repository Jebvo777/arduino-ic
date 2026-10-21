<?php
namespace MIK;
defined('ABSPATH') || exit;

class Admin {
    public static function hooks(): void {
        add_action('admin_menu',function(){add_menu_page('MIK Shop','MIK Shop','manage_woocommerce','mik-shop',[self::class,'page'],'dashicons-store',56);});
        add_action('admin_enqueue_scripts',function($hook){
            $screen=get_current_screen();if($hook!=='toplevel_page_mik-shop'&&($screen->post_type??'')!=='product'){return;}
            wp_enqueue_media();wp_enqueue_style('mik-admin',MIK_URL.'assets/css/admin.css',[],MIK_VERSION);wp_enqueue_script('mik-admin',MIK_URL.'assets/js/admin.js',[],MIK_VERSION,true);
            wp_localize_script('mik-admin','MIK_ADMIN',['api'=>esc_url_raw(rest_url('mik/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'document'=>admin_url('admin-post.php?action=mik_document'),'edit_product'=>admin_url('post.php?action=edit&post='),'new_product'=>admin_url('post-new.php?post_type=product'),'chunk_size'=>max(65536,min(1048576,wp_max_upload_size()-65536))]);
        });
    }
    public static function page(): void {
        if(!current_user_can('manage_woocommerce')){return;}
        $tabs=['dashboard'=>'Обзор','orders'=>'Заказы','products'=>'Товары','stocks'=>'Остатки','accounts'=>'Аккаунты','groups'=>'Группы и цены','invoices'=>'Счета','parameters'=>'Параметры','import'=>'Импорт','content'=>'Контент','shortcodes'=>'Шорткоды','settings'=>'Настройки'];
        $active=sanitize_key($_GET['tab']??'dashboard');if(!isset($tabs[$active])){$active='dashboard';}
        echo '<div class="wrap mik-admin"><h1>MIK Shop</h1><nav class="nav-tab-wrapper">';foreach($tabs as $k=>$label){echo '<a class="nav-tab '.($active===$k?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=mik-shop&tab='.$k)).'">'.esc_html($label).'</a>';}
        echo '</nav><div id="mik-admin-app" data-tab="'.esc_attr($active).'"><p>Загрузка…</p></div></div>';
    }
    public static function data(string $tab,array $p=[]): array {
        global $wpdb;$page=max(1,(int)($p['page']??1));$offset=($page-1)*40;$q=sanitize_text_field($p['q']??'');$like='%'.$wpdb->esc_like($q).'%';
        if($tab==='dashboard'){return ['customers'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('customers')),'orders'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('orders')),'pending'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM ".DB::table('orders')." WHERE status='pending'"),'products'=>(int)wp_count_posts('product')->publish,'zip'=>class_exists('ZipArchive'),'php'=>PHP_VERSION,'upload_limit'=>wp_max_upload_size()];}
        if(in_array($tab,['orders','invoices'],true)){
            $where=$tab==='invoices'?" WHERE invoice_number<>''":" WHERE 1=1";if($q){$where.=$wpdb->prepare(' AND (id=%d OR customer_json LIKE %s)',(int)$q,$like);}
            $rows=$wpdb->get_results('SELECT id,status,currency,total,customer_json,delivery,payment,invoice_number,invoice_date,mail_status,created_at FROM '.DB::table('orders').$where.' ORDER BY id DESC LIMIT 40 OFFSET '.(int)$offset,ARRAY_A);foreach($rows as &$r){$r['customer']=json_decode($r['customer_json'],true);unset($r['customer_json']);}unset($r);return ['rows'=>$rows,'page'=>$page,'total'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('orders').$where)];
        }
        if(in_array($tab,['products','stocks'],true)){
            $where=" WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')";if($q){$where.=$wpdb->prepare(' AND (p.post_title LIKE %s OR m.sku LIKE %s)',$like,$like);}
            $from=" FROM {$wpdb->posts} p LEFT JOIN {$wpdb->wc_product_meta_lookup} m ON m.product_id=p.ID LEFT JOIN ".DB::table('inventory').' i ON i.product_id=p.ID';
            $rows=$wpdb->get_results('SELECT p.ID AS id,p.post_title AS name,m.sku,m.min_price AS price,COALESCE(i.quantity,m.stock_quantity,0) AS stock'.$from.$where.' ORDER BY p.ID DESC LIMIT 40 OFFSET '.(int)$offset,ARRAY_A);return ['rows'=>$rows,'page'=>$page,'total'=>(int)$wpdb->get_var('SELECT COUNT(*)'.$from.$where)];
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
    private static function create_pages(): array {
        $list=[Settings::get('shop_slug')=>['Каталог','[mik_catalog]'],Settings::get('cart_slug')=>['Корзина','[mik_cart]'],Settings::get('wishlist_slug')=>['Избранное','[mik_wishlist]'],Settings::get('account_slug')=>['Личный кабинет','[mik_account]'],'wholesale-mik'=>['Оптовым покупателям','[mik_wholesale]'],'purchase-mik'=>['Информация о покупке','[mik_purchase_info]'],'company-mik'=>['Информация о компании','[mik_company_info]'],'contacts-mik'=>['Контакты','[mik_contacts]'],'legal-mik'=>['Правовая информация','[mik_legal]']];$out=[];
        foreach($list as $slug=>[$title,$code]){$page=get_page_by_path($slug);if(!$page){$id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$title,'post_name'=>$slug,'post_content'=>$code],true);if(is_wp_error($id)){throw new \RuntimeException($id->get_error_message());}}else{$id=$page->ID;}$out[]=['title'=>$title,'url'=>get_permalink($id),'existing'=>(bool)$page];if($slug===Settings::get('shop_slug')&&!get_option('woocommerce_shop_page_id')){update_option('woocommerce_shop_page_id',$id);}}
        return $out;
    }
}
