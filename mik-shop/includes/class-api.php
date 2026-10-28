<?php
namespace MIK;
defined('ABSPATH') || exit;

class API {
    public static function hooks(): void {
        add_action('admin_post_mik_category_backup',[CategoryJobs::class,'download']);
        add_action('rest_api_init',[self::class,'routes']);
        foreach(['admin_post_mik_document','admin_post_nopriv_mik_document'] as $hook){add_action($hook,[self::class,'document']);}
    }
    private static function route(string $path,string $methods,callable $fn,string $access='public'): void {
        register_rest_route('mik/v1',$path,['methods'=>$methods,'permission_callback'=>function($r)use($access){
            if($access==='admin'){return current_user_can('manage_woocommerce')&&wp_verify_nonce($r->get_header('x-wp-nonce'),'wp_rest')?true:new \WP_Error('mik_forbidden','Нет прав администратора.',['status'=>403]);}
            if($access==='write'){return Auth::csrf($r)?true:new \WP_Error('mik_csrf','Сессия устарела. Обновите страницу.',['status'=>403]);}return true;
        },'callback'=>function($r)use($fn){
            try{$data=$fn($r);$response=new \WP_REST_Response($data,200);}catch(\InvalidArgumentException $e){$response=new \WP_REST_Response(['message'=>$e->getMessage()],422);}catch(\Throwable $e){$response=new \WP_REST_Response(['message'=>$e instanceof \RuntimeException?$e->getMessage():'Не удалось выполнить действие. Повторите позже.'],400);}
            $response->header('Cache-Control','no-store, no-cache, must-revalidate, private, max-age=0');$response->header('Vary','Cookie');return $response;
        }]);
    }
    private static function payload($r): array { return (array)($r->get_json_params()?:$r->get_params()); }
    public static function state(): array {
        $s=Auth::session();$c=Auth::customer();return ['csrf'=>$s['csrf'],'customer'=>Auth::public_customer($c),'group'=>$c?Catalog::group():null,'cart'=>Commerce::lists('cart'),'wishlist'=>Commerce::lists('wishlist'),'hours'=>Settings::hours(),'pickup'=>Settings::get('pickup'),'pickup_address'=>Settings::get('pickup_address')];
    }
    public static function routes(): void {
        self::route('/session','GET',fn()=>self::state());
        self::route('/hours','GET',fn()=>Settings::hours());
        self::route('/categories','GET',fn()=>Catalog::categories());
        self::route('/filters','GET',fn()=>Catalog::filters());
        self::route('/products','GET',fn($r)=>Catalog::listing($r->get_params()));
        self::route('/products/(?P<id>\d+)','GET',function($r){$p=Catalog::product((int)$r['id'],true);if(!$p){throw new \InvalidArgumentException('Товар не найден.');}return $p;});
        self::route('/auth/register','POST',function($r){Auth::register(self::payload($r));return self::state();},'write');
        self::route('/auth/login','POST',function($r){Auth::login(self::payload($r));return self::state();},'write');
        self::route('/auth/logout','POST',function(){Auth::logout();return self::state();},'write');
        self::route('/auth/reset-request','POST',function($r){Auth::reset_request((string)$r->get_param('email'));return ['message'=>'Если адрес зарегистрирован, на него отправлена ссылка восстановления.'];},'write');
        self::route('/auth/reset-password','POST',function($r){Auth::reset_password(self::payload($r));return ['message'=>'Пароль изменён. Войдите с новым паролем.'];},'write');
        self::route('/profile','POST',function($r){Auth::profile(self::payload($r));return self::state();},'write');
        self::route('/profile/permanent','POST',function(){Auth::permanent();return self::state();},'write');
        foreach(['cart','wishlist'] as $kind){
            self::route('/'.$kind,'GET',fn()=>Commerce::lists($kind));
            self::route('/'.$kind,'POST',fn($r)=>Commerce::update_list($kind,self::payload($r)),'write');
        }
        self::route('/cart/import','POST',function($r){
            $rows=(array)$r->get_param('rows');if(count($rows)>500){throw new \InvalidArgumentException('В одном файле допускается до 500 позиций.');}$errors=[];$added=0;
            foreach($rows as $row){try{$sku=sanitize_text_field($row['sku']??'');$id=wc_get_product_id_by_sku($sku);if(!$id){throw new \InvalidArgumentException('Артикул не найден.');}Commerce::update_list('cart',['product_id'=>$id,'qty'=>$row['qty']??0,'op'=>'set']);$added++;}catch(\Throwable $e){$errors[]=['sku'=>$sku??'','message'=>$e->getMessage()];}}
            return ['added'=>$added,'errors'=>$errors];
        },'write');
        self::route('/orders','GET',fn()=>Commerce::orders());
        self::route('/orders','POST',fn($r)=>Commerce::checkout(self::payload($r)),'write');
        self::route('/orders/(?P<id>\d+)','GET',fn($r)=>Commerce::order((int)$r['id']));
        self::route('/categories-job/(?P<action>[a-z_-]+)','POST',fn($r)=>CategoryJobs::dispatch(sanitize_key($r['action']),self::payload($r)),'admin');
        self::route('/admin/media/upload','POST',fn($r)=>Admin::upload_media($r),'admin');
        self::route('/admin/(?P<tab>[a-z_-]+)','GET',fn($r)=>Admin::data(sanitize_key($r['tab']),$r->get_params()),'admin');
        self::route('/admin/(?P<tab>[a-z_-]+)','POST',fn($r)=>Admin::save(sanitize_key($r['tab']),self::payload($r)),'admin');
        self::route('/import/(?P<action>[a-z_-]+)','POST',fn($r)=>Importer::dispatch(sanitize_key($r['action']),$r),'admin');
        self::route('/import/status','GET',fn($r)=>Importer::status(absint($r->get_param('id'))),'admin');
    }
    public static function document(): void {
        nocache_headers();header('X-Content-Type-Options: nosniff');
        try {
            $kind=sanitize_key($_GET['kind']??'');
            if($kind==='price'){
                if(!Auth::customer()){throw new \RuntimeException('Войдите в личный кабинет.');}
                header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="mik-price.csv"');
                $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Артикул','Наименование','Наличие','Цена','Валюта','Количество'],';');
                global $wpdb;$g=Catalog::group();$price=(!empty($g['fallback_base'])&&$g['currency']==='RUB')?'COALESCE(gp.price,m.min_price)':'gp.price';$offset=0;
                do {
                    $rows=$wpdb->get_results($wpdb->prepare("SELECT p.post_title,m.sku,m.stock_quantity,$price AS price FROM {$wpdb->posts} p INNER JOIN {$wpdb->wc_product_meta_lookup} m ON m.product_id=p.ID LEFT JOIN ".DB::table('prices')." gp ON gp.product_id=p.ID AND gp.group_id=%d WHERE p.post_type='product' AND p.post_status='publish' ORDER BY p.ID LIMIT 500 OFFSET %d",$g['id'],$offset),ARRAY_A);
                    foreach($rows as $row){$safe=fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:$v;fputcsv($out,[$safe($row['sku']),$safe($row['post_title']),$row['stock_quantity'],$row['price'],$g['currency'],''],';');}$offset+=500;
                }while(count($rows)===500);fclose($out);exit;
            }
            if($kind==='packager'){
                if(!current_user_can('manage_woocommerce')||!check_admin_referer('mik_packager')){throw new \RuntimeException('Нет доступа.');}
                $bat=Importer::bat(absint($_GET['id']??0));header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="mik-build-packages.bat"');echo $bat;exit;
            }
            $o=Commerce::order(absint($_GET['id']??0),current_user_can('manage_woocommerce'));
            if($kind==='xls'){header('Content-Type: application/vnd.ms-excel');header('Content-Disposition: attachment; filename="mik-order-'.$o['id'].'.xls"');echo Documents::xls($o);exit;}
            if($kind==='invoice'&&$o['invoice_number']){header('Content-Type: text/html; charset=utf-8');echo Documents::invoice_html($o);exit;}
            throw new \InvalidArgumentException('Документ пока не сформирован.');
        } catch(\Throwable $e){wp_die(esc_html($e->getMessage()),'MIK Shop',['response'=>403]);}
    }
}
