<?php
// Test-only mu-plugin. Never packaged in the delivered plugin.
add_filter('pre_wp_mail',function($value,$args){
    if(str_starts_with($args['subject']??'','Заказ MIK')){
        foreach((array)$args['attachments'] as $path){
            if(!str_ends_with($path,'.xls')||!is_file($path)){throw new RuntimeException('Test: XLS email attachment must exist and have .xls extension');}
            $f=fopen($path,'rb');$signature=fread($f,8);fclose($f);
            if($signature!==hex2bin('D0CF11E0A1B11AE1')){throw new RuntimeException('Test: email attachment is not OLE XLS');}
        }
    }
    return !get_option('mik_test_mail_failure',false);
},10,2);
add_filter('template_include',function($template){
    if(is_page()||is_shop()||is_product_category()){return __DIR__.'/templates/mik-test-template.php';}return $template;
},9999);
