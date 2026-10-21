<?php
namespace MIK;
defined('ABSPATH') || exit;

class Shortcodes {
    public const MAP=[
        'mik_products_carousel'=>'carousel','mik_search'=>'search','mik_wishlist_icon'=>'wishlist-icon','mik_account_icon'=>'account-icon','mik_cart_icon'=>'cart-icon','mik_catalog_button'=>'catalog-button','mik_store_status'=>'store-status','mik_catalog'=>'catalog','mik_product'=>'product','mik_cart'=>'cart','mik_wishlist'=>'wishlist','mik_account'=>'account','mik_wholesale'=>'wholesale','mik_purchase_info'=>'purchase','mik_company_info'=>'company','mik_contacts'=>'contacts','mik_legal'=>'legal','mik_slider'=>'slider',
    ];
    public static function hooks(): void {
        add_action('wp_enqueue_scripts',[self::class,'assets']);
        foreach(self::MAP as $tag=>$type){add_shortcode($tag,fn($atts=[])=>self::render($type,(array)$atts));}
    }
    public static function assets(): void {
        wp_enqueue_style('mik-shop',MIK_URL.'assets/css/store.css',[],MIK_VERSION);
        wp_enqueue_script('mik-shop',MIK_URL.'assets/js/store.js',[],MIK_VERSION,true);
        $s=Settings::all();$style='.mik{';foreach($s['colors'] as $k=>$v){$style.='--mik-'.sanitize_key($k).':'.sanitize_hex_color($v).';';}$style.='--mik-heading-font:"'.esc_attr($s['heading_font']).'",sans-serif;--mik-body-font:"'.esc_attr($s['body_font']).'",sans-serif;}';wp_add_inline_style('mik-shop',$style);
        if($s['load_fonts']){wp_enqueue_style('mik-fonts',MIK_URL.'assets/css/fonts.css',[],MIK_VERSION);}
        wp_localize_script('mik-shop','MIK',['api'=>esc_url_raw(rest_url('mik/v1/')),'document'=>admin_url('admin-post.php?action=mik_document'),'urls'=>['shop'=>Settings::url('shop'),'cart'=>Settings::url('cart'),'wishlist'=>Settings::url('wishlist'),'account'=>Settings::url('account')],'labels'=>$s['labels'],'carousel'=>$s['carousel'],'pickup'=>$s['pickup'],'pickup_address'=>$s['pickup_address']]);
    }
    public static function render(string $type,array $a): string {
        $attrs=[];$content='';$intro=(array)(Settings::get('blocks',[])[$type]??[]);
        if($type==='product'){$id=absint($a['id']??get_queried_object_id());if(get_post_type($id)!=='product'){$id=absint($a['id']??0);}$attrs['id']=$id;}
        if($type==='catalog'){
            $o=get_queried_object();$attrs['category']=sanitize_text_field($a['category']??(($o instanceof \WP_Term&&$o->taxonomy==='product_cat')?$o->term_id:''));
            $attrs['view']=($a['view']??'grid')==='list'?'list':'grid';$attrs['limit']=max(6,min(60,(int)($a['per_page']??Settings::get('per_page'))));
        }
        if($type==='carousel'){$attrs=array_merge(Settings::get('carousel'),array_intersect_key($a,array_flip(['title','limit','columns','source','category'])));}
        if($type==='slider'){
            $slides=Settings::get('slides');foreach($slides as &$s){if(!$s['image']){$s['image']=MIK_URL.'assets/img/'.$s['local_image'];}$s['url']=self::link($s['url']);$s['secondary_url']=self::link($s['secondary_url']);}unset($s);$attrs['slides']=$slides;
        }
        if(in_array($type,['wholesale','purchase','company','contacts','legal'],true)){
            $p=Settings::get('pages')[$type];$content='<article class="mik-prose"><h1>'.esc_html($p['title']).'</h1>'.wp_kses_post($p['content']);
            if($type==='contacts'){$content.='<div class="mik-contact-grid"><div data-mik="store-status"></div><p><a href="mailto:'.esc_attr(Settings::get('email')).'">'.esc_html(Settings::get('email')).'</a></p>';if(Settings::get('telegram')){$content.='<p><a href="'.esc_url(Settings::get('telegram')).'" target="_blank" rel="noopener">Telegram</a></p>';}$content.='</div>';}
            if($type==='wholesale'){$content.='<p><a class="mik-btn" href="'.esc_url(Settings::url('account')).'">Войти или зарегистрироваться</a></p>';}
            $content.='</article>';$type='static';
        }
        $prefix=(!empty($intro['title'])?'<h2 class="mik-block-title">'.esc_html($intro['title']).'</h2>':'').(!empty($intro['text'])?'<div class="mik-block-intro">'.wp_kses_post($intro['text']).'</div>':'');
        return '<div class="mik mik-'.esc_attr($type).'">'.$prefix.'<div data-mik="'.esc_attr($type).'" data-options="'.esc_attr(DB::json($attrs)).'">'.$content.(in_array($type,['static','slider'],true)?'':'<span class="mik-loading" role="status">Загрузка…</span>').'</div></div>';
    }
    private static function link(string $url): string { return str_starts_with($url,'/')?home_url($url):$url; }
}
