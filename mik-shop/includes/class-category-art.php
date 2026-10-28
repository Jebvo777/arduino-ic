<?php
namespace MIK;
defined('ABSPATH') || exit;

/** Resolve the background images of the eight Elementor blocks supplied in the design HTML. */
class CategoryArt {
    public const NODES=['ic'=>'3f2a0ef','semiconductors'=>'183cf5d','diodes'=>'a7abf3e','microcontrollers'=>'aecc11a','passive'=>'de2d6bf','power'=>'fca296f','connectors'=>'136d09c','protection'=>'5afd042'];
    public static function images(): array {
        $manual=array_filter((array)Settings::get('category_images',[]));$found=get_transient('mik_category_art_v140');
        if(!is_array($found)){
            global $wpdb;$conditions=[];foreach(self::NODES as $id){$conditions[]=$wpdb->prepare('meta_value LIKE %s','%'.$wpdb->esc_like($id).'%');}
            $rows=$wpdb->get_results("SELECT post_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data' AND (".implode(' OR ',$conditions).') ORDER BY meta_id DESC LIMIT 15',ARRAY_A);$found=[];$wanted=array_flip(self::NODES);
            $walk=function($nodes)use(&$walk,&$found,$wanted){foreach((array)$nodes as $node){if(!is_array($node))continue;$key=$wanted[$node['id']??'']??null;if($key&&!isset($found[$key])){$settings=(array)($node['settings']??[]);foreach(['background_image','background_overlay_image'] as $property){$image=$settings[$property]??[];$url=is_array($image)?($image['url']??''):'';if(!$url&&is_array($image)&&!empty($image['id']))$url=wp_get_attachment_url((int)$image['id']);if($url){$found[$key]=esc_url_raw($url);break;}}}if(!empty($node['elements']))$walk($node['elements']);}};
            $upload=wp_upload_dir();foreach($rows as $row){$nodes=json_decode($row['meta_value'],true);if(!is_array($nodes))$nodes=json_decode(wp_unslash($row['meta_value']),true);if(is_array($nodes))$walk($nodes);
                // Background URLs can also live in Elementor's generated CSS, rather than in the copied HTML.
                $file=$upload['basedir'].'/elementor/css/post-'.(int)$row['post_id'].'.css';if(is_readable($file)){$css=file_get_contents($file);foreach(self::NODES as $key=>$id){if(!isset($found[$key])&&preg_match('~\.elementor-element-'.preg_quote($id,'~').'[^{}]*\{[^{}]*background-image\s*:\s*url\([\x22\x27]?([^\x22\x27)]+)~i',$css,$match)){$url=trim($match[1]);if(str_starts_with($url,'//'))$url=(is_ssl()?'https:':'http:').$url;if(str_starts_with($url,'/'))$url=preg_replace('~^(https?://[^/]+).*$~','$1',home_url()).$url;$found[$key]=esc_url_raw($url);}}}
            }
            set_transient('mik_category_art_v140',$found,600);
        }
        return array_replace($found,$manual);
    }
    public static function hooks(): void { add_action('elementor/editor/after_save',function(){delete_transient('mik_category_art_v140');Catalog::invalidate_categories();}); }
}
