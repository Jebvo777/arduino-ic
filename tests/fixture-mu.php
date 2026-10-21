<?php
// Test-only mu-plugin. Never packaged in the delivered plugin.
add_filter('pre_wp_mail',fn()=>!get_option('mik_test_mail_failure',false));
add_filter('template_include',function($template){
    if(is_page()||is_shop()||is_product_category()){return __DIR__.'/templates/mik-test-template.php';}return $template;
});
