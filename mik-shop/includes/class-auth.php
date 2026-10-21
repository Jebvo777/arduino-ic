<?php
namespace MIK;
defined('ABSPATH') || exit;

class Auth {
    private static ?array $session=null;
    public static function session(bool $create=true): ?array {
        global $wpdb;
        if(self::$session!==null){return self::$session;}
        $token=(string)($_COOKIE['mik_session']??'');$t=DB::table('sessions');
        if(preg_match('/^[a-f0-9]{64}$/',$token)){
            $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE token_hash=%s AND expires_at>%s",hash('sha256',$token),DB::now()),ARRAY_A);
            if($s){self::$session=$s;return $s;}
        }
        return $create?self::create(0):null;
    }
    private static function create(int $customer): array {
        global $wpdb;$token=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));
        $s=['token_hash'=>hash('sha256',$token),'csrf'=>$csrf,'customer_id'=>$customer,'expires_at'=>gmdate('Y-m-d H:i:s',time()+14*DAY_IN_SECONDS),'created_at'=>DB::now()];
        DB::require_result($wpdb->insert(DB::table('sessions'),$s));$s['id']=(int)$wpdb->insert_id;
        setcookie('mik_session',$token,['expires'=>time()+14*DAY_IN_SECONDS,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
        $_COOKIE['mik_session']=$token;self::$session=$s;return $s;
    }
    public static function customer(): ?array {
        global $wpdb;$s=self::session(false);if(!$s||!$s['customer_id']){return null;}
        $c=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('customers').' WHERE id=%d AND status=%s',$s['customer_id'],'active'),ARRAY_A);
        if(!$c){return null;}
        return $c;
    }
    public static function public_customer(?array $c): ?array {
        if(!$c){return null;}
        $c['billing']=json_decode($c['billing']??'{}',true)?:[];
        unset($c['password'],$c['reset_hash'],$c['reset_expires']);return $c;
    }
    public static function owner(): string { $s=self::session();return $s['customer_id']?'c:'.$s['customer_id']:'s:'.$s['id']; }
    public static function csrf(\WP_REST_Request $r): bool {
        $s=self::session(false);
        $origin=$r->get_header('origin');
        if($origin&&strtolower((string)wp_parse_url($origin,PHP_URL_HOST))!==strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST))){return false;}
        return $s&&hash_equals($s['csrf'],(string)$r->get_header('x-mik-csrf'));
    }
    public static function limit(string $purpose,int $max,int $seconds): void {
        global $wpdb;$ip=$_SERVER['REMOTE_ADDR']??'unknown';$bucket=$purpose.':'.hash_hmac('sha256',$ip,wp_salt('auth')).':'.(int)floor(time()/$seconds);$t=DB::table('limits');
        $wpdb->query($wpdb->prepare("INSERT INTO $t (bucket,hits,expires_at) VALUES (%s,1,%s) ON DUPLICATE KEY UPDATE hits=hits+1",$bucket,gmdate('Y-m-d H:i:s',time()+$seconds)));
        if((int)$wpdb->get_var($wpdb->prepare("SELECT hits FROM $t WHERE bucket=%s",$bucket))>$max){throw new \RuntimeException('Слишком много попыток. Повторите позже.');}
    }
    public static function register(array $p): array {
        global $wpdb;self::limit('register',8,3600);
        $email=strtolower(sanitize_email($p['email']??''));$name=sanitize_text_field($p['name']??'');$phone=sanitize_text_field($p['phone']??'');$pass=(string)($p['password']??'');
        if(!is_email($email)||mb_strlen($name)<2||strlen(preg_replace('/\D/','',$phone))<10){throw new \InvalidArgumentException('Укажите имя, корректный email и телефон.');}
        if(strlen($pass)<12||strlen($pass)>200){throw new \InvalidArgumentException('Пароль должен содержать от 12 до 200 символов.');}
        if(empty($p['consent'])){throw new \InvalidArgumentException('Подтвердите согласие на обработку данных.');}
        $billing=self::billing((array)($p['billing']??[]));$type=($p['type']??'')==='legal'?'legal':'physical';
        if($type==='legal'&&(!$billing['company']||!preg_match('/^\d{10}(\d{2})?$/',$billing['inn']))){throw new \InvalidArgumentException('Для юридического лица укажите организацию и ИНН.');}
        $data=['email'=>$email,'password'=>wp_hash_password($pass),'name'=>$name,'phone'=>$phone,'type'=>$type,'group_id'=>1,'status'=>'active','address'=>sanitize_textarea_field($p['address']??''),'billing'=>DB::json($billing),'created_at'=>DB::now(),'updated_at'=>DB::now()];
        if($wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('customers').' WHERE email=%s',$email))){throw new \InvalidArgumentException('Не удалось зарегистрировать этот email. Войдите или восстановите пароль.');}
        DB::require_result($wpdb->insert(DB::table('customers'),$data));$id=(int)$wpdb->insert_id;
        self::authenticate($id);return self::public_customer(self::customer());
    }
    public static function login(array $p): array {
        global $wpdb;self::limit('login',20,900);$email=strtolower(sanitize_email($p['email']??''));
        $c=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('customers').' WHERE email=%s',$email),ARRAY_A);
        if(!$c||!wp_check_password((string)($p['password']??''),$c['password'])||$c['status']!=='active'){throw new \InvalidArgumentException('Неверный email или пароль.');}
        self::authenticate((int)$c['id']);return self::public_customer(self::customer());
    }
    private static function authenticate(int $id): void {
        global $wpdb;$s=self::session();$guest='s:'.$s['id'];$target='c:'.$id;$t=DB::table('lists');
        $wpdb->query($wpdb->prepare("INSERT INTO $t (owner,kind,product_id,qty) SELECT %s,kind,product_id,qty FROM $t WHERE owner=%s ON DUPLICATE KEY UPDATE qty=LEAST(999999,GREATEST(qty,VALUES(qty)))",$target,$guest));
        $wpdb->delete($t,['owner'=>$guest]);$wpdb->delete(DB::table('sessions'),['id'=>$s['id']]);self::$session=null;self::create($id);
    }
    public static function logout(): void {
        global $wpdb;$s=self::session(false);if($s){$wpdb->delete(DB::table('sessions'),['id'=>$s['id']]);}
        self::$session=null;self::create(0);
    }
    public static function billing(array $p): array {
        $b=[];foreach(['company','inn','kpp','ogrn','legal_address','bank','bik','account','correspondent'] as $k){$b[$k]=sanitize_text_field($p[$k]??'');}return $b;
    }
    public static function profile(array $p): array {
        global $wpdb;$c=self::customer();if(!$c){throw new \RuntimeException('Войдите в личный кабинет.');}
        $data=['name'=>sanitize_text_field($p['name']??$c['name']),'phone'=>sanitize_text_field($p['phone']??$c['phone']),'address'=>sanitize_textarea_field($p['address']??$c['address']),'billing'=>DB::json(self::billing((array)($p['billing']??json_decode($c['billing'],true)))),'type'=>($p['type']??$c['type'])==='legal'?'legal':'physical','updated_at'=>DB::now()];
        if(mb_strlen($data['name'])<2||strlen(preg_replace('/\D/','',$data['phone']))<10){throw new \InvalidArgumentException('Заполните имя и телефон.');}
        if($data['type']==='legal'){$b=json_decode($data['billing'],true);if(!$b['company']||!preg_match('/^\d{10}(\d{2})?$/',$b['inn'])){throw new \InvalidArgumentException('Укажите организацию и корректный ИНН.');}}
        if(!empty($p['new_password'])){
            if(!wp_check_password((string)($p['current_password']??''),$c['password'])||strlen($p['new_password'])<12){throw new \InvalidArgumentException('Проверьте текущий пароль. Новый пароль — не менее 12 символов.');}
            $data['password']=wp_hash_password($p['new_password']);
            $wpdb->query($wpdb->prepare('DELETE FROM '.DB::table('sessions').' WHERE customer_id=%d AND id<>%d',$c['id'],self::session()['id']));
        }
        DB::require_result($wpdb->update(DB::table('customers'),$data,['id'=>$c['id']]));return self::public_customer(self::customer());
    }
    public static function permanent(): void {
        global $wpdb;$c=self::customer();if(!$c||!trim($c['address'])||count(preg_split('/\s+/',trim($c['name'])))<2){throw new \InvalidArgumentException('Укажите полное ФИО, телефон и адрес доставки в профиле.');}
        $wpdb->update(DB::table('customers'),['permanent_status'=>'pending','updated_at'=>DB::now()],['id'=>$c['id']]);
    }
    public static function reset_request(string $email): void {
        global $wpdb;self::limit('reset',6,3600);$c=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('customers').' WHERE email=%s',strtolower(sanitize_email($email))),ARRAY_A);
        if(!$c){return;}$token=bin2hex(random_bytes(32));
        $wpdb->update(DB::table('customers'),['reset_hash'=>hash('sha256',$token),'reset_expires'=>gmdate('Y-m-d H:i:s',time()+HOUR_IN_SECONDS)],['id'=>$c['id']]);
        $url=add_query_arg(['mik_reset'=>$token,'email'=>$c['email']],Settings::url('account'));
        wp_mail($c['email'],'Восстановление пароля '.Settings::get('store_name'),"Для смены пароля откройте ссылку в течение часа:\n".$url);
    }
    public static function reset_password(array $p): void {
        global $wpdb;self::limit('reset-confirm',15,900);$token=(string)($p['token']??'');$password=(string)($p['password']??'');
        if(!preg_match('/^[a-f0-9]{64}$/',$token)||strlen($password)<12||strlen($password)>200){throw new \InvalidArgumentException('Проверьте ссылку и пароль (от 12 символов).');}
        $t=DB::table('customers');
        $c=$wpdb->get_row($wpdb->prepare("SELECT id FROM $t WHERE email=%s AND reset_hash=%s AND reset_expires>%s",strtolower(sanitize_email($p['email']??'')),hash('sha256',$token),DB::now()),ARRAY_A);
        if(!$c){throw new \InvalidArgumentException('Ссылка недействительна или срок её действия истёк.');}
        $updated=$wpdb->query($wpdb->prepare("UPDATE $t SET password=%s,reset_hash=NULL,reset_expires=NULL WHERE id=%d AND reset_hash=%s",wp_hash_password($password),$c['id'],hash('sha256',$token)));
        if($updated!==1){throw new \InvalidArgumentException('Ссылка уже использована.');}
        $wpdb->delete(DB::table('sessions'),['customer_id'=>$c['id']]);
    }
    public static function cleanup(): void {
        global $wpdb;$s=DB::table('sessions');$l=DB::table('lists');
        $wpdb->query($wpdb->prepare("DELETE l FROM $l l INNER JOIN $s s ON l.owner=CONCAT('s:',s.id) WHERE s.expires_at<%s",DB::now()));
        foreach(['sessions','limits'] as $n){$wpdb->query($wpdb->prepare('DELETE FROM '.DB::table($n).' WHERE expires_at<%s',DB::now()));}
    }
}
