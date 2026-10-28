<?php
namespace MIK;
defined('ABSPATH') || exit;

class Catalog {
    public static bool $syncing=false;
    public static function hooks(): void {
        add_action('woocommerce_new_product',[self::class,'sync_stock']);
        add_action('woocommerce_update_product',[self::class,'sync_stock']);
        add_action('woocommerce_product_set_stock',function($p){self::sync_stock($p->get_id());});
        add_action('add_meta_boxes',function(){add_meta_box('mik-product','MIK Shop — параметры и ссылки',[self::class,'metabox'],'product','normal');});
        add_action('woocommerce_process_product_meta',[self::class,'save_meta']);
        foreach(['created_product_cat','edited_product_cat','delete_product_cat'] as $hook){add_action($hook,[self::class,'invalidate_categories']);}
        add_action('set_object_terms',function($id,$terms,$tt_ids,$taxonomy){if($taxonomy==='product_cat'){self::invalidate_categories();}},10,4);
        // Group prices are private. Standard WooCommerce exports/endpoints expose the public base price only.
    }
    public static function group(): array {
        global $wpdb;$c=Auth::customer();$id=$c?(int)$c['group_id']:1;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('groups').' WHERE id=%d',$id),ARRAY_A)?:['id'=>0,'name'=>'Группа не назначена','currency'=>'RUB','fallback_base'=>0];
    }
    public static function price(int $id,?array $group=null): ?array {
        global $wpdb;$g=$group??self::group();
        if(!Auth::customer()&&!Settings::get('guest_prices')){return null;}
        $value=$wpdb->get_var($wpdb->prepare('SELECT price FROM '.DB::table('prices').' WHERE product_id=%d AND group_id=%d',$id,$g['id']));
        if($value===null&&!empty($g['fallback_base'])&&$g['currency']==='RUB'){$p=wc_get_product($id);$value=$p?$p->get_price():null;}
        return $value!==null&&$value!==''?['value'=>(float)$value,'currency'=>$g['currency'],'group'=>$g['name']]:null;
    }
    public static function sync_stock($id): void {
        if(self::$syncing){return;}global $wpdb;$p=wc_get_product($id);if(!$p||!$p->is_type('simple')){return;}
        $qty=$p->get_stock_quantity();$qty=$qty===null?0:max(0,(int)$qty);
        $wpdb->query($wpdb->prepare('INSERT INTO '.DB::table('inventory').' (product_id,quantity,updated_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),updated_at=VALUES(updated_at)',$id,$qty,DB::now()));
    }
    public static function stock(int $id): int {
        global $wpdb;$q=$wpdb->get_var($wpdb->prepare('SELECT quantity FROM '.DB::table('inventory').' WHERE product_id=%d',$id));
        if($q===null){self::sync_stock($id);$q=$wpdb->get_var($wpdb->prepare('SELECT quantity FROM '.DB::table('inventory').' WHERE product_id=%d',$id));}
        return max(0,(int)$q);
    }
    public static function write_wc_stock(int $id,int $qty): void {
        self::$syncing=true;
        try {wc_update_product_stock($id,$qty,'set');$p=wc_get_product($id);if($p){$p->set_stock_status($qty>0?'instock':'outofstock');$p->save();}} finally {self::$syncing=false;}
    }
    public static function specs(int $id): array {
        global $wpdb;return $wpdb->get_results($wpdb->prepare('SELECT param_key,label,value,unit FROM '.DB::table('specs').' WHERE product_id=%d ORDER BY sort,param_key',$id),ARRAY_A)?:[];
    }
    public static function set_specs(int $id,array $specs): void {
        global $wpdb;$wpdb->delete(DB::table('specs'),['product_id'=>$id]);$i=0;$defs=(array)get_option('mik_parameters',[]);$known=array_column($defs,'key');$changed=false;
        foreach(array_slice($specs,0,80) as $s){
            $label=mb_substr(sanitize_text_field($s['label']??''),0,190);$val=sanitize_text_field($s['value']??'');if($label===''||$val===''){continue;}
            $key=sanitize_key($s['param_key']??'');if(!$key){$key='p_'.substr(md5($label),0,16);}
            $wpdb->replace(DB::table('specs'),['product_id'=>$id,'param_key'=>$key,'label'=>$label,'value'=>mb_substr($val,0,190),'unit'=>mb_substr(sanitize_text_field($s['unit']??''),0,30),'sort'=>$i++]);
            if(!in_array($key,$known,true)&&count($defs)<200){$defs[]=['key'=>$key,'label'=>$label,'unit'=>mb_substr(sanitize_text_field($s['unit']??''),0,30)];$known[]=$key;$changed=true;}
        }
        if($changed){update_option('mik_parameters',$defs,false);}
    }
    public static function product(int $id,bool $detail=false): ?array {
        $p=wc_get_product($id);if(!$p||$p->get_status()!=='publish'||!$p->is_type('simple')){return null;}
        $terms=wp_get_post_terms($id,'product_cat',['fields'=>'names']);$specs=self::specs($id);
        $images=[];foreach(array_unique(array_filter(array_merge([$p->get_image_id()],$p->get_gallery_image_ids()))) as $image_id){$url=wp_get_attachment_image_url($image_id,'large');if($url){$images[]=['url'=>$url,'alt'=>get_post_meta($image_id,'_wp_attachment_image_alt',true)?:$p->get_name()];}}
        $ozon=esc_url_raw((string)get_post_meta($id,'_mik_ozon_url',true));if(!$ozon){$ozon='https://www.ozon.ru/search/?text='.rawurlencode($p->get_name());}
        $r=['id'=>$id,'name'=>$p->get_name(),'sku'=>$p->get_sku(),'url'=>get_permalink($id),'image'=>$images[0]['url']??wc_placeholder_img_src('woocommerce_single'),'category'=>is_wp_error($terms)?'':implode(' · ',$terms),'price'=>self::price($id),'stock'=>self::stock($id),'specs'=>array_slice($specs,0,$detail?80:4),'ozon'=>$ozon,'short_description'=>wp_strip_all_tags($p->get_short_description())];
        if($detail){
            $pdfs=[];foreach((array)get_post_meta($id,'_mik_pdfs',true) as $pdf){$pdf_id=(int)$pdf;$url=wp_get_attachment_url($pdf_id);if($url){$pdfs[]=['name'=>get_the_title($pdf_id),'url'=>$url];}}
            $r+=['images'=>$images,'description'=>wp_kses_post($p->get_description()),'pdfs'=>$pdfs,'related'=>array_values(array_filter(array_map(fn($i)=>self::product((int)$i),wc_get_related_products($id,4))))];
        }
        return $r;
    }
    public static function listing(array $p): array {
        global $wpdb;$g=self::group();$page=max(1,(int)($p['page']??1));$limit=max(1,min(60,(int)($p['limit']??Settings::get('per_page'))));
        $where=["p.post_type='product'","p.post_status='publish'","EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->terms} tm ON tm.term_id=tt.term_id WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' AND tm.slug='simple')"];
        $joins=" LEFT JOIN {$wpdb->wc_product_meta_lookup} m ON m.product_id=p.ID LEFT JOIN ".DB::table('prices').' gp ON gp.product_id=p.ID AND gp.group_id='.(int)$g['id'];
        $price=(!empty($g['fallback_base'])&&$g['currency']==='RUB')?'COALESCE(gp.price,m.min_price)':'gp.price';
        if(!Auth::customer()&&!Settings::get('guest_prices')){$price='NULL';}
        $query=mb_substr(sanitize_text_field($p['q']??''),0,100);
        if($query!==''){$like='%'.$wpdb->esc_like($query).'%';$where[]=$wpdb->prepare('(p.post_title LIKE %s OR m.sku LIKE %s OR EXISTS (SELECT 1 FROM '.$wpdb->postmeta.' alt WHERE alt.post_id=p.ID AND alt.meta_key=%s AND alt.meta_value LIKE %s))',$like,$like,'_mik_search_aliases',$like);}
        if(!empty($p['cat'])){
            $term=is_numeric($p['cat'])?get_term((int)$p['cat'],'product_cat'):get_term_by('slug',sanitize_title($p['cat']),'product_cat');
            if($term&&!is_wp_error($term)){$ids=array_merge([(int)$term->term_id],array_map('intval',(array)get_term_children($term->term_id,'product_cat')));$where[]='p.ID IN (SELECT tr.object_id FROM '.$wpdb->term_relationships.' tr INNER JOIN '.$wpdb->term_taxonomy.' tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy=\'product_cat\' AND tt.term_id IN ('.implode(',',$ids).'))';}else{$where[]='1=0';}
        }
        if(!empty($p['stock'])){$where[]="m.stock_status='instock'";}
        foreach(['min'=>'>=','max'=>'<='] as $k=>$op){if(isset($p[$k])&&$p[$k]!==''&&is_numeric($p[$k])){$where[]=$wpdb->prepare("$price $op %f",max(0,(float)$p[$k]));}}
        if(!empty($p['param'])&&isset($p['value'])&&$p['value']!==''){$where[]=$wpdb->prepare('EXISTS (SELECT 1 FROM '.DB::table('specs').' s WHERE s.product_id=p.ID AND s.param_key=%s AND s.value=%s)',sanitize_key($p['param']),sanitize_text_field($p['value']));}
        if(($p['source']??'')==='featured'){$where[]="EXISTS (SELECT 1 FROM {$wpdb->term_relationships} fr JOIN {$wpdb->term_taxonomy} ft ON ft.term_taxonomy_id=fr.term_taxonomy_id JOIN {$wpdb->terms} fn ON fn.term_id=ft.term_id WHERE fr.object_id=p.ID AND ft.taxonomy='product_visibility' AND fn.slug='featured')";}
        $orders=['price_asc'=>"$price IS NULL ASC,$price ASC,p.ID DESC",'price_desc'=>"$price IS NULL ASC,$price DESC,p.ID DESC",'name'=>'p.post_title ASC','new'=>'p.post_date DESC,p.ID DESC'];$order=$orders[$p['sort']??'new']??$orders['new'];
        $sql=' FROM '.$wpdb->posts.' p '.$joins.' WHERE '.implode(' AND ',$where);
        $total=(int)$wpdb->get_var('SELECT COUNT(DISTINCT p.ID)'.$sql);
        $ids=$wpdb->get_col('SELECT p.ID'.$sql.' ORDER BY '.$order.' LIMIT '.(int)$limit.' OFFSET '.(($page-1)*$limit));
        return ['items'=>array_values(array_filter(array_map(fn($id)=>self::product((int)$id),$ids))),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit),'group'=>Auth::customer()?$g['name']:null,'currency'=>$g['currency']];
    }
    public static function invalidate_categories(): void { delete_transient('mik_categories_v130'); }
    public static function categories(): array {
        $cached=get_transient('mik_categories_v130');if(is_array($cached)){return $cached;}
        $terms=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'number'=>0,'orderby'=>'name','order'=>'ASC']);if(is_wp_error($terms)){return [];}
        $terms=array_values(array_filter($terms,fn($t)=>!get_term_meta($t->term_id,'_mik_archived',true)));$by=[];$images=[];
        foreach($terms as $t){$by[(int)$t->term_id]=$t;$own=(int)get_term_meta($t->term_id,'thumbnail_id',true);if($own&&wp_attachment_is_image($own)){$images[(int)$t->term_id]=$own;}}
        global $wpdb;$fallback=$wpdb->get_results("SELECT tt.term_id,MIN(CAST(pm.meta_value AS UNSIGNED)) AS image FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id=tt.term_taxonomy_id JOIN {$wpdb->posts} p ON p.ID=tr.object_id AND p.post_type='product' AND p.post_status='publish' JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_thumbnail_id' AND CAST(pm.meta_value AS UNSIGNED)>0 JOIN {$wpdb->posts} a ON a.ID=CAST(pm.meta_value AS UNSIGNED) AND a.post_type='attachment' AND a.post_mime_type LIKE 'image/%' LEFT JOIN {$wpdb->postmeta} f ON f.post_id=a.ID AND f.meta_key='_wp_attached_file' WHERE tt.taxonomy='product_cat' AND (f.meta_value IS NULL OR f.meta_value NOT LIKE '%woocommerce-placeholder%') GROUP BY tt.term_id",ARRAY_A);
        foreach($fallback as $r){if(isset($by[(int)$r['term_id']])&&!isset($images[(int)$r['term_id']])){$images[(int)$r['term_id']]=(int)$r['image'];}}
        foreach($terms as $t){$image=$images[(int)$t->term_id]??0;$parent=(int)$t->parent;$seen=[];while($image&&$parent&&isset($by[$parent])&&!isset($seen[$parent])){$seen[$parent]=true;if(!isset($images[$parent]))$images[$parent]=$image;$parent=(int)$by[$parent]->parent;}}
        $image_ids=array_values(array_unique($images));if($image_ids){get_posts(['post_type'=>'attachment','post_status'=>'inherit','post__in'=>$image_ids,'posts_per_page'=>-1,'update_post_meta_cache'=>true,'update_post_term_cache'=>false]);}
        $out=[];foreach($terms as $t){$url=get_term_link($t);$image=$images[(int)$t->term_id]??0;$out[]=['id'=>(int)$t->term_id,'parent'=>(int)$t->parent,'name'=>$t->name,'slug'=>$t->slug,'count'=>(int)$t->count,'url'=>is_wp_error($url)?Settings::url('shop'):$url,'image'=>$image?(wp_get_attachment_image_url($image,'medium')?:''):'','key'=>(string)get_term_meta($t->term_id,'_mik_tree_key',true),'order'=>(int)get_term_meta($t->term_id,'_mik_order',true)];}
        usort($out,fn($a,$b)=>(($a['order']?:999999)<=>($b['order']?:999999))?:strnatcasecmp($a['name'],$b['name']));$children=[];$known=array_column($out,'id');foreach($out as $c){$parent=in_array($c['parent'],$known,true)?$c['parent']:0;$children[$parent][]=$c;}$ordered=[];$seen=[];$walk=function($parent)use(&$walk,&$ordered,&$seen,$children){foreach($children[$parent]??[] as $c){if(isset($seen[$c['id']]))continue;$seen[$c['id']]=true;$ordered[]=$c;$walk($c['id']);}};$walk(0);foreach($out as $c){if(!isset($seen[$c['id']]))$ordered[]=$c;}set_transient('mik_categories_v130',$ordered,600);return $ordered;
    }
    public static function filters(): array {
        global $wpdb;$rows=$wpdb->get_results('SELECT param_key,label,value,unit FROM '.DB::table('specs').' GROUP BY param_key,label,value,unit ORDER BY label,value LIMIT 600',ARRAY_A);$out=[];
        foreach($rows as $r){$key=$r['param_key'];if(!isset($out[$key])){$out[$key]=['key'=>$key,'label'=>$r['label'],'unit'=>$r['unit'],'values'=>[]];}if(count($out[$key]['values'])<60){$out[$key]['values'][]=$r['value'];}}
        return array_values($out);
    }
    public static function metabox($post): void {
        wp_nonce_field('mik_product','mik_product_nonce');
        echo '<p><label>Прямая ссылка Ozon <input style="width:100%" name="mik_ozon" type="url" value="'.esc_attr(get_post_meta($post->ID,'_mik_ozon_url',true)).'"></label></p>';
        echo '<p><label>ID PDF документов (через запятую) <input style="width:100%" name="mik_pdfs" value="'.esc_attr(implode(',',(array)get_post_meta($post->ID,'_mik_pdfs',true))).'"></label></p>';
        echo '<p>Справочник параметров задаётся в MIK Shop → Параметры. Выберите параметр и заполните значение.</p><div id="mik-spec-editor" data-defs="'.esc_attr(DB::json(get_option('mik_parameters',[]))).'" data-specs="'.esc_attr(DB::json(self::specs($post->ID))).'"></div><input type="hidden" name="mik_specs" id="mik-specs-json" value="'.esc_attr(DB::json(self::specs($post->ID))).'">';
    }
    public static function save_meta($id): void {
        if(!isset($_POST['mik_product_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mik_product_nonce'])),'mik_product')||!current_user_can('edit_post',$id)){return;}
        update_post_meta($id,'_mik_ozon_url',esc_url_raw(wp_unslash($_POST['mik_ozon']??'')));
        update_post_meta($id,'_mik_pdfs',array_values(array_filter(array_map('absint',explode(',',sanitize_text_field(wp_unslash($_POST['mik_pdfs']??'')))))));
        $specs=json_decode(wp_unslash($_POST['mik_specs']??'[]'),true);if(is_array($specs)){self::set_specs($id,$specs);}
    }
}
