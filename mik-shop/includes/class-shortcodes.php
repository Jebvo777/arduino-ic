<?php
namespace MIK;
defined('ABSPATH') || exit;

class Shortcodes {
    public const MAP=[
        'mik_products_carousel'=>'carousel','mik_search'=>'search','mik_wishlist_icon'=>'wishlist-icon','mik_account_icon'=>'account-icon','mik_cart_icon'=>'cart-icon','mik_catalog_button'=>'catalog-button','mik_store_status'=>'store-status','mik_catalog'=>'catalog','mik_product'=>'product','mik_cart'=>'cart','mik_wishlist'=>'wishlist','mik_account'=>'account','mik_wholesale'=>'wholesale','mik_purchase_info'=>'purchase','mik_company_info'=>'company','mik_contacts'=>'contacts','mik_legal'=>'legal','mik_slider'=>'slider',
        'mik_legal_entities'=>'business','mik_how_to_buy'=>'purchase','mik_payment'=>'payment','mik_delivery'=>'delivery','mik_help'=>'help','mik_footer'=>'footer','mik_mobile_menu'=>'mobile-menu','mik_logo'=>'logo','mik_header_categories'=>'header-categories','mik_category_cards'=>'category-cards','mik_bestsellers'=>'bestsellers','mik_new_arrivals'=>'new-arrivals','mik_repair_products'=>'repair-products','mik_product_documents'=>'product-documents',
    ];
    public static function hooks(): void {
        add_filter('get_site_icon_url',fn($url)=>$url?:MIK_URL.'assets/img/store-favicon.png');
        add_action('wp_enqueue_scripts',[self::class,'assets']);
        foreach(self::MAP as $tag=>$type){add_shortcode($tag,fn($atts=[])=>self::render($type,(array)$atts));}
    }
    public static function assets(): void {
        wp_enqueue_style('mik-shop',MIK_URL.'assets/css/store.css',[],MIK_VERSION);
        wp_enqueue_style('mik-layout',MIK_URL.'assets/css/layout.css',['mik-shop'],MIK_VERSION);
        wp_enqueue_style('mik-refinements',MIK_URL.'assets/css/refinements.css',['mik-layout'],MIK_VERSION);
        wp_enqueue_style('mik-storefront-140',MIK_URL.'assets/css/storefront-140.css',['mik-refinements'],MIK_VERSION);
        wp_enqueue_script('mik-shop',MIK_URL.'assets/js/store.js',[],MIK_VERSION,true);
        $s=Settings::all();$style='.mik{';foreach($s['colors'] as $k=>$v){$style.='--mik-'.sanitize_key($k).':'.sanitize_hex_color($v).';';}$style.='--mik-heading-font:"'.esc_attr($s['heading_font']).'",sans-serif;--mik-body-font:"'.esc_attr($s['body_font']).'",sans-serif;}';wp_add_inline_style('mik-shop',$style);
        if($s['load_fonts']){wp_enqueue_style('mik-fonts',MIK_URL.'assets/css/fonts.css',[],MIK_VERSION);}
        wp_localize_script('mik-shop','MIK',['api'=>esc_url_raw(rest_url('mik/v1/')),'document'=>admin_url('admin-post.php?action=mik_document'),'urls'=>['shop'=>Settings::url('shop'),'cart'=>Settings::url('cart'),'wishlist'=>Settings::url('wishlist'),'account'=>Settings::url('account')],'labels'=>$s['labels'],'loader'=>self::loader(),'catalog_title'=>$s['catalog_title'],'store_name'=>$s['store_name'],'placeholder'=>MIK_URL.'assets/img/product-placeholder.webp','carousel'=>$s['carousel'],'pickup'=>$s['pickup'],'pickup_address'=>$s['pickup_address']]);
    }
    public static function render(string $type,array $a): string {
        $outer=$type;$attrs=[];$content='';$intro=(array)(Settings::get('blocks',[])[$type]??[]);
        if(in_array($type,['product','product-documents'],true)){$id=absint($a['id']??get_queried_object_id());if(get_post_type($id)!=='product'){$id=absint($a['id']??0);}$attrs['id']=$id;}
        if($type==='catalog'){
            $o=get_queried_object();$attrs['category']=sanitize_text_field($a['category']??(($o instanceof \WP_Term&&$o->taxonomy==='product_cat')?$o->term_id:''));
            $attrs['view']=($a['view']??'grid')==='list'?'list':'grid';$attrs['limit']=max(6,min(60,(int)($a['per_page']??Settings::get('per_page'))));
        }
        if($type==='carousel'){$attrs=array_merge(Settings::get('carousel'),array_intersect_key($a,array_flip(['title','limit','columns','source','category'])));}
        $preset=['bestsellers'=>'hits','new-arrivals'=>'new','repair-products'=>'repair'][$type]??null;
        if($preset){$attrs=array_merge(Settings::get('carousel'),Settings::get('carousels')[$preset],array_intersect_key($a,array_flip(['title','limit','columns','category'])));$attrs['source']=$preset;$type='carousel';}
        if($type==='catalog'){$attrs['title']=sanitize_text_field($a['title']??Settings::get('catalog_title'));}

        if(in_array($type,['header-categories','category-cards'],true)){
            $section=$type==='header-categories'?'header_categories':'category_cards';$attrs=array_merge(Settings::get($section),array_intersect_key($a,array_flip(['limit','ids','parent','children'])));
            $attrs['ids']=is_array($attrs['ids'])?$attrs['ids']:explode(',',(string)$attrs['ids']);$attrs['ids']=array_values(array_filter(array_map('absint',$attrs['ids'])));if(!$attrs['ids']&&empty($attrs['parent'])){$attrs['ids']=CategoryTree::featured_ids();}
        }
        if($type==='slider'){
            $slides=Settings::get('slides');foreach($slides as &$s){if(!$s['image']){$s['image']=MIK_URL.'assets/img/'.$s['local_image'];}$s['url']=self::link($s['url']);$s['secondary_url']=self::link($s['secondary_url']);}unset($s);$attrs['slides']=$slides;if(Settings::get('hero_cycle')){$attrs['photos']=array_map(fn($p)=>preg_match('~^https?://~i',$p)?$p:MIK_URL.'assets/img/'.sanitize_file_name($p),Settings::get('hero_photos'));}
        }
        if(array_key_exists($type,Layout::page_defaults())){$content=Layout::page($type);$type='static';}
        if($type==='footer'){$content=Layout::footer();$type='static';}
        if($type==='logo'){$content=Layout::brand();$type='static';}
        if($type==='mobile-menu'){$content=Layout::mobile();}
        $prefix=(!empty($intro['title'])?'<h2 class="mik-block-title">'.esc_html($intro['title']).'</h2>':'').(!empty($intro['text'])?'<div class="mik-block-intro">'.wp_kses_post($intro['text']).'</div>':'');
        $page=in_array($type,['catalog','product','product-documents','cart','wishlist','account','category-cards','carousel'],true);
        return '<div class="mik mik-'.esc_attr($outer).'">'.$prefix.'<div data-mik="'.esc_attr($type).'" data-options="'.esc_attr(DB::json($attrs)).'">'.$content.(in_array($type,['static','slider','mobile-menu'],true)?'':($page?self::loader():'<span class="mik-widget-skeleton" aria-hidden="true"></span>')).'</div></div>';
    }
    public static function loader(): string {
        return '<div class="mik-chip-loader" role="status" aria-live="polite"><svg viewBox="0 0 100 100" width="94" height="94" fill="none" aria-hidden="true"><path class="mik-chip-pins" d="M32 10v12m12-12v12m12-12v12m12-12v12M32 78v12m12-12v12m12-12v12m12-12v12M10 32h12M10 44h12M10 56h12M10 68h12m56-36h12M78 44h12M78 56h12M78 68h12"/><rect class="mik-chip-frame" x="22" y="22" width="56" height="56" rx="10"/><rect class="mik-chip-fill" x="28" y="28" width="44" height="44" rx="6"/><path class="mik-chip-traces" d="M37 41h9v18h17M37 53h9m9-17v10h8M55 54v9"/><circle class="mik-chip-light" cx="35" cy="34" r="2.5"/></svg><span>Загружаем компоненты</span><i></i></div>';
    }
    private static function link(string $url): string { return str_starts_with($url,'/')?home_url($url):$url; }
}
