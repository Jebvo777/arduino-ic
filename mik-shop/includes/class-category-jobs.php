<?php
namespace MIK;
defined('ABSPATH') || exit;

/** Short server-side jobs. Backups precede every taxonomy change, including restoration. */
class CategoryJobs {
    private const HOOK='mik_category_job_tick';
    private const PRIMARY=['rank_math_primary_product_cat','_yoast_wpseo_primary_product_cat'];
    public static function hooks(): void { add_action(self::HOOK,[self::class,'tick'],10,3); }
    private static function row(int $id): array { global $wpdb;$r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('category_jobs').' WHERE id=%d',$id),ARRAY_A);if(!$r){throw new \InvalidArgumentException('Задание категорий не найдено.');}return $r; }
    private static function save(int $id,array $data): void { global $wpdb;$data['updated_at']=DB::now();DB::require_result($wpdb->update(DB::table('category_jobs'),$data,['id'=>$id])); }
    private static function locked(callable $fn) {
        global $wpdb;$key='mik_categories_'.substr(md5(DB::table('category_jobs')),0,20);if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!==1){throw new \RuntimeException('Текущая порция ещё обрабатывается. Повторите действие через несколько секунд.');}
        try{return $fn();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
    }
    public static function dispatch(string $action,array $p): array {
        if($action==='status'){return self::status();}
        $id=absint($p['id']??0);
        if($action==='start'||$action==='restore'){
            $new=self::locked(function()use($action,$id){
                global $wpdb;$table=DB::table('category_jobs');$active=$wpdb->get_row("SELECT * FROM $table WHERE status IN ('queued','running','paused','error') ORDER BY id DESC LIMIT 1",ARRAY_A);
                if($action==='restore'){$source=self::row($id);if(!$source['backup_ready']){throw new \InvalidArgumentException('Эта резервная копия ещё не завершена.');}if($active){self::save((int)$active['id'],['status'=>'cancelled']);}}
                elseif($active){throw new \InvalidArgumentException('Сначала продолжите текущее задание либо восстановите его резервную копию.');}
                $where="post_type='product' AND post_status NOT IN ('trash','auto-draft')";$max=(int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE $where");$total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where AND ID<=".$max);
                $config=['tree'=>get_option('mik_category_tree',false),'default_category'=>get_option('default_product_cat',0)];
                DB::require_result($wpdb->insert($table,['mode'=>$action==='restore'?'restore':'migrate','source_id'=>$action==='restore'?$id:0,'status'=>'queued','phase'=>'backup_terms','max_product'=>$max,'total'=>$total,'config_json'=>DB::json($config),'created_at'=>DB::now(),'updated_at'=>DB::now()]));return (int)$wpdb->insert_id;
            });self::queue($new);return self::status();
        }
        self::locked(function()use($action,$id){$job=self::row($id);
            if($action==='pause'&&in_array($job['status'],['queued','running'],true)){self::save($id,['status'=>'paused']);return;}
            if($action==='resume'&&in_array($job['status'],['paused','error','queued','running'],true)){self::save($id,['status'=>'queued','error'=>'']);return;}
            throw new \InvalidArgumentException('Это действие недоступно для текущего состояния.');
        });if($action==='resume'){self::queue($id,true);}return self::status();
    }
    private static function queue(int $id,bool $force=false): void {
        $j=self::row($id);if(!in_array($j['status'],['queued','running'],true)){return;}$args=[$id,$j['phase'],(int)$j['cursor_id']];
        $has_as=function_exists('as_enqueue_async_action')&&did_action('init');
        if($has_as&&!$force&&function_exists('as_has_scheduled_action')&&as_has_scheduled_action(self::HOOK,$args,'mik-shop')){return;}
        global $wpdb;$wpdb->update(DB::table('category_jobs'),['queued_at'=>DB::now()],['id'=>$id]);
        if($has_as){$action=as_enqueue_async_action(self::HOOK,$args,'mik-shop',!$force);if($action){return;}if(function_exists('as_has_scheduled_action')&&as_has_scheduled_action(self::HOOK,$args,'mik-shop')){return;}}
        if(!wp_next_scheduled(self::HOOK,$args)){wp_schedule_single_event(time(),self::HOOK,$args);}if(function_exists('spawn_cron')){spawn_cron();}
    }
    public static function tick($id,$phase='',$cursor=-1): void {
        $id=(int)$id;
        try{self::locked(function()use($id,$phase,$cursor){
            $j=self::row($id);if(!in_array($j['status'],['queued','running'],true)){return;}
            if($phase!==''&&($j['phase']!==$phase||(int)$j['cursor_id']!==(int)$cursor)){return;}
            if($j['status']==='queued'){global $wpdb;$wpdb->query($wpdb->prepare('UPDATE '.DB::table('category_jobs')." SET status='running' WHERE id=%d AND status='queued'",$id));}
            CategoryTree::$include_archived=true;
            try{self::work($j);}finally{CategoryTree::$include_archived=false;}
        });}catch(\Throwable $e){
            // A concurrent request holding the lock will schedule the next portion itself.
            if(str_contains($e->getMessage(),'Текущая порция')){return;}
            try{self::save($id,['status'=>'error','error'=>mb_substr($e->getMessage(),0,1800)]);}catch(\Throwable $ignored){}return;
        }
        try{self::queue($id);}catch(\Throwable $e){self::save($id,['status'=>'error','error'=>$e->getMessage()]);}
    }
    private static function work(array $j): void {
        global $wpdb;$id=(int)$j['id'];$cursor=(int)$j['cursor_id'];$terms_table=DB::table('category_terms');$products_table=DB::table('category_products');
        if($j['phase']==='backup_terms'){
            $rows=$wpdb->get_results($wpdb->prepare("SELECT t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.description,tt.parent FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' AND t.term_id>%d ORDER BY t.term_id LIMIT 50",$cursor),ARRAY_A);
            foreach($rows as $r){$r['meta']=$wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->termmeta} WHERE term_id=%d",$r['term_id']),ARRAY_A);DB::require_result($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $terms_table (job_id,term_id,data_json) VALUES (%d,%d,%s)",$id,$r['term_id'],DB::json($r))));$cursor=(int)$r['term_id'];}
            self::save($id,['phase'=>$rows?'backup_terms':'backup_products','cursor_id'=>$rows?$cursor:0]);return;
        }
        if($j['phase']==='backup_products'){
            $ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status NOT IN ('trash','auto-draft') AND ID>%d AND ID<=%d ORDER BY ID LIMIT 120",$cursor,$j['max_product'])));
            if(!$ids){self::save($id,['backup_ready'=>1,'phase'=>$j['mode']==='restore'?'restore_terms':'prepare','cursor_id'=>0,'done'=>0]);return;}
            $in=implode(',',$ids);$links=[];$primary=[];
            foreach($wpdb->get_results("SELECT tr.object_id,tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy='product_cat' AND tr.object_id IN ($in)",ARRAY_A) as $r){$links[$r['object_id']][]=(int)$r['term_id'];}
            foreach($wpdb->get_results("SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ('rank_math_primary_product_cat','_yoast_wpseo_primary_product_cat')",ARRAY_A) as $r){$primary[$r['post_id']][$r['meta_key']]=$r['meta_value'];}
            foreach($ids as $pid){$data=['terms'=>$links[$pid]??[],'primary'=>$primary[$pid]??[]];DB::require_result($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $products_table (job_id,product_id,data_json) VALUES (%d,%d,%s)",$id,$pid,DB::json($data))));}
            self::save($id,['cursor_id'=>max($ids)]);return;
        }
        if($j['phase']==='prepare'){
            $tree=CategoryTree::ensure_tree($id);$old_created=json_decode($j['created_json']?:'[]',true)?:[];$created=array_values(array_unique(array_merge($old_created,$tree['created'])));
            // Persist created IDs before enabling the new tree, so recovery never loses its work set.
            self::save($id,['created_json'=>DB::json($created),'map_json'=>DB::json($tree['map']),'phase'=>'products','cursor_id'=>0]);update_option('mik_category_tree',['enabled'=>true,'map'=>$tree['map'],'job_id'=>$id],false);update_option('default_product_cat',(int)$tree['map']['review']);return;
        }
        if($j['phase']==='products'||$j['phase']==='restore_products'){
            $source=$j['mode']==='restore'?(int)$j['source_id']:$id;
            $rows=$wpdb->get_results($wpdb->prepare("SELECT b.product_id,b.data_json,p.post_title,p.post_excerpt,p.post_content FROM $products_table b LEFT JOIN {$wpdb->posts} p ON p.ID=b.product_id WHERE b.job_id=%d AND b.product_id>%d ORDER BY b.product_id LIMIT 50",$source,$cursor),ARRAY_A);
            if(!$rows){self::save($id,['phase'=>$j['mode']==='restore'?'restore_finish':'archive','cursor_id'=>0]);return;}
            $term_names=[];if($j['mode']!=='restore'){foreach($wpdb->get_results($wpdb->prepare("SELECT term_id,data_json FROM $terms_table WHERE job_id=%d",$id),ARRAY_A) as $term){$d=json_decode($term['data_json'],true);$term_names[(int)$term['term_id']]=$d['name'];}}
            $map=json_decode($j['map_json']?:'[]',true)?:[];$started=microtime(true);$done=(int)$j['done'];$deferred=wp_defer_term_counting();wp_defer_term_counting(true);
            try{foreach($rows as $r){$pid=(int)$r['product_id'];$before=json_decode($r['data_json'],true);$targets=[];
                if($r['post_title']!==null){
                    if($j['mode']==='restore'){$targets=array_values(array_filter(array_map('intval',$before['terms']),fn($t)=>term_exists($t,'product_cat')));}
                    else{$names=array_values(array_filter(array_map(fn($t)=>$term_names[(int)$t]??'',$before['terms'])));$leaf=CategoryTree::classify($r['post_title'],$names,$r['post_excerpt'].' '.$r['post_content']);$targets=[(int)($map[$leaf]??$map['review'])];}
                    $set=wp_set_object_terms($pid,$targets,'product_cat',false);if(is_wp_error($set)){throw new \RuntimeException($set->get_error_message());}
                    if($j['mode']==='restore'){foreach(self::PRIMARY as $key){delete_post_meta($pid,$key);if(array_key_exists($key,$before['primary'])){update_post_meta($pid,$key,$before['primary'][$key]);}}}
                    else{foreach(self::PRIMARY as $key){if(array_key_exists($key,$before['primary'])){update_post_meta($pid,$key,$targets[0]);}}}
                    clean_object_term_cache($pid,'product');delete_transient('wc_related_'.$pid);
                }
                $done++;self::save($id,['cursor_id'=>$pid,'done'=>$done]);if(microtime(true)-$started>3.2){break;}
            }}finally{wp_defer_term_counting($deferred);}return;
        }
        if($j['phase']==='archive'){
            $map=json_decode($j['map_json']?:'[]',true)?:[];$managed=array_map('intval',array_values($map));$rows=$wpdb->get_results($wpdb->prepare("SELECT term_id,data_json FROM $terms_table WHERE job_id=%d AND term_id>%d ORDER BY term_id LIMIT 50",$id,$cursor),ARRAY_A);
            foreach($rows as $row){$tid=(int)$row['term_id'];if(!in_array($tid,$managed,true)&&term_exists($tid,'product_cat')){$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE tt.taxonomy='product_cat' AND tt.term_id=%d AND p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')",$tid));if(!$count){$old=json_decode($row['data_json'],true);$leaf=CategoryTree::classify('',[$old['name']]);update_term_meta($tid,'_mik_archived',1);update_term_meta($tid,'_mik_replacement',(int)($map[$leaf]??$map['other']));}}$cursor=$tid;}
            self::save($id,['phase'=>$rows?'archive':'finish','cursor_id'=>$rows?$cursor:0]);return;
        }
        if($j['phase']==='restore_terms'){
            $rows=$wpdb->get_results($wpdb->prepare("SELECT term_id,data_json FROM $terms_table WHERE job_id=%d AND term_id>%d ORDER BY term_id LIMIT 40",$j['source_id'],$cursor),ARRAY_A);
            foreach($rows as $r){$t=json_decode($r['data_json'],true);$tid=(int)$r['term_id'];
                if(!term_exists($tid,'product_cat')){throw new \RuntimeException('Категория #'.$tid.' удалена вручную. Восстановите её из полной резервной копии сайта перед продолжением.');}
                $set=wp_update_term($tid,'product_cat',['name'=>$t['name'],'slug'=>$t['slug'],'description'=>$t['description'],'parent'=>(int)$t['parent']]);if(is_wp_error($set)){throw new \RuntimeException($set->get_error_message());}
                $wpdb->update($wpdb->terms,['term_group'=>(int)$t['term_group']],['term_id'=>$tid]);$wpdb->delete($wpdb->termmeta,['term_id'=>$tid]);foreach($t['meta'] as $meta){DB::require_result($wpdb->insert($wpdb->termmeta,['term_id'=>$tid,'meta_key'=>$meta['meta_key'],'meta_value'=>$meta['meta_value']]));}wp_cache_delete($tid,'term_meta');clean_term_cache($tid,'product_cat');$cursor=$tid;
            }
            self::save($id,['phase'=>$rows?'restore_terms':'restore_products','cursor_id'=>$rows?$cursor:0]);return;
        }
        if($j['phase']==='restore_finish'){
            $source=self::row((int)$j['source_id']);$config=json_decode($source['config_json'],true);if($config['tree']===false){delete_option('mik_category_tree');}else{update_option('mik_category_tree',$config['tree'],false);}update_option('default_product_cat',(int)$config['default_category']);
            $original=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT term_id FROM $terms_table WHERE job_id=%d",$j['source_id'])));
            $all=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'mik_include_archived'=>true]);if(is_wp_error($all)){throw new \RuntimeException($all->get_error_message());}
            foreach($all as $t){if(!in_array((int)$t->term_id,$original,true)&&get_term_meta($t->term_id,'_mik_tree_key',true)){$children=get_term_children($t->term_id,'product_cat');if(is_wp_error($children)){throw new \RuntimeException($children->get_error_message());}$objects=get_objects_in_term(array_merge([(int)$t->term_id],array_map('intval',$children)),'product_cat');if(is_wp_error($objects)){throw new \RuntimeException($objects->get_error_message());}if(!$objects){update_term_meta($t->term_id,'_mik_archived',1);delete_term_meta($t->term_id,'_mik_replacement');}}}
            self::save($id,['phase'=>'finish','cursor_id'=>0]);return;
        }
        if($j['phase']==='finish'){
            Catalog::invalidate_categories();delete_option('product_cat_children');if(class_exists('WC_Cache_Helper')){\WC_Cache_Helper::get_transient_version('product',true);}self::save($id,['status'=>'completed']);return;
        }
        throw new \RuntimeException('Неизвестный этап задания категорий.');
    }
    public static function status(): array {
        global $wpdb;$rows=$wpdb->get_results('SELECT id,mode,source_id,status,phase,cursor_id,total,done,backup_ready,error,created_at,updated_at,queued_at FROM '.DB::table('category_jobs').' ORDER BY id DESC LIMIT 20',ARRAY_A);
        foreach($rows as &$j){foreach(['id','source_id','cursor_id','total','done','backup_ready'] as $k){$j[$k]=(int)$j[$k];}$j['backed_up']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('category_products').' WHERE job_id=%d',$j['id']));$j['backup_url']=add_query_arg('_wpnonce',wp_create_nonce('mik_category_backup_'.$j['id']),admin_url('admin-post.php?action=mik_category_backup&id='.$j['id']));
            if(in_array($j['status'],['queued','running'],true)&&time()-strtotime(($j['queued_at']?:$j['updated_at']).' UTC')>90){self::queue($j['id'],true);}
        }unset($j);
        $categories=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'mik_include_archived'=>true]);$active=0;$archived=0;foreach(is_array($categories)?$categories:[] as $t){if(get_term_meta($t->term_id,'_mik_archived',true))$archived++;else $active++;}
        return ['jobs'=>$rows,'profile'=>CategoryTree::profile(),'categories'=>$active,'archived'=>$archived,'products'=>(int)wp_count_posts('product')->publish,'enabled'=>!empty(get_option('mik_category_tree')['enabled'])];
    }
    public static function download(): void {
        if(!current_user_can('manage_woocommerce')){wp_die('Нет доступа.');}$id=absint($_GET['id']??0);check_admin_referer('mik_category_backup_'.$id);$j=self::row($id);if(!$j['backup_ready']){wp_die('Копия ещё не завершена.');}
        nocache_headers();header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="mik-categories-backup-'.$id.'.json"');
        global $wpdb;echo '{"format":"mik-category-backup-v1","job":'.$id.',"created_at":'.wp_json_encode($j['created_at']).',"config":'.$j['config_json'].',"terms":[';$first=true;
        foreach($wpdb->get_col($wpdb->prepare('SELECT data_json FROM '.DB::table('category_terms').' WHERE job_id=%d ORDER BY term_id',$id)) as $row){echo ($first?'':',').$row;$first=false;}
        echo '],"products":[';$cursor=0;$first=true;do{$rows=$wpdb->get_results($wpdb->prepare('SELECT product_id,data_json FROM '.DB::table('category_products').' WHERE job_id=%d AND product_id>%d ORDER BY product_id LIMIT 200',$id,$cursor),ARRAY_A);foreach($rows as $r){echo ($first?'':',').'{"product_id":'.(int)$r['product_id'].',"data":'.$r['data_json'].'}';$first=false;$cursor=(int)$r['product_id'];}}while($rows);echo ']}';exit;
    }
}
