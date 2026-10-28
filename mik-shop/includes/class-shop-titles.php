<?php
namespace MIK;
defined('ABSPATH') || exit;

class ShopTitles {
    private static function active(): bool { return !is_admin()&&function_exists('is_shop')&&(is_shop()||is_product_category()); }
    public static function heading(): string { if(function_exists('is_product_category')&&is_product_category()){$term=get_queried_object();if($term instanceof \WP_Term)return $term->name;}return (string)Settings::get('catalog_title','Каталог электронных компонентов'); }
    public static function hooks(): void {
        add_filter('get_the_archive_title',fn($title)=>self::active()?self::heading():$title,999);
        add_filter('woocommerce_page_title',fn($title)=>self::active()?self::heading():$title,999);
        add_filter('document_title_parts',function($parts){if(self::active())$parts['title']=self::heading();return $parts;},999);
        foreach(['pre_get_document_title','rank_math/frontend/title','wpseo_title'] as $hook){add_filter($hook,fn($title)=>self::active()?self::heading().' — '.Settings::get('store_name'):$title,9999);}
    }
}
