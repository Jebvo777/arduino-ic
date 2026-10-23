<?php
namespace MIK;
defined('ABSPATH') || exit;

/** Private, resumable URL downloads. The REST routes require a shop administrator nonce. */
class RemoteImport {
    private const WINDOW=2097152;
    public static function dispatch(string $action,array $p): array {
        if($action==='start'){return self::start($p);}
        $token=(string)($p['token']??'');self::paths($token);
        $lock=fopen(self::paths($token)['lock'],'c');
        if(!$lock){throw new \RuntimeException('Не удалось открыть файл загрузки.');}
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new \RuntimeException('Этот файл уже скачивается. Дождитесь текущего запроса.');}
        try {
            $s=self::read($token);
            if($action==='status'){return self::public_state($s);}
            if($action==='cancel'){
                if(empty($s['done'])){foreach(['part','chunk','meta'] as $key){@unlink(self::paths($token)[$key]);}}
                return ['cancelled'=>empty($s['done']),'done'=>!empty($s['done'])];
            }
            if($action!=='step'){throw new \InvalidArgumentException('Неизвестное действие скачивания.');}
            if(!empty($s['done'])){return self::public_state($s);}
            return self::step($s);
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function paths(string $token): array {
        if(!preg_match('/^[a-f0-9]{32}$/D',$token)){throw new \InvalidArgumentException('Недопустимый ID скачивания.');}
        $base=Importer::dir().'/'.get_current_user_id().'-remote-'.$token;
        return ['part'=>$base.'.part','chunk'=>$base.'.chunk','meta'=>$base.'.json','lock'=>$base.'.lock'];
    }
    private static function start(array $p): array {
        $kind=(string)($p['kind']??'package');if(!in_array($kind,['catalog','package'],true)){throw new \InvalidArgumentException('Недопустимый тип файла.');}
        $id=absint($p['id']??0);
        if($kind==='package'){$job=Importer::status($id);if(($job['status']??'')!=='ready'){throw new \InvalidArgumentException('Сначала загрузите каталог и рассчитайте пакеты.');}}
        $url=self::url((string)($p['url']??''));$token=bin2hex(random_bytes(16));$path=(string)wp_parse_url($url,PHP_URL_PATH);$name=sanitize_file_name(rawurldecode(basename($path)));
        $s=['token'=>$token,'owner'=>get_current_user_id(),'kind'=>$kind,'import_id'=>$id,'url'=>$url,'name'=>$name?:($kind==='catalog'?'catalog.csv':'package.zip'),'offset'=>0,'total'=>0,'window'=>self::WINDOW,'mode'=>'range','etag'=>'','modified'=>'','created'=>time(),'updated'=>time(),'done'=>false];
        self::save($s);return self::public_state($s);
    }
    private static function url(string $value): string {
        $url=esc_url_raw(trim($value),['http','https']);$parts=wp_parse_url($url);
        if(!$url||!is_array($parts)||!empty($parts['user'])||!empty($parts['pass'])||!wp_http_validate_url($url)){throw new \InvalidArgumentException('Нужна публичная прямая HTTP(S)-ссылка на файл без авторизации.');}
        return $url;
    }
    private static function read(string $token): array {
        $path=self::paths($token)['meta'];$s=is_file($path)?json_decode(file_get_contents($path),true):null;
        if(!is_array($s)||(int)($s['owner']??0)!==get_current_user_id()||($s['token']??'')!==$token){throw new \InvalidArgumentException('Скачивание не найдено. Вставьте ссылку заново.');}
        if(time()-(int)$s['updated']>259200&&!$s['done']){throw new \InvalidArgumentException('Срок сохранённой загрузки истёк. Вставьте ссылку заново.');}
        return $s;
    }
    private static function save(array $s): void {
        $path=self::paths($s['token'])['meta'];$tmp=$path.'.tmp';$s['updated']=time();
        if(file_put_contents($tmp,DB::json($s),LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Не удалось сохранить прогресс скачивания.');}
        @chmod($path,0600);
    }
    private static function public_state(array $s): array {
        $r=['token'=>$s['token'],'name'=>$s['name'],'kind'=>$s['kind'],'offset'=>(int)$s['offset'],'total'=>(int)$s['total'],'done'=>!empty($s['done']),'mode'=>$s['mode']];
        if(!empty($s['result'])){$r=array_merge($r,$s['result']);}
        return $r;
    }
    private static function header($response,string $name): string {
        $v=wp_remote_retrieve_header($response,$name);return is_array($v)?implode(',',$v):(string)$v;
    }
    private static function step(array $s): array {
        $paths=self::paths($s['token']);$max=$s['kind']==='catalog'?Importer::CATALOG_MAX:Importer::PACKAGE_MAX;
        clearstatcache(true,$paths['part']);$offset=is_file($paths['part'])?(int)filesize($paths['part']):0;$s['offset']=$offset;
        if($offset>$max){throw new \InvalidArgumentException('Файл превышает допустимый размер.');}
        if($s['mode']==='stream'){return self::whole($s,$paths,$max);}
        $window=max(1048576,min(8388608,(int)$s['window']));$headers=['Range'=>'bytes='.$offset.'-'.($offset+$window-1),'Accept-Encoding'=>'identity'];
        if($offset){if($s['etag']&&!str_starts_with($s['etag'],'W/')){$headers['If-Range']=$s['etag'];}elseif($s['modified']){$headers['If-Range']=$s['modified'];}}
        $started=microtime(true);$r=wp_safe_remote_get(self::url($s['url']),['timeout'=>20,'redirection'=>3,'reject_unsafe_urls'=>true,'sslverify'=>true,'headers'=>$headers,'decompress'=>false,'stream'=>true,'filename'=>$paths['chunk'],'limit_response_size'=>$window+1]);
        if(is_wp_error($r)){@unlink($paths['chunk']);throw new \RuntimeException('Не удалось скачать фрагмент. Повторите продолжение загрузки или используйте файл с компьютера.');}
        $code=(int)wp_remote_retrieve_response_code($r);clearstatcache(true,$paths['chunk']);$bytes=is_file($paths['chunk'])?(int)filesize($paths['chunk']):0;
        try {
            if($code===416&&preg_match('~^bytes\s+\*/(\d+)$~i',trim(self::header($r,'content-range')),$m)&&(int)$m[1]===$offset&&$offset>0){$s['total']=$offset;return self::finish($s,$paths,$max);}
            if(!in_array($code,[200,206],true)){throw new \InvalidArgumentException('Источник вернул HTTP '.$code.'. Используйте общедоступную ссылку для скачивания файла.');}
            if(stripos(self::header($r,'content-type'),'text/html')!==false){throw new \InvalidArgumentException('По ссылке открывается веб-страница. Укажите прямую ссылку на CSV или ZIP, а не страницу просмотра файла.');}
            $etag=self::header($r,'etag');$modified=self::header($r,'last-modified');
            if($offset&&(($etag&&$s['etag']&&$etag!==$s['etag'])||($modified&&$s['modified']&&$modified!==$s['modified']))){throw new \InvalidArgumentException('Файл по ссылке изменился во время скачивания. Начните загрузку заново.');}
            if(!$s['etag']){$s['etag']=$etag;}if(!$s['modified']){$s['modified']=$modified;}
            if($code===200){
                if($offset){throw new \InvalidArgumentException('Источник перестал поддерживать продолжение скачивания. Загрузите этот ZIP с компьютера.');}
                $length=self::header($r,'content-length');$total=ctype_digit($length)?(int)$length:0;
                if($total>$max){throw new \InvalidArgumentException($s['kind']==='catalog'?'CSV по ссылке превышает 100 МБ.':'ZIP по ссылке превышает 1 ГБ. Используйте отдельный пакет импорта.');}
                if($bytes>$window||($total&&$total>$bytes)){
                    // Servers without Range support still work through a bounded streaming download.
                    $s['mode']='stream';$s['total']=$total;self::save($s);return self::public_state($s);
                }
                if(!$bytes){throw new \InvalidArgumentException('По ссылке получен пустой файл.');}
                if(!@rename($paths['chunk'],$paths['part'])){throw new \RuntimeException('Не удалось сохранить скачанный файл.');}
                $s['offset']=$bytes;$s['total']=$bytes;return self::finish($s,$paths,$max);
            }
            if(!preg_match('~^bytes\s+(\d+)-(\d+)/(\d+|\*)$~i',trim(self::header($r,'content-range')),$m)){throw new \InvalidArgumentException('Источник вернул некорректный диапазон файла. Используйте другую ссылку или загрузку с компьютера.');}
            $start=(int)$m[1];$end=(int)$m[2];$total=$m[3]==='*'?0:(int)$m[3];
            if($start!==$offset||$end<$start||$end-$start+1!==$bytes||$bytes>$window||!$bytes){throw new \RuntimeException('Скачанный фрагмент неполный. Нажмите «Продолжить скачивание».');}
            if($total>$max||$offset+$bytes>$max){throw new \InvalidArgumentException('Файл по ссылке превышает лимит: '.($s['kind']==='catalog'?'100 МБ для CSV.':'1 ГБ для ZIP.'));}
            if($total&&$end>=$total){throw new \InvalidArgumentException('Источник сообщил неверный размер файла.');}
            if($s['total']&&$total&&(int)$s['total']!==$total){throw new \InvalidArgumentException('Размер файла изменился. Начните скачивание заново.');}
            if($total){$s['total']=$total;}
            $dest=fopen($paths['part'],'c+b');$src=fopen($paths['chunk'],'rb');
            if(!$dest||!$src){if($dest)fclose($dest);if($src)fclose($src);throw new \RuntimeException('Не удалось сохранить фрагмент. Проверьте свободное место.');}
            try {fseek($dest,$offset);$written=stream_copy_to_stream($src,$dest);if($written!==$bytes){ftruncate($dest,$offset);throw new \RuntimeException('Недостаточно места для скачивания.');}fflush($dest);}finally{fclose($src);fclose($dest);}
            $s['offset']=$offset+$bytes;$elapsed=microtime(true)-$started;
            if($elapsed<3){$s['window']=min(8388608,$window*2);}elseif($elapsed>10){$s['window']=max(1048576,(int)floor($window/2));}
            self::save($s);
            if($s['total']&&$s['offset']===$s['total']){return self::finish($s,$paths,$max);}
            return self::public_state($s);
        } finally {@unlink($paths['chunk']);}
    }
    private static function whole(array $s,array $paths,int $max): array {
        $r=wp_safe_remote_get(self::url($s['url']),['timeout'=>20,'redirection'=>3,'reject_unsafe_urls'=>true,'sslverify'=>true,'headers'=>['Accept-Encoding'=>'identity'],'decompress'=>false,'stream'=>true,'filename'=>$paths['chunk'],'limit_response_size'=>$max+1]);
        try {
            if(is_wp_error($r)){throw new \RuntimeException('Источник не успел передать файл целиком и не поддерживает продолжение. Используйте загрузку с компьютера или ссылку с поддержкой частичного скачивания.');}
            if((int)wp_remote_retrieve_response_code($r)!==200){throw new \InvalidArgumentException('Не удалось получить файл. Нужна прямая общедоступная ссылка.');}
            if(stripos(self::header($r,'content-type'),'text/html')!==false){throw new \InvalidArgumentException('Вместо файла источник передал HTML-страницу.');}
            clearstatcache(true,$paths['chunk']);$size=is_file($paths['chunk'])?(int)filesize($paths['chunk']):0;$length=self::header($r,'content-length');
            if(!$size||$size>$max){throw new \InvalidArgumentException('Размер скачанного файла выходит за допустимый лимит.');}
            if(ctype_digit($length)&&(int)$length!==$size){throw new \RuntimeException('Файл скачан не полностью. Используйте загрузку с компьютера.');}
            if(!@rename($paths['chunk'],$paths['part'])){throw new \RuntimeException('Не удалось сохранить скачанный файл.');}
            $s['offset']=$size;$s['total']=$size;return self::finish($s,$paths,$max);
        } finally {@unlink($paths['chunk']);}
    }
    private static function finish(array $s,array $paths,int $max): array {
        clearstatcache(true,$paths['part']);$size=is_file($paths['part'])?(int)filesize($paths['part']):0;
        if(!$size||$size>$max){throw new \InvalidArgumentException('Скачанный файл пуст или превышает допустимый размер.');}
        $s['offset']=$size;$s['total']=$size;
        $s['result']=Importer::complete_upload($s['kind'],$paths['part'],(int)$s['import_id'],$size);$s['done']=true;self::save($s);
        return self::public_state($s);
    }
}
