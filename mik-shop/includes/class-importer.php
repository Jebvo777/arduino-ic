<?php
namespace MIK;
defined('ABSPATH') || exit;

class Importer {
    public static function dir(): string {
        $base=trailingslashit(get_temp_dir()).'mik-shop-'.substr(hash_hmac('sha256',home_url(),wp_salt('auth')),0,20);
        if(!is_dir($base)&&!wp_mkdir_p($base)){throw new \RuntimeException('Нет доступа к временной папке PHP.');}
        @chmod($base,0700);return $base;
    }
    private static function lock(int $id,callable $fn) {
        global $wpdb;$key='mik_'.substr(md5(DB::table('imports')),0,12).'_'.$id;
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!==1){throw new \RuntimeException('Этот импорт уже обрабатывается. Повторите запрос через несколько секунд.');}
        try{return $fn();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
    }
    public static function dispatch(string $action,\WP_REST_Request $r): array {
        $p=(array)($r->get_json_params()?:$r->get_params());$id=absint($p['id']??0);
        if($action==='upload'){return self::upload($r);}
        if($action==='plan'){return self::lock($id,fn()=>self::plan($id,$p));}
        if($action==='plan-step'){return self::lock($id,fn()=>self::plan_step($id));}
        if($action==='select'){return self::select($id,(array)($p['packages']??[]));}
        if($action==='step'){return self::lock($id,fn()=>self::step($id,absint($p['package']??0)));}
        if($action==='retry'){
            global $wpdb;$number=absint($p['package']??0);$wpdb->query($wpdb->prepare('UPDATE '.DB::table('import_rows')." SET status='pending',error=NULL WHERE import_id=%d AND package_no=%d AND status='error'",$id,$number));
            $wpdb->update(DB::table('packages'),['status'=>'uploaded'],['import_id'=>$id,'package_no'=>$number]);return self::status($id);
        }
        throw new \InvalidArgumentException('Неизвестное действие импорта.');
    }
    private static function upload(\WP_REST_Request $r): array {
        global $wpdb;$p=$r->get_params();$files=$r->get_file_params();$file=$files['file']??null;
        if(!$file||$file['error']!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])){throw new \InvalidArgumentException('Не удалось загрузить фрагмент. Проверьте лимиты PHP.');}
        $token=(string)($p['upload_id']??'');if(!preg_match('/^[a-f0-9]{32}$/',$token)){throw new \InvalidArgumentException('Недопустимый ID загрузки.');}
        $kind=($p['kind']??'')==='catalog'?'catalog':'package';$offset=max(0,(int)($p['offset']??0));$total=max(0,(int)($p['total']??0));$size=(int)filesize($file['tmp_name']);
        if($total<1||$total>1073741824||$size>2097152||$offset+$size>$total){throw new \InvalidArgumentException('Недопустимый размер файла или фрагмента.');}
        $path=self::dir().'/'.get_current_user_id().'-'.$kind.'-'.$token.'.part';$h=fopen($path,'c+b');if(!$h||!flock($h,LOCK_EX)){throw new \RuntimeException('Файл занят. Повторите запрос.');}
        try{
            $length=(int)fstat($h)['size'];
            if($offset>$length){throw new \RuntimeException('Нарушен порядок загрузки. Повторите с текущего фрагмента.');}
            if($offset<$length){fseek($h,$offset);$old=fread($h,$size);if(strlen($old)!==$size||!hash_equals(hash('sha256',$old),hash_file('sha256',$file['tmp_name']))){throw new \RuntimeException('Фрагмент не совпадает с уже загруженным. Начните загрузку заново.');}}
            else{fseek($h,0,SEEK_END);$src=fopen($file['tmp_name'],'rb');$written=stream_copy_to_stream($src,$h);fclose($src);if($written!==$size){throw new \RuntimeException('Недостаточно места для загрузки.');}}
            $length=(int)fstat($h)['size'];
        }finally{flock($h,LOCK_UN);fclose($h);}
        if($length<$total){return ['offset'=>$length,'done'=>false];}
        if($kind==='catalog'){
            if($total>104857600){throw new \InvalidArgumentException('Каталог CSV не должен превышать 100 МБ.');}
            $existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('imports').' WHERE file_path=%s',$path));
            if($existing){return ['offset'=>$length,'done'=>true,'id'=>(int)$existing];}
            $f=fopen($path,'rb');$headers=fgetcsv($f,0,';');fclose($f);if($headers){$headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);}
            if(!$headers||array_diff(['legacy_id','sku','name','assets_json','images_json','pdfs_json'],(array)$headers)){throw new \InvalidArgumentException('Используйте подготовленный catalog.csv в UTF-8 с разделителем «;».');}
            DB::require_result($wpdb->insert(DB::table('imports'),['file_path'=>$path,'headers'=>DB::json($headers),'created_at'=>DB::now(),'updated_at'=>DB::now()]));return ['offset'=>$length,'done'=>true,'id'=>(int)$wpdb->insert_id];
        }
        $id=absint($p['id']??0);$package=self::accept_zip($id,$path);return ['offset'=>$length,'done'=>true,'id'=>$id,'package'=>$package];
    }
    private static function job(int $id): array {
        global $wpdb;$j=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('imports').' WHERE id=%d',$id),ARRAY_A);if(!$j||!is_file($j['file_path'])){throw new \InvalidArgumentException('Каталог импорта не найден. Загрузите CSV заново.');}return $j;
    }
    private static function plan(int $id,array $p): array {
        global $wpdb;$j=self::job($id);if($j['status']!=='uploaded'){throw new \InvalidArgumentException('План уже создан. Для другого размера пакетов загрузите каталог заново.');}
        $mb=max(1,min(1024,(int)($p['mb']??50)));$f=fopen($j['file_path'],'rb');fgetcsv($f,0,';');$offset=ftell($f);fclose($f);
        $wpdb->update(DB::table('imports'),['status'=>'planning','plan_bytes'=>$mb*1048576,'byte_offset'=>$offset,'current_package'=>1,'package_bytes'=>0,'updated_at'=>DB::now()],['id'=>$id]);return self::status($id);
    }
    private static function plan_step(int $id): array {
        global $wpdb;$j=self::job($id);if($j['status']!=='planning'){return self::status($id);}
        $headers=json_decode($j['headers'],true);$f=fopen($j['file_path'],'rb');fseek($f,(int)$j['byte_offset']);$limit=(int)floor($j['plan_bytes']*.88)-4096;$number=(int)$j['current_package'];$bytes=(int)$j['package_bytes'];$total=(int)$j['total_rows'];$count=0;
        $row_count=(int)$wpdb->get_var($wpdb->prepare('SELECT rows_count FROM '.DB::table('packages').' WHERE import_id=%d AND package_no=%d',$id,$number));
        while($count<150&&($values=fgetcsv($f,0,';'))!==false){
            if(count($values)===1&&trim((string)$values[0])===''){continue;}
            if(count($values)!==count($headers)){fclose($f);throw new \InvalidArgumentException('Число колонок не совпадает в строке '.($total+2).'. Исправьте CSV.');}
            $row=array_combine($headers,$values);if(!trim($row['sku'])||!trim($row['name'])){continue;}
            $assets=json_decode($row['assets_json']??'[]',true);if(!is_array($assets)){fclose($f);throw new \InvalidArgumentException('Некорректный assets_json у '.$row['sku']);}
            $size=2048;foreach($assets as $a){self::valid_path((string)($a['path']??''));$size+=max(0,(int)($a['size']??0));}
            if($size>$limit){fclose($f);throw new \InvalidArgumentException('Товар '.$row['sku'].' требует около '.ceil($size/1048576).' МБ. Для него нужен больший размер пакета. Загрузите каталог заново с большим лимитом.');}
            if($row_count&&($bytes+$size>$limit||$row_count>=500)){$number++;$bytes=0;$row_count=0;}
            DB::require_result($wpdb->insert(DB::table('import_rows'),['import_id'=>$id,'package_no'=>$number,'legacy_id'=>sanitize_text_field($row['legacy_id']),'row_json'=>DB::json($row),'status'=>'pending']));
            $bytes+=$size;$row_count++;$total++;$count++;
            $filename='mik-'.$id.'-'.str_pad((string)$number,4,'0',STR_PAD_LEFT).'.zip';
            $wpdb->query($wpdb->prepare('INSERT INTO '.DB::table('packages').' (import_id,package_no,filename,estimated_bytes,rows_count) VALUES (%d,%d,%s,%d,%d) ON DUPLICATE KEY UPDATE estimated_bytes=VALUES(estimated_bytes),rows_count=VALUES(rows_count)',$id,$number,$filename,$bytes,$row_count));
        }
        $finished=feof($f);$offset=ftell($f);fclose($f);$wpdb->update(DB::table('imports'),['byte_offset'=>$offset,'current_package'=>$number,'package_bytes'=>$bytes,'total_rows'=>$total,'status'=>$finished?'ready':'planning','updated_at'=>DB::now()],['id'=>$id]);return self::status($id);
    }
    public static function status(int $id): array {
        global $wpdb;if(!$id){return ['jobs'=>$wpdb->get_results('SELECT id,status,total_rows,created_at FROM '.DB::table('imports').' ORDER BY id DESC LIMIT 20',ARRAY_A)];}
        $j=$wpdb->get_row($wpdb->prepare('SELECT id,status,total_rows,plan_bytes,created_at FROM '.DB::table('imports').' WHERE id=%d',$id),ARRAY_A);if(!$j){throw new \InvalidArgumentException('Импорт не найден.');}
        $j['packages']=$wpdb->get_results($wpdb->prepare('SELECT package_no,filename,estimated_bytes,rows_count,selected,status FROM '.DB::table('packages').' WHERE import_id=%d ORDER BY package_no',$id),ARRAY_A);
        $j['processed']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('import_rows')." WHERE import_id=%d AND status='done'",$id));
        $j['error_count']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('import_rows')." WHERE import_id=%d AND status='error'",$id));
        $j['errors']=$wpdb->get_results($wpdb->prepare('SELECT id,package_no,legacy_id,error FROM '.DB::table('import_rows')." WHERE import_id=%d AND error IS NOT NULL AND error<>'' ORDER BY id DESC LIMIT 100",$id),ARRAY_A);
        $pending=array_values(array_filter($j['packages'],fn($p)=>$p['selected']&&!in_array($p['status'],['done','done-errors'],true)));$j['next']=$pending[0]??null;
        $j['bat_url']=wp_nonce_url(admin_url('admin-post.php?action=mik_document&kind=packager&id='.$id),'mik_packager');return $j;
    }
    private static function select(int $id,array $ids): array {
        global $wpdb;$j=self::job($id);if($j['status']!=='ready'){throw new \InvalidArgumentException('Дождитесь завершения планирования.');}
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));if(!$ids){throw new \InvalidArgumentException('Выберите хотя бы один пакет.');}
        $wpdb->update(DB::table('packages'),['selected'=>0],['import_id'=>$id]);foreach($ids as $n){$wpdb->update(DB::table('packages'),['selected'=>1],['import_id'=>$id,'package_no'=>$n]);}return self::status($id);
    }
    private static function token(array $j,int $number): string { return hash_hmac('sha256',$j['id'].'|'.$number.'|'.hash_file('sha256',$j['file_path']),wp_salt('auth')); }
    public static function bat(int $id): string {
        global $wpdb;$j=self::job($id);if($j['status']!=='ready'){throw new \InvalidArgumentException('План ещё не готов.');}
        $packages=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('packages').' WHERE import_id=%d AND selected=1 ORDER BY package_no',$id),ARRAY_A);$plan=['job_id'=>$id,'max_bytes'=>(int)$j['plan_bytes'],'packages'=>[]];
        foreach($packages as $p){
            $files=[];$offset=0;
            do{$rows=$wpdb->get_col($wpdb->prepare('SELECT row_json FROM '.DB::table('import_rows').' WHERE import_id=%d AND package_no=%d ORDER BY id LIMIT 200 OFFSET %d',$id,$p['package_no'],$offset));foreach($rows as $raw){$row=json_decode($raw,true);foreach(json_decode($row['assets_json']??'[]',true)?:[] as $a){$path=self::valid_path($a['path']);$files[$path]=['path'=>$path,'size'=>(int)$a['size']];}}$offset+=200;}while(count($rows)===200);
            $plan['packages'][]=['number'=>(int)$p['package_no'],'filename'=>$p['filename'],'token'=>self::token($j,(int)$p['package_no']),'files'=>array_values($files)];
        }
        $payload=base64_encode(gzencode(DB::json($plan),9));$ps=file_get_contents(MIK_PATH.'assets/packager.ps1');
        $loader='$self=$env:MIK_SELF; $raw=[IO.File]::ReadAllText($self); $parts=$raw -split "(?m)^::MIK_DATA\\r?$",2; $bytes=[Convert]::FromBase64String($parts[1].Trim()); $ms=New-Object IO.MemoryStream(,$bytes); $gz=New-Object IO.Compression.GzipStream($ms,[IO.Compression.CompressionMode]::Decompress); $reader=New-Object IO.StreamReader($gz,[Text.Encoding]::UTF8); $plan=$reader.ReadToEnd() | ConvertFrom-Json; $script=$raw -split "(?m)^::MIK_PS\\r?$",2; $body=($script[1] -split "(?m)^::MIK_DATA\\r?$",2)[0]; & ([ScriptBlock]::Create($body)) $plan (Split-Path -LiteralPath $self -Parent)';
        $encoded=base64_encode(mb_convert_encoding($loader,'UTF-16LE','UTF-8'));
        return "@echo off\r\nsetlocal DisableDelayedExpansion\r\nset \"MIK_SELF=%~f0\"\r\npowershell.exe -NoLogo -NoProfile -EncodedCommand $encoded\r\nset \"MIK_EXIT=%ERRORLEVEL%\"\r\npause\r\nexit /b %MIK_EXIT%\r\n::MIK_PS\r\n".str_replace("\n","\r\n",$ps)."\r\n::MIK_DATA\r\n".$payload."\r\n";
    }
    private static function valid_path(string $path): string {
        $path=str_replace('\\','/',$path);$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        if($path===''||str_starts_with($path,'/')||str_contains($path,':')||str_contains($path,"\0")||preg_match('~(?:^|/)\.\.(?:/|$)~',$path)||!in_array($ext,['jpg','jpeg','png','gif','webp','pdf'],true)){throw new \InvalidArgumentException('Недопустимый путь медиафайла: '.sanitize_text_field($path));}return $path;
    }
    private static function accept_zip(int $id,string $path): int {
        global $wpdb;if(!class_exists('ZipArchive')){throw new \RuntimeException('На хостинге необходимо включить PHP ZipArchive.');}$j=self::job($id);$z=new \ZipArchive();if($z->open($path)!==true){throw new \InvalidArgumentException('Повреждён ZIP.');}
        try{
            $stat=$z->statName('manifest.json');if(!$stat||$stat['size']>2097152){throw new \InvalidArgumentException('В ZIP отсутствует допустимый manifest.json.');}
            $m=json_decode($z->getFromName('manifest.json'),true);$n=absint($m['package_no']??0);
            if((int)($m['job_id']??0)!==$id||!hash_equals(self::token($j,$n),(string)($m['token']??''))){throw new \InvalidArgumentException('Этот пакет не принадлежит выбранному импорту.');}
            $p=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('packages').' WHERE import_id=%d AND package_no=%d',$id,$n),ARRAY_A);
            if(!$p||!$p['selected']){throw new \InvalidArgumentException('Пакет не выбран в плане.');}
            $size=0;if($z->numFiles>20000){throw new \InvalidArgumentException('Слишком много файлов в ZIP.');}
            for($i=0;$i<$z->numFiles;$i++){
                $s=$z->statIndex($i);$name=$s['name'];if($name!=='manifest.json'){self::valid_path($name);if(!isset($m['files'][$name])){throw new \InvalidArgumentException('Лишний файл в пакете.');}}
                $size+=$s['size'];if($s['size']>1048576&&$s['comp_size']>0&&$s['size']/$s['comp_size']>500){throw new \InvalidArgumentException('Недопустимая степень сжатия ZIP.');}
                if($z->getExternalAttributesIndex($i,$opsys,$attrs)&&($attrs>>16&0170000)===0120000){throw new \InvalidArgumentException('Символические ссылки запрещены.');}
            }
            if($size>(int)$j['plan_bytes']*2+2097152){throw new \InvalidArgumentException('Распакованный пакет превышает лимит плана.');}
            if(in_array($p['status'],['done','done-errors'],true)){return $n;}
            $wpdb->update(DB::table('packages'),['zip_path'=>$path,'status'=>'uploaded'],['import_id'=>$id,'package_no'=>$n]);return $n;
        }finally{$z->close();}
    }
    private static function step(int $id,int $number): array {
        global $wpdb;$j=self::job($id);$p=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('packages').' WHERE import_id=%d AND package_no=%d',$id,$number),ARRAY_A);
        if($p&&$p['selected']&&in_array($p['status'],['done','done-errors'],true)){return self::status($id);}
        if(!$p||!$p['selected']||!in_array($p['status'],['uploaded','processing'],true)||!is_file($p['zip_path'])){throw new \InvalidArgumentException('Сначала загрузите выбранный ZIP-пакет.');}
        $z=new \ZipArchive();if($z->open($p['zip_path'])!==true){throw new \RuntimeException('Не удалось открыть пакет.');}$manifest=json_decode($z->getFromName('manifest.json'),true);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('import_rows')." WHERE import_id=%d AND package_no=%d AND status='pending' ORDER BY id LIMIT 8",$id,$number),ARRAY_A);$start=microtime(true);
        $wpdb->update(DB::table('packages'),['status'=>'processing'],['import_id'=>$id,'package_no'=>$number]);
        try{
            foreach($rows as $entry){
                try{$warnings=[];self::import_product(json_decode($entry['row_json'],true),$z,$manifest,$warnings);$wpdb->update(DB::table('import_rows'),['status'=>'done','error'=>$warnings?implode('; ',$warnings):null],['id'=>$entry['id']]);}
                catch(\Throwable $e){$wpdb->update(DB::table('import_rows'),['status'=>'error','error'=>mb_substr($e->getMessage(),0,4000)],['id'=>$entry['id']]);}
                if(microtime(true)-$start>8){break;}
            }
        }finally{$z->close();}
        $remaining=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('import_rows')." WHERE import_id=%d AND package_no=%d AND status='pending'",$id,$number));
        if(!$remaining){$errors=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('import_rows')." WHERE import_id=%d AND package_no=%d AND status='error'",$id,$number));$wpdb->update(DB::table('packages'),['status'=>$errors?'done-errors':'done'],['import_id'=>$id,'package_no'=>$number]);}
        return self::status($id);
    }
    private static function media(string $path,\ZipArchive $z,array $m,array &$warnings): int {
        global $wpdb;$path=self::valid_path($path);$expected=$m['files'][$path]??null;
        if(!$expected||$z->locateName($path)===false){$warnings[]='Нет файла: '.$path;return 0;}
        $sha=(string)($expected['sha256']??'');if(!preg_match('/^[a-f0-9]{64}$/',$sha)){throw new \InvalidArgumentException('Некорректная контрольная сумма файла.');}
        $old=(int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_mik_asset_sha256' AND meta_value=%s LIMIT 1",$sha));if($old&&get_post_type($old)==='attachment'&&is_file(get_attached_file($old))){return $old;}
        $source=$z->getStream($path);if(!$source){throw new \RuntimeException('Не удалось прочитать файл ZIP.');}$tmp=tempnam(self::dir(),'asset-');$target=fopen($tmp,'wb');stream_copy_to_stream($source,$target);fclose($source);fclose($target);
        try{
            if(!hash_equals($sha,hash_file('sha256',$tmp))){throw new \RuntimeException('Контрольная сумма не совпала: '.$path);}
            $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$mime='';$dims=null;
            if($ext==='pdf'){$f=fopen($tmp,'rb');$head=fread($f,5);fclose($f);if($head!=='%PDF-'){throw new \InvalidArgumentException('Файл не является PDF: '.$path);}$mime='application/pdf';}
            else{$dims=wp_getimagesize($tmp);if(!$dims||!in_array($dims['mime']??'',['image/jpeg','image/png','image/gif','image/webp'],true)){throw new \InvalidArgumentException('Файл не является изображением: '.$path);}$mime=$dims['mime'];}
            $upload=wp_upload_dir();if($upload['error']){throw new \RuntimeException($upload['error']);}$dir=$upload['basedir'].'/mik-media';if(!wp_mkdir_p($dir)){throw new \RuntimeException('Не удалось создать папку медиа.');}$dest=$dir.'/'.$sha.'.'.$ext;
            if(!is_file($dest)&&!copy($tmp,$dest)){throw new \RuntimeException('Не удалось сохранить медиафайл.');}
            $attachment=wp_insert_attachment(['post_title'=>sanitize_text_field(pathinfo($path,PATHINFO_FILENAME)),'post_mime_type'=>$mime,'post_status'=>'inherit'],$dest,0,true);if(is_wp_error($attachment)){throw new \RuntimeException($attachment->get_error_message());}
            update_post_meta($attachment,'_mik_asset_sha256',$sha);update_post_meta($attachment,'_mik_source_path',$path);
            if($dims){wp_update_attachment_metadata($attachment,['width'=>$dims[0],'height'=>$dims[1],'file'=>'mik-media/'.basename($dest),'sizes'=>[]]);}
            return (int)$attachment;
        }finally{@unlink($tmp);}
    }
    private static function import_product(array $r,\ZipArchive $z,array $manifest,array &$warnings): void {
        global $wpdb;$legacy=sanitize_text_field($r['legacy_id']??'');$sku=sanitize_text_field($r['sku']??'');$name=sanitize_text_field($r['name']??'');if($sku===''||$name===''){throw new \InvalidArgumentException('Нет артикула или названия.');}
        $id=$legacy!==''?(int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_mik_legacy_id' AND meta_value=%s LIMIT 1",$legacy)):0;
        if(!$id){$id=(int)wc_get_product_id_by_sku($sku);}$product=$id?wc_get_product($id):new \WC_Product_Simple();if(!$product||!$product->is_type('simple')){throw new \InvalidArgumentException('SKU занят товаром другого типа: '.$sku);}
        $images=[];$pdfs=[];foreach(json_decode($r['images_json']??'[]',true)?:[] as $path){$a=self::media($path,$z,$manifest,$warnings);if($a){$images[]=$a;}}foreach(json_decode($r['pdfs_json']??'[]',true)?:[] as $path){$a=self::media($path,$z,$manifest,$warnings);if($a){$pdfs[]=$a;}}
        $product->set_name($name);$product->set_sku($sku);$product->set_status('publish');$product->set_description(wp_kses_post($r['description']??''));$product->set_short_description(wp_kses_post($r['short_description']??''));$product->set_manage_stock(true);$stock=max(0,(int)($r['stock']??0));$product->set_stock_quantity($stock);$product->set_stock_status($stock?'instock':'outofstock');$product->set_regular_price(is_numeric($r['price_rub']??'')?wc_format_decimal($r['price_rub']):'');$product->set_sale_price('');
        $product->set_featured(!empty($r['featured']));if($images){$product->set_image_id($images[0]);$product->set_gallery_image_ids(array_slice(array_unique($images),1));}
        $category=sanitize_text_field($r['category']??'Прочие компоненты');$term=term_exists($category,'product_cat');if(!$term){$term=wp_insert_term($category,'product_cat');}if(!is_wp_error($term)){$product->set_category_ids([(int)(is_array($term)?$term['term_id']:$term)]);}
        $id=$product->save();if(!$id){throw new \RuntimeException('Не удалось сохранить товар.');}
        update_post_meta($id,'_mik_legacy_id',$legacy);update_post_meta($id,'_mik_source_sku',sanitize_text_field($r['source_sku']??$sku));update_post_meta($id,'_mik_source_url',esc_url_raw($r['source_url']??''));update_post_meta($id,'_mik_ozon_url',esc_url_raw($r['ozon_url']??''));update_post_meta($id,'_mik_stock_note',sanitize_text_field($r['stock_note']??''));update_post_meta($id,'_mik_special_price',is_numeric($r['price_special']??'')?(float)$r['price_special']:'');
        if($pdfs){update_post_meta($id,'_mik_pdfs',array_values(array_unique($pdfs)));}Catalog::set_specs($id,json_decode($r['specs_json']??'[]',true)?:[]);Catalog::sync_stock($id);
        if(isset($r['price_wholesale'])&&is_numeric($r['price_wholesale'])){$wpdb->replace(DB::table('prices'),['product_id'=>$id,'group_id'=>3,'price'=>(float)$r['price_wholesale']]);}
    }
}
