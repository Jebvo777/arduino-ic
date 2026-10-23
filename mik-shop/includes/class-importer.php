<?php
namespace MIK;
defined('ABSPATH') || exit;

class Importer {
    public const CATALOG_MAX=104857600;
    public const PACKAGE_MAX=0; // Zero means no application-imposed byte limit for ZIP packages.
    public const CHUNK_MAX=2097152;
    public const IMAGE_EXTENSIONS=['jpg','jpeg','jpe','png','webp','gif','avif','bmp','dib','tif','tiff','ico','heic','heif'];
    private const PLANNER_VERSION=2;
    private const MANIFEST_MAX=8388608;
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
        if(str_starts_with($action,'remote-')){return RemoteImport::dispatch(substr($action,7),$p);}
        if($action==='plan'){return self::lock($id,fn()=>self::plan($id,$p));}
        if($action==='replan'){return self::lock($id,fn()=>self::replan($id,$p));}
        if($action==='plan-step'){return self::lock($id,fn()=>DB::transaction(fn()=>self::plan_step($id)));}
        if($action==='select'){return DB::transaction(fn()=>self::select($id,(array)($p['packages']??[])));}
        if($action==='step'){return self::lock($id,fn()=>self::step($id,absint($p['package']??0)));}
        if($action==='retry'){
            global $wpdb;$number=absint($p['package']??0);$wpdb->query($wpdb->prepare('UPDATE '.DB::table('import_rows')." SET status='pending',error=NULL WHERE import_id=%d AND package_no=%d AND status='error'",$id,$number));
            $wpdb->update(DB::table('packages'),['status'=>'uploaded'],['import_id'=>$id,'package_no'=>$number]);return self::status($id);
        }
        throw new \InvalidArgumentException('Неизвестное действие импорта.');
    }
    private static function upload(\WP_REST_Request $r): array {
        $p=$r->get_body_params();$files=$r->get_file_params();$file=$files['file']??null;
        if(!$file){throw new \InvalidArgumentException('Сервер не получил фрагмент. Обновите страницу и повторите загрузку.');}
        if((int)$file['error']!==UPLOAD_ERR_OK){
            $messages=[UPLOAD_ERR_INI_SIZE=>'Размер фрагмента превышает upload_max_filesize хостинга.',UPLOAD_ERR_FORM_SIZE=>'Размер фрагмента превышает лимит формы.',UPLOAD_ERR_PARTIAL=>'Фрагмент получен не полностью. Повторите загрузку.',UPLOAD_ERR_NO_TMP_DIR=>'На сервере недоступна временная папка PHP.',UPLOAD_ERR_CANT_WRITE=>'Сервер не смог записать фрагмент: проверьте свободное место.'];
            throw new \InvalidArgumentException($messages[(int)$file['error']]??'Не удалось получить файл. Повторите загрузку.');
        }
        if(!is_uploaded_file($file['tmp_name'])){throw new \InvalidArgumentException('Недопустимый файл загрузки.');}
        $token=(string)($p['upload_id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$token)){throw new \InvalidArgumentException('Недопустимый ID загрузки. Обновите страницу.');}
        $kind=(string)($p['kind']??'');if(!in_array($kind,['catalog','package'],true)){throw new \InvalidArgumentException('Укажите тип файла: каталог или ZIP-пакет.');}
        foreach(['offset','total'] as $key){if(!isset($p[$key])||!preg_match('/^\d+$/D',(string)$p[$key])){throw new \InvalidArgumentException('Некорректные параметры фрагмента. Обновите страницу после установки обновления.');}}
        $offset=(int)$p['offset'];$total=(int)$p['total'];$size=(int)filesize($file['tmp_name']);$max=$kind==='catalog'?self::CATALOG_MAX:self::PACKAGE_MAX;
        if($total<1||($max>0&&$total>$max)){throw new \InvalidArgumentException($kind==='catalog'?'Каталог должен быть не пустым CSV размером до 100 МБ.':'ZIP-пакет не должен быть пустым.');}
        if($size<1||$size>self::CHUNK_MAX){throw new \InvalidArgumentException('Получен фрагмент '.size_format($size,2).', допустимо до 2 МБ. Перезагрузите страницу админки после обновления плагина и повторите загрузку.');}
        if($offset>$total||$size>$total-$offset){throw new \InvalidArgumentException('Границы фрагмента выходят за размер файла. Обновите страницу и повторите загрузку.');}
        $path=self::dir().'/'.get_current_user_id().'-'.$kind.'-'.$token.'.part';$h=fopen($path,'c+b');
        if(!$h){throw new \RuntimeException('Не удалось открыть временный файл. Проверьте права и свободное место.');}
        if(!flock($h,LOCK_EX)){fclose($h);throw new \RuntimeException('Файл занят. Повторите запрос.');}
        try {
            $length=(int)fstat($h)['size'];if($length>$total){throw new \InvalidArgumentException('Размер сохранённой загрузки не совпадает с файлом. Начните новую загрузку.');}
            if($offset>$length){throw new \RuntimeException('Нарушен порядок загрузки. Повторно выберите тот же файл для продолжения.');}
            if($offset<$length){
                fseek($h,$offset);$old=fread($h,$size);
                if(strlen($old)!==$size||!hash_equals(hash('sha256',$old),hash_file('sha256',$file['tmp_name']))){throw new \InvalidArgumentException('Фрагмент отличается от сохранённого. Выберите исходный файл или начните новую загрузку.');}
            } else {
                fseek($h,0,SEEK_END);$src=fopen($file['tmp_name'],'rb');if(!$src){throw new \RuntimeException('Не удалось прочитать фрагмент.');}
                try{$written=stream_copy_to_stream($src,$h);}finally{fclose($src);}
                if($written!==$size){ftruncate($h,$length);throw new \RuntimeException('Недостаточно места для сохранения фрагмента.');}
            }
            fflush($h);$length=(int)fstat($h)['size'];
            return $length<$total?['offset'=>$length,'done'=>false]:self::complete_upload($kind,$path,absint($p['id']??0),$length);
        } finally {flock($h,LOCK_UN);fclose($h);}
    }
    /** Local chunks and remote downloads share exactly the same file validation. */
    public static function complete_upload(string $kind,string $path,int $id,int $length): array {
        global $wpdb;
        if($kind==='catalog'){
            if($length<1||$length>self::CATALOG_MAX){throw new \InvalidArgumentException('Каталог CSV не должен превышать 100 МБ.');}
            $existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('imports').' WHERE file_path=%s',$path));
            if($existing){return ['offset'=>$length,'done'=>true,'id'=>(int)$existing];}
            $f=fopen($path,'rb');if(!$f){throw new \RuntimeException('Не удалось прочитать каталог.');}
            try{$headers=fgetcsv($f,0,';','"','');}finally{fclose($f);}
            if($headers){$headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);}
            if(!$headers||array_diff(['legacy_id','sku','name','assets_json','images_json','pdfs_json'],(array)$headers)){throw new \InvalidArgumentException('Используйте подготовленный catalog.csv в UTF-8 с разделителем «;».');}
            DB::require_result($wpdb->insert(DB::table('imports'),['file_path'=>$path,'headers'=>DB::json($headers),'created_at'=>DB::now(),'updated_at'=>DB::now()]));
            return ['offset'=>$length,'done'=>true,'id'=>(int)$wpdb->insert_id];
        }
        if($length<1){throw new \InvalidArgumentException('ZIP-пакет не должен быть пустым.');}
        $number=self::accept_zip($id,$path);$job=self::status($id);$package=null;foreach($job['packages'] as $candidate){if((int)$candidate['package_no']===$number){$package=$candidate;break;}}
        return ['offset'=>$length,'done'=>true,'id'=>$id,'package'=>$number,'ready'=>$package&&in_array($package['status'],['uploaded','processing','done','done-errors'],true),'parts_received'=>(int)($package['parts_received']??0),'parts_total'=>(int)($package['parts_total']??0),'next_filename'=>$package['next_filename']??''];
    }
    private static function job(int $id): array {
        global $wpdb;$j=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('imports').' WHERE id=%d',$id),ARRAY_A);if(!$j||!is_file($j['file_path'])){throw new \InvalidArgumentException('Каталог импорта не найден. Загрузите CSV заново.');}return $j;
    }
    private static function plan(int $id,array $p): array {
        global $wpdb;$j=self::job($id);if($j['status']!=='uploaded'){throw new \InvalidArgumentException('План уже создан. Для другого размера ZIP используйте «Пересчитать пакеты».');}
        $limit=self::plan_limit($p);$f=fopen($j['file_path'],'rb');fgetcsv($f,0,';','"','');$offset=ftell($f);fclose($f);
        DB::require_result($wpdb->update(DB::table('imports'),['status'=>'planning','plan_bytes'=>$limit,'planner_version'=>self::PLANNER_VERSION,'byte_offset'=>$offset,'current_package'=>1,'package_bytes'=>0,'updated_at'=>DB::now()],['id'=>$id]));return self::status($id);
    }
    private static function plan_limit(array $p): int {
        $mb=filter_var($p['mb']??50,FILTER_VALIDATE_INT);
        if($mb===false||$mb<1||$mb>intdiv(PHP_INT_MAX,1048576)){throw new \InvalidArgumentException('Укажите ориентировочный размер ZIP положительным целым числом в МБ. Фактический размер архива может быть больше.');}
        return $mb*1048576;
    }
    /** Keep the old import and its ZIPs intact; a rebuilt plan has its own ID and tokens. */
    private static function replan(int $id,array $p): array {
        global $wpdb;self::plan_limit($p);$j=self::job($id);$path=self::dir().'/'.get_current_user_id().'-catalog-'.bin2hex(random_bytes(16)).'.part';
        if(!copy($j['file_path'],$path)){@unlink($path);throw new \RuntimeException('Не удалось подготовить каталог для нового плана. Проверьте свободное место.');}
        @chmod($path,0600);
        try{return DB::transaction(function()use($wpdb,$path,$j,$p){
            DB::require_result($wpdb->insert(DB::table('imports'),['file_path'=>$path,'headers'=>$j['headers'],'created_at'=>DB::now(),'updated_at'=>DB::now()]));
            return self::plan((int)$wpdb->insert_id,$p);
        });}catch(\Throwable $e){@unlink($path);throw $e;}
    }
    /** Conservative cost of one unique ZIP member, including the directory and JSON manifest. */
    private static function asset_cost(string $path,int $size): int {
        return $size+(int)ceil($size/16384)*5+512+6*strlen($path);
    }
    private static function plan_step(int $id): array {
        global $wpdb;$j=self::job($id);if($j['status']!=='planning'){return self::status($id);}
        if((int)($j['planner_version']??1)!==self::PLANNER_VERSION){throw new \InvalidArgumentException('Этот план создан старой версией. Нажмите «Пересчитать пакеты», чтобы применить размер к одному архиву.');}
        $headers=json_decode($j['headers'],true);$f=fopen($j['file_path'],'rb');fseek($f,(int)$j['byte_offset']);$limit=(int)floor($j['plan_bytes']*.98)-4096;$number=(int)$j['current_package'];$bytes=(int)$j['package_bytes'];$total=(int)$j['total_rows'];$count=0;$started=microtime(true);$known=[];$asset_table=DB::table('plan_assets');
        foreach($wpdb->get_results($wpdb->prepare("SELECT path_hash,file_size FROM $asset_table WHERE import_id=%d AND package_no=%d",$id,$number),ARRAY_A) as $a){$known[$a['path_hash']]=(int)$a['file_size'];}
        $row_count=(int)$wpdb->get_var($wpdb->prepare('SELECT rows_count FROM '.DB::table('packages').' WHERE import_id=%d AND package_no=%d',$id,$number));
        while($count<150&&($values=fgetcsv($f,0,';','"',''))!==false){
            if(count($values)===1&&trim((string)$values[0])===''){continue;}
            if(count($values)!==count($headers)){fclose($f);throw new \InvalidArgumentException('Число колонок не совпадает в строке '.($total+2).'. Исправьте CSV.');}
            $row=array_combine($headers,$values);if(!trim($row['sku'])||!trim($row['name'])){continue;}
            $assets=json_decode($row['assets_json']??'[]',true);if(!is_array($assets)){fclose($f);throw new \InvalidArgumentException('Некорректный assets_json у '.$row['sku']);}
            $unique=[];foreach($assets as $a){if(!is_array($a)){fclose($f);throw new \InvalidArgumentException('Некорректный список файлов у '.$row['sku']);}$path=self::valid_path((string)($a['path']??''));$size=max(0,(int)($a['size']??0));$hash=hash('sha256',$path);$unique[$hash]=['path'=>$path,'size'=>max($size,$unique[$hash]['size']??0)];}
            $size=0;$delta=0;foreach($unique as $hash=>$a){$cost=self::asset_cost($a['path'],$a['size']);$size+=$cost;$delta+=isset($known[$hash])?max(0,$cost-self::asset_cost($a['path'],$known[$hash])):$cost;}
            // Only the per-archive byte budget splits the catalogue. Products are still processed in small REST batches.
            if($row_count&&$bytes+$delta>$limit){$number++;$bytes=0;$row_count=0;$known=[];$delta=$size;}
            DB::require_result($wpdb->insert(DB::table('import_rows'),['import_id'=>$id,'package_no'=>$number,'legacy_id'=>sanitize_text_field($row['legacy_id']),'row_json'=>DB::json($row),'status'=>'pending']));
            foreach($unique as $hash=>$a){if(!isset($known[$hash])||$a['size']>$known[$hash]){DB::require_result($wpdb->query($wpdb->prepare("INSERT INTO $asset_table (import_id,package_no,path_hash,path,file_size) VALUES (%d,%d,%s,%s,%d) ON DUPLICATE KEY UPDATE file_size=GREATEST(file_size,VALUES(file_size))",$id,$number,$hash,$a['path'],$a['size'])));$known[$hash]=$a['size'];}}
            $bytes+=$delta;$row_count++;$total++;$count++;
            $filename='mik-'.$id.'-'.str_pad((string)$number,4,'0',STR_PAD_LEFT).'.zip';
            $wpdb->query($wpdb->prepare('INSERT INTO '.DB::table('packages').' (import_id,package_no,filename,estimated_bytes,rows_count) VALUES (%d,%d,%s,%d,%d) ON DUPLICATE KEY UPDATE estimated_bytes=VALUES(estimated_bytes),rows_count=VALUES(rows_count)',$id,$number,$filename,$bytes,$row_count));
            if(microtime(true)-$started>6){break;}
        }
        $finished=feof($f);$offset=ftell($f);fclose($f);$wpdb->update(DB::table('imports'),['byte_offset'=>$offset,'current_package'=>$number,'package_bytes'=>$bytes,'total_rows'=>$total,'status'=>$finished?'ready':'planning','updated_at'=>DB::now()],['id'=>$id]);return self::status($id);
    }
    public static function status(int $id): array {
        global $wpdb;if(!$id){return ['jobs'=>$wpdb->get_results('SELECT id,status,total_rows,created_at FROM '.DB::table('imports').' ORDER BY id DESC LIMIT 20',ARRAY_A)];}
        $j=$wpdb->get_row($wpdb->prepare('SELECT id,status,total_rows,plan_bytes,planner_version,created_at FROM '.DB::table('imports').' WHERE id=%d',$id),ARRAY_A);if(!$j){throw new \InvalidArgumentException('Импорт не найден.');}
        foreach(['id','total_rows','plan_bytes','planner_version'] as $key){$j[$key]=(int)$j[$key];}
        $j['packages']=$wpdb->get_results($wpdb->prepare('SELECT package_no,filename,estimated_bytes,rows_count,selected,status,parts_total FROM '.DB::table('packages').' WHERE import_id=%d ORDER BY package_no',$id),ARRAY_A);
        foreach($j['packages'] as &$package){foreach(['package_no','estimated_bytes','rows_count','selected'] as $key){$package[$key]=(int)$package[$key];}}unset($package);
        $j['packages']=ImportParts::decorate($id,$j['packages']);
        $j['estimated_total_bytes']=array_sum(array_column($j['packages'],'estimated_bytes'));
        $j['processed']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('import_rows')." WHERE import_id=%d AND status='done'",$id));
        $j['error_count']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('import_rows')." WHERE import_id=%d AND status='error'",$id));
        $j['errors']=$wpdb->get_results($wpdb->prepare('SELECT id,package_no,legacy_id,error FROM '.DB::table('import_rows')." WHERE import_id=%d AND error IS NOT NULL AND error<>'' ORDER BY id DESC LIMIT 100",$id),ARRAY_A);
        $pending=array_values(array_filter($j['packages'],fn($p)=>$p['selected']&&!in_array($p['status'],['done','done-errors'],true)));$j['next']=$pending[0]??null;
        $j['bat_url']=add_query_arg('_wpnonce',wp_create_nonce('mik_packager'),admin_url('admin-post.php?action=mik_document&kind=packager&id='.$id));return $j;
    }
    private static function select(int $id,array $ids): array {
        global $wpdb;$j=self::job($id);if($j['status']!=='ready'){throw new \InvalidArgumentException('Дождитесь завершения планирования.');}
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));if(!$ids){throw new \InvalidArgumentException('Выберите хотя бы один пакет.');}
        $wpdb->update(DB::table('packages'),['selected'=>0],['import_id'=>$id]);foreach($ids as $n){$wpdb->update(DB::table('packages'),['selected'=>1],['import_id'=>$id,'package_no'=>$n]);}return self::status($id);
    }
    private static function token(array $j,int $number): string { return hash_hmac('sha256',$j['id'].'|'.$number.'|'.hash_file('sha256',$j['file_path']),wp_salt('auth')); }
    public static function bat(int $id): string {
        global $wpdb;$j=self::job($id);if($j['status']!=='ready'){throw new \InvalidArgumentException('План ещё не готов.');}
        $packages=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('packages').' WHERE import_id=%d AND selected=1 ORDER BY package_no',$id),ARRAY_A);$plan=['job_id'=>$id,'max_bytes'=>(int)$j['plan_bytes'],'image_extensions'=>self::IMAGE_EXTENSIONS,'size_mode'=>'estimate','packages'=>[]];
        foreach($packages as $p){
            $files=[];$offset=0;
            if((int)($j['planner_version']??1)>=self::PLANNER_VERSION){$files=$wpdb->get_results($wpdb->prepare('SELECT path,file_size AS size FROM '.DB::table('plan_assets').' WHERE import_id=%d AND package_no=%d ORDER BY path_hash',$id,$p['package_no']),ARRAY_A);foreach($files as &$file){$file['size']=(int)$file['size'];}unset($file);}
            else{do{$rows=$wpdb->get_col($wpdb->prepare('SELECT row_json FROM '.DB::table('import_rows').' WHERE import_id=%d AND package_no=%d ORDER BY id LIMIT 200 OFFSET %d',$id,$p['package_no'],$offset));foreach($rows as $raw){$row=json_decode($raw,true);foreach(json_decode($row['assets_json']??'[]',true)?:[] as $a){$path=self::valid_path($a['path']);$files[$path]=['path'=>$path,'size'=>(int)$a['size']];}}$offset+=200;}while(count($rows)===200);}
            $plan['packages'][]=['number'=>(int)$p['package_no'],'filename'=>$p['filename'],'token'=>self::token($j,(int)$p['package_no']),'files'=>array_values($files)];
        }
        $payload=base64_encode(gzencode(DB::json($plan),9));$ps=file_get_contents(MIK_PATH.'assets/packager.ps1');
        $loader='$self=$env:MIK_SELF; $raw=[IO.File]::ReadAllText($self); $parts=$raw -split "(?m)^::MIK_DATA\\r?$",2; $bytes=[Convert]::FromBase64String($parts[1].Trim()); $ms=New-Object IO.MemoryStream(,$bytes); $gz=New-Object IO.Compression.GzipStream($ms,[IO.Compression.CompressionMode]::Decompress); $reader=New-Object IO.StreamReader($gz,[Text.Encoding]::UTF8); $plan=$reader.ReadToEnd() | ConvertFrom-Json; $script=$raw -split "(?m)^::MIK_PS\\r?$",2; $body=($script[1] -split "(?m)^::MIK_DATA\\r?$",2)[0]; & ([ScriptBlock]::Create($body)) $plan ([IO.Path]::GetDirectoryName($self))';
        $encoded=base64_encode(mb_convert_encoding($loader,'UTF-16LE','UTF-8'));
        return "@echo off\r\nsetlocal DisableDelayedExpansion\r\nset \"MIK_SELF=%~f0\"\r\npowershell.exe -NoLogo -NoProfile -EncodedCommand $encoded\r\nset \"MIK_EXIT=%ERRORLEVEL%\"\r\npause\r\nexit /b %MIK_EXIT%\r\n::MIK_PS\r\n".str_replace("\n","\r\n",$ps)."\r\n::MIK_DATA\r\n".$payload."\r\n";
    }
    private static function valid_path(string $path): string {
        $path=str_replace('\\','/',$path);$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        if($path===''||str_starts_with($path,'/')||str_contains($path,':')||str_contains($path,"\0")||preg_match('~(?:^|/)\.\.(?:/|$)~',$path)||!in_array($ext,array_merge(self::IMAGE_EXTENSIONS,['pdf']),true)){throw new \InvalidArgumentException('Недопустимый путь медиафайла: '.sanitize_text_field($path));}return $path;
    }
    private static function accept_zip(int $id,string $path): int {
        global $wpdb;if(!class_exists('ZipArchive')){throw new \RuntimeException('На хостинге необходимо включить PHP ZipArchive.');}$j=self::job($id);$z=new \ZipArchive();if($z->open($path)!==true){throw new \InvalidArgumentException('Повреждён ZIP.');}
        try{
            $stat=$z->statName('manifest.json');if(!$stat||$stat['size']>self::MANIFEST_MAX){throw new \InvalidArgumentException('В ZIP отсутствует допустимый manifest.json.');}
            $m=json_decode($z->getFromName('manifest.json'),true);if(!is_array($m)||!is_array($m['files']??null)||!is_array($m['aliases']??[])){throw new \InvalidArgumentException('Некорректный манифест ZIP.');}$n=absint($m['package_no']??0);
            foreach(($m['aliases']??[]) as $requested=>$actual){if(!is_string($requested)||!is_string($actual)||!self::same_image_name(self::valid_path($requested),self::valid_path($actual))||!isset($m['files'][$actual])){throw new \InvalidArgumentException('Некорректная замена расширения изображения в ZIP.');}}
            if((int)($m['job_id']??0)!==$id||!hash_equals(self::token($j,$n),(string)($m['token']??''))){throw new \InvalidArgumentException('Этот пакет не принадлежит выбранному импорту.');}
            $p=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('packages').' WHERE import_id=%d AND package_no=%d',$id,$n),ARRAY_A);
            if(!$p||!$p['selected']){throw new \InvalidArgumentException('Пакет не выбран в плане.');}
            $size=0;if($z->numFiles>20000){throw new \InvalidArgumentException('Слишком много файлов в ZIP.');}
            for($i=0;$i<$z->numFiles;$i++){
                $s=$z->statIndex($i);$name=$s['name'];if($name!=='manifest.json'){self::valid_path($name);if(!isset($m['files'][$name])){throw new \InvalidArgumentException('Лишний файл в пакете.');}}
                $size+=$s['size'];if($s['size']>1048576&&$s['comp_size']>0&&$s['size']/$s['comp_size']>500){throw new \InvalidArgumentException('Недопустимая степень сжатия ZIP.');}
                if($z->getExternalAttributesIndex($i,$opsys,$attrs)&&($attrs>>16&0170000)===0120000){throw new \InvalidArgumentException('Символические ссылки запрещены.');}
            }
            foreach($m['files'] as $actual=>$file){self::valid_path((string)$actual);$entry=$z->statName($actual);if(!is_array($file)||!$entry||!preg_match('/^[a-f0-9]{64}$/D',(string)($file['sha256']??''))||(int)($file['size']??-1)!==(int)$entry['size']){throw new \InvalidArgumentException('Некорректные сведения о файле: '.sanitize_text_field((string)$actual));}}
            if((int)($m['version']??1)===3){ImportParts::register($id,$p,$path,$m);return $n;}
            self::accept_whole($id,$n,$path);return $n;
        }finally{$z->close();}
    }
    /** A complete archive replaces an unprocessed multipart transfer of the same planned package. */
    private static function accept_whole(int $id,int $number,string $path): void {
        global $wpdb;
        $unused=self::lock($id,fn()=>DB::transaction(function()use($wpdb,$id,$number,$path){
            $table=DB::table('packages');$p=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE import_id=%d AND package_no=%d FOR UPDATE",$id,$number),ARRAY_A);
            if(!$p||!$p['selected']){throw new \InvalidArgumentException('Пакет не выбран в плане.');}
            if(in_array($p['status'],['done','done-errors'],true)){return [];}
            if($p['status']==='processing'){if($p['zip_path']===$path){return [];}throw new \InvalidArgumentException('Этот пакет уже обрабатывается. Для изменения его файлов создайте новый план.');}
            $old=$wpdb->get_col($wpdb->prepare('SELECT zip_path FROM '.DB::table('package_parts').' WHERE import_id=%d AND package_no=%d',$id,$number));if(!empty($p['zip_path'])){$old[]=$p['zip_path'];}
            DB::require_result($wpdb->delete(DB::table('package_media'),['import_id'=>$id,'package_no'=>$number]));DB::require_result($wpdb->delete(DB::table('package_parts'),['import_id'=>$id,'package_no'=>$number]));
            DB::require_result($wpdb->update($table,['zip_path'=>$path,'status'=>'uploaded','parts_total'=>0,'part_set'=>''],['import_id'=>$id,'package_no'=>$number]));return $old;
        }));
        $directory=self::dir().'/';foreach(array_unique($unused) as $old){if($old!==$path&&str_starts_with($old,$directory)&&is_file($old)){@unlink($old);}}
    }
    private static function step(int $id,int $number): array {
        global $wpdb;$j=self::job($id);$p=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('packages').' WHERE import_id=%d AND package_no=%d',$id,$number),ARRAY_A);
        if($p&&$p['selected']&&in_array($p['status'],['done','done-errors'],true)){return self::status($id);}
        if(!$p||!$p['selected']||!in_array($p['status'],['uploaded','processing'],true)){throw new \InvalidArgumentException('Сначала загрузите ZIP выбранного пакета.');}
        $files=new ImportFiles($id,$p);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('import_rows')." WHERE import_id=%d AND package_no=%d AND status='pending' ORDER BY id LIMIT 8",$id,$number),ARRAY_A);$start=microtime(true);
        $wpdb->update(DB::table('packages'),['status'=>'processing'],['import_id'=>$id,'package_no'=>$number]);
        try{
            foreach($rows as $entry){
                try{$warnings=[];self::import_product(json_decode($entry['row_json'],true),$files,$warnings);$wpdb->update(DB::table('import_rows'),['status'=>'done','error'=>$warnings?implode('; ',$warnings):null],['id'=>$entry['id']]);}
                catch(\Throwable $e){$wpdb->update(DB::table('import_rows'),['status'=>'error','error'=>mb_substr($e->getMessage(),0,4000)],['id'=>$entry['id']]);}
                if(microtime(true)-$start>8){break;}
            }
        }finally{$files->close();}
        $remaining=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('import_rows')." WHERE import_id=%d AND package_no=%d AND status='pending'",$id,$number));
        if(!$remaining){$errors=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('import_rows')." WHERE import_id=%d AND package_no=%d AND status='error'",$id,$number));$wpdb->update(DB::table('packages'),['status'=>$errors?'done-errors':'done'],['import_id'=>$id,'package_no'=>$number]);}
        return self::status($id);
    }
    private static function same_image_name(string $requested,string $actual): bool {
        $lower=fn($value)=>mb_strtolower($value,'UTF-8');if($lower($requested)===$lower($actual)){return true;}
        $from=strtolower(pathinfo($requested,PATHINFO_EXTENSION));$to=strtolower(pathinfo($actual,PATHINFO_EXTENSION));
        return in_array($from,self::IMAGE_EXTENSIONS,true)&&in_array($to,self::IMAGE_EXTENSIONS,true)&&$lower(substr($requested,0,-strlen($from)-1))===$lower(substr($actual,0,-strlen($to)-1));
    }
    /** Keep the CSV path as the lookup key while reading the image under its real ZIP name. */
    public static function resolve_media(string $path,\ZipArchive $z,array $m): ?string {
        if(isset($m['files'][$path])&&$z->locateName($path)!==false){return $path;}
        $alias=$m['aliases'][$path]??null;
        if(is_string($alias)){$alias=self::valid_path($alias);if(self::same_image_name($path,$alias)&&isset($m['files'][$alias])&&$z->locateName($alias)!==false){return $alias;}}
        $extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));$extensions=in_array($extension,self::IMAGE_EXTENSIONS,true)?array_unique(array_merge([$extension],self::IMAGE_EXTENSIONS)):[$extension];
        $stem=substr($path,0,-strlen($extension)-1);
        foreach($extensions as $candidate_extension){$index=$z->locateName($stem.'.'.$candidate_extension,\ZipArchive::FL_NOCASE);if($index!==false){$candidate=$z->getNameIndex($index);if(is_string($candidate)&&isset($m['files'][$candidate])&&self::same_image_name($path,$candidate)){return self::valid_path($candidate);}}}
        return null;
    }
    /** Recognise bytes rather than trusting a stale extension; convert legacy image formats when available. */
    private static function prepare_image(string $tmp,string $source_path): array {
        $mime=wp_get_image_mime($tmp);
        if(!$mime&&class_exists('finfo')){$detector=new \finfo(FILEINFO_MIME_TYPE);$mime=$detector->file($tmp);}
        $direct=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/avif'=>'avif'];
        $legacy=['image/bmp','image/x-bmp','image/x-ms-bmp','image/tiff','image/x-tiff','image/vnd.microsoft.icon','image/x-icon','image/ico','image/icon','image/heic','image/heif','image/heic-sequence','image/heif-sequence'];
        if(!isset($direct[$mime?:''])&&!in_array($mime,$legacy,true)){throw new \InvalidArgumentException('Файл не является поддерживаемым изображением: '.$source_path);}
        $dims=wp_getimagesize($tmp);
        if(isset($direct[$mime])&&$dims&&$dims[0]>0&&$dims[1]>0){return ['path'=>$tmp,'ext'=>$direct[$mime],'mime'=>$mime,'dims'=>$dims];}
        require_once ABSPATH.'wp-admin/includes/image.php';$editor=wp_get_image_editor($tmp,['mime_type'=>$mime]);
        if(is_wp_error($editor)){throw new \RuntimeException('Изображение найдено, но хостинг не поддерживает чтение или преобразование '.$mime.': '.$source_path.'. Сохраните его в JPG, PNG или WebP.');}
        $converted=$tmp.'.png';
        try{
            $saved=$editor->save($converted,'image/png');if(is_wp_error($saved)){throw new \RuntimeException('Не удалось преобразовать изображение в PNG: '.$source_path);}
            $converted=$saved['path'];$dims=wp_getimagesize($converted);if(!$dims||($dims['mime']??'')!=='image/png'){throw new \RuntimeException('Не удалось получить PNG после преобразования: '.$source_path);}
            return ['path'=>$converted,'ext'=>'png','mime'=>'image/png','dims'=>$dims];
        }catch(\Throwable $e){@unlink($converted);throw $e;}
    }
    private static function media(string $path,ImportFiles $files,array &$warnings): int {
        global $wpdb;$requested=self::valid_path($path);$resolved=$files->find($requested);
        if($resolved===null){$warnings[]='Нет файла: '.$requested;return 0;}$path=$resolved['path'];$expected=$resolved['expected'];$z=$resolved['zip'];
        $sha=(string)($expected['sha256']??'');if(!preg_match('/^[a-f0-9]{64}$/',$sha)){throw new \InvalidArgumentException('Некорректная контрольная сумма файла.');}
        $old=(int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_mik_asset_sha256' AND meta_value=%s LIMIT 1",$sha));if($old&&get_post_type($old)==='attachment'&&is_file(get_attached_file($old))){return $old;}
        $source=$z->getStream($path);if(!$source){throw new \RuntimeException('Не удалось прочитать файл ZIP.');}$tmp=tempnam(self::dir(),'asset-');$target=fopen($tmp,'wb');stream_copy_to_stream($source,$target);fclose($source);fclose($target);$stored=$tmp;
        try{
            if(!hash_equals($sha,hash_file('sha256',$tmp))){throw new \RuntimeException('Контрольная сумма не совпала: '.$path);}
            $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$mime='';$dims=null;
            if($ext==='pdf'){$f=fopen($tmp,'rb');$head=fread($f,5);fclose($f);if($head!=='%PDF-'){throw new \InvalidArgumentException('Файл не является PDF: '.$path);}$mime='application/pdf';}
            else{$image=self::prepare_image($tmp,$path);$ext=$image['ext'];$mime=$image['mime'];$dims=$image['dims'];$stored=$image['path'];}
            $upload=wp_upload_dir();if($upload['error']){throw new \RuntimeException($upload['error']);}$dir=$upload['basedir'].'/mik-media';if(!wp_mkdir_p($dir)){throw new \RuntimeException('Не удалось создать папку медиа.');}$dest=$dir.'/'.$sha.'.'.$ext;
            if(!is_file($dest)&&!copy($stored,$dest)){throw new \RuntimeException('Не удалось сохранить медиафайл.');}
            $attachment=wp_insert_attachment(['post_title'=>sanitize_text_field(pathinfo($path,PATHINFO_FILENAME)),'post_mime_type'=>$mime,'post_status'=>'inherit'],$dest,0,true);if(is_wp_error($attachment)){throw new \RuntimeException($attachment->get_error_message());}
            update_post_meta($attachment,'_mik_asset_sha256',$sha);update_post_meta($attachment,'_mik_source_path',$path);
            if($requested!==$path){update_post_meta($attachment,'_mik_requested_path',$requested);}
            if($dims){wp_update_attachment_metadata($attachment,['width'=>$dims[0],'height'=>$dims[1],'file'=>'mik-media/'.basename($dest),'sizes'=>[]]);}
            return (int)$attachment;
        }finally{if($stored!==$tmp){@unlink($stored);}@unlink($tmp);}
    }
    private static function import_product(array $r,ImportFiles $files,array &$warnings): void {
        global $wpdb;$legacy=sanitize_text_field($r['legacy_id']??'');$sku=sanitize_text_field($r['sku']??'');$name=sanitize_text_field($r['name']??'');if($sku===''||$name===''){throw new \InvalidArgumentException('Нет артикула или названия.');}
        $id=$legacy!==''?(int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_mik_legacy_id' AND meta_value=%s LIMIT 1",$legacy)):0;
        if(!$id){$id=(int)wc_get_product_id_by_sku($sku);}$product=$id?wc_get_product($id):new \WC_Product_Simple();if(!$product||!$product->is_type('simple')){throw new \InvalidArgumentException('SKU занят товаром другого типа: '.$sku);}
        $images=[];$pdfs=[];foreach(json_decode($r['images_json']??'[]',true)?:[] as $path){$a=self::media($path,$files,$warnings);if($a){$images[]=$a;}}foreach(json_decode($r['pdfs_json']??'[]',true)?:[] as $path){$a=self::media($path,$files,$warnings);if($a){$pdfs[]=$a;}}
        $product->set_name($name);$product->set_sku($sku);$product->set_status('publish');$product->set_description(wp_kses_post($r['description']??''));$product->set_short_description(wp_kses_post($r['short_description']??''));$product->set_manage_stock(true);$stock=max(0,(int)($r['stock']??0));$product->set_stock_quantity($stock);$product->set_stock_status($stock?'instock':'outofstock');$product->set_regular_price(is_numeric($r['price_rub']??'')?wc_format_decimal($r['price_rub']):'');$product->set_sale_price('');
        $product->set_featured(!empty($r['featured']));if($images){$product->set_image_id($images[0]);$product->set_gallery_image_ids(array_slice(array_unique($images),1));}
        $category=sanitize_text_field($r['category']??'Прочие компоненты');$term=term_exists($category,'product_cat');if(!$term){$term=wp_insert_term($category,'product_cat');}if(!is_wp_error($term)){$product->set_category_ids([(int)(is_array($term)?$term['term_id']:$term)]);}
        $id=$product->save();if(!$id){throw new \RuntimeException('Не удалось сохранить товар.');}
        update_post_meta($id,'_mik_legacy_id',$legacy);update_post_meta($id,'_mik_source_sku',sanitize_text_field($r['source_sku']??$sku));update_post_meta($id,'_mik_source_url',esc_url_raw($r['source_url']??''));update_post_meta($id,'_mik_ozon_url',esc_url_raw($r['ozon_url']??''));update_post_meta($id,'_mik_stock_note',sanitize_text_field($r['stock_note']??''));update_post_meta($id,'_mik_special_price',is_numeric($r['price_special']??'')?(float)$r['price_special']:'');
        if($pdfs){update_post_meta($id,'_mik_pdfs',array_values(array_unique($pdfs)));}Catalog::set_specs($id,json_decode($r['specs_json']??'[]',true)?:[]);Catalog::sync_stock($id);
        if(isset($r['price_wholesale'])&&is_numeric($r['price_wholesale'])){$wpdb->replace(DB::table('prices'),['product_id'=>$id,'group_id'=>3,'price'=>(float)$r['price_wholesale']]);}
    }
}
