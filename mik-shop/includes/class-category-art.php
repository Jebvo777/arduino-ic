<?php
namespace MIK;
defined('ABSPATH') || exit;

/** The supplied category artwork is bundled so it survives a domain change. */
class CategoryArt {
    public const FILES=[
        'ic'=>'category-ic.webp',
        'semiconductors'=>'category-semiconductors.webp',
        'diodes'=>'category-diodes.webp',
        'microcontrollers'=>'category-microcontrollers.webp',
        'passive'=>'category-passive.webp',
        'power'=>'category-power.webp',
        'connectors'=>'category-connectors.webp',
        'protection'=>'category-protection.webp',
        'accessories'=>'category-accessories.webp',
        'other'=>'category-other.webp',
    ];
    public static function images(): array {
        $images=[];foreach(self::FILES as $key=>$file){$images[$key]=MIK_URL.'assets/img/'.$file;}
        foreach((array)Settings::get('category_images',[]) as $key=>$url){if(isset(self::FILES[$key])&&is_string($url)&&$url!==''){$clean=esc_url_raw($url);if($clean)$images[$key]=$clean;}}
        return $images;
    }
    public static function hooks(): void {
        if(get_option('mik_category_art_version')==='1.4.1'){return;}
        $settings=(array)get_option('mik_settings',[]);$old=(array)($settings['category_images']??[]);
        if($old){update_option('mik_category_images_previous_141',$old,false);}
        // Apply the ten requested pictures once; subsequent administrator overrides are retained.
        foreach(array_keys(self::FILES) as $key){unset($settings['category_images'][$key]);}
        update_option('mik_settings',$settings,false);
        delete_transient('mik_category_art_v140');Catalog::invalidate_categories();
        update_option('mik_category_art_version','1.4.1',false);
    }
}
