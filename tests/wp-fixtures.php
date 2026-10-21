<?php
// Run only in a disposable WordPress installation: wp eval-file tests/wp-fixtures.php.
if(!defined('WP_CLI')||!WP_CLI){exit;}
global $wpdb;
$root=dirname(__DIR__);$out=$root.'/.runtime';
$cat=wp_insert_term('Дискретные полупроводники','product_cat');$parent=is_wp_error($cat)?get_term_by('name','Дискретные полупроводники','product_cat')->term_id:$cat['term_id'];
$cat=wp_insert_term('Биполярные транзисторы','product_cat',['parent'=>$parent]);$category=is_wp_error($cat)?get_term_by('name','Биполярные транзисторы','product_cat')->term_id:$cat['term_id'];
$upload=wp_upload_dir();wp_mkdir_p($upload['basedir'].'/mik-fixture');$file=$upload['basedir'].'/mik-fixture/component.webp';copy(MIK_PATH.'assets/img/slider-components.webp',$file);
$image=wp_insert_attachment(['post_title'=>'Тестовое изображение компонентов','post_mime_type'=>'image/webp','post_status'=>'inherit'],$file);$size=wp_getimagesize($file);wp_update_attachment_metadata($image,['width'=>$size[0],'height'=>$size[1],'file'=>'mik-fixture/component.webp','sizes'=>[]]);
$ids=[];
foreach([['MJE13003BP','MJE13003BP',36,295],['IRFP4227','IRFP4227',180,3],['CD4013BE','CD4013BE',55,80],['1N4007','1N4007',4,1000]] as [$sku,$name,$price,$stock]){
    $p=new WC_Product_Simple();$p->set_name($name);$p->set_sku($sku);$p->set_status('publish');$p->set_regular_price($price);$p->set_manage_stock(true);$p->set_stock_quantity($stock);$p->set_stock_status('instock');$p->set_category_ids([$category]);$p->set_featured(true);$p->set_image_id($image);$p->set_short_description('Компонент для ремонта и разработки');$p->set_description('<p>Описание тестового электронного компонента.</p>');$id=$p->save();$ids[]=$id;
    \MIK\Catalog::set_specs($id,[['param_key'=>'type','label'=>'Тип','value'=>'npn','unit'=>''],['param_key'=>'voltage','label'=>'Напряжение','value'=>'400','unit'=>'В'],['param_key'=>'current','label'=>'Ток','value'=>'1.6','unit'=>'А'],['param_key'=>'power','label'=>'Мощность','value'=>'1.5','unit'=>'Вт']]);
    $wpdb->replace(\MIK\DB::table('prices'),['product_id'=>$id,'group_id'=>3,'price'=>$price*.8]);$wpdb->replace(\MIK\DB::table('prices'),['product_id'=>$id,'group_id'=>2,'price'=>2.59]);
}
\MIK\Settings::save(['seller'=>['name'=>'Тестовый продавец','inn'=>'7700000000','kpp'=>'770001001','address'=>'Тестовый адрес','bank'=>'Тестовый банк','bik'=>'044525000','account'=>'40702810000000000000','correspondent'=>'30101810000000000000'],'email'=>'manager@example.test']);
wp_set_current_user(1);$pages=\MIK\Admin::save('setup',[]);
delete_transient('_wc_activation_redirect');update_option('woocommerce_onboarding_profile',['completed'=>true]);
$preview=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'MIK Preview','post_name'=>'mik-preview','post_content'=>'[mik_slider][mik_products_carousel][mik_products_carousel title="Новые поступления" source="new"]']);
$detail=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Product Preview','post_name'=>'mik-product-preview','post_content'=>'[mik_product id="'.$ids[0].'"]']);
$category_preview=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Category Preview','post_name'=>'mik-category-preview','post_content'=>'[mik_catalog category="'.$category.'"]']);
file_put_contents($out.'/fixture.json',wp_json_encode(['products'=>$ids,'category'=>$category,'admin_user'=>'mik_test_admin','admin_password'=>'Local-Test-Only-Password-2026','wp_users'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->users),'wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION]));
WP_CLI::success('Created disposable fixtures.');
