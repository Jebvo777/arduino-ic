<?php
namespace MIK;
defined('ABSPATH') || exit;

/** A deterministic, conservative catalogue taxonomy shared by migration and future imports. */
class CategoryTree {
    private static ?array $profile=null;
    public static bool $include_archived=false;
    public static function profile(): array { return self::$profile??(self::$profile=json_decode(file_get_contents(MIK_PATH.'assets/data/category-tree.json'),true)?:[]); }
    public static function hooks(): void {
        add_filter('get_terms_args',function($args,$taxonomies){
            if(self::$include_archived||!in_array('product_cat',(array)$taxonomies,true)||!empty($args['mik_include_archived'])||!empty($args['include'])||!empty($args['slug'])||!empty($args['object_ids'])){return $args;}
            $hide=['relation'=>'OR',['key'=>'_mik_archived','compare'=>'NOT EXISTS'],['key'=>'_mik_archived','value'=>'1','compare'=>'!=']];
            $args['meta_query']=empty($args['meta_query'])?$hide:['relation'=>'AND',$args['meta_query'],$hide];return $args;
        },10,2);
        add_action('template_redirect',function(){if(is_tax('product_cat')){$t=get_queried_object();if($t&&get_term_meta($t->term_id,'_mik_archived',true)){$target=(int)get_term_meta($t->term_id,'_mik_replacement',true);$url=$target?get_term_link($target,'product_cat'):Settings::url('shop');if(!is_wp_error($url)){wp_safe_redirect($url,301);exit;}}}});
    }
    public static function parent_key(string $leaf): string { foreach(self::profile() as $root){foreach($root['children'] as $c){if($c['key']===$leaf){return $root['key'];}}}return 'other'; }
    public static function featured_ids(): array { $map=(array)(get_option('mik_category_tree',[])['map']??[]);$out=[];foreach(self::profile() as $r){if($r['featured']&&!empty($map[$r['key']])){$out[]=(int)$map[$r['key']];}}return $out; }
    public static function ensure_tree(int $job_id): array {
        $map=[];$created=[];
        foreach(self::profile() as $root){
            $nodes=array_merge([['key'=>$root['key'],'name'=>$root['name'],'root'=>true]],$root['children']);
            foreach($nodes as $position=>$node){$parent=!empty($node['root'])?0:$map[$root['key']];$slug='mik-'.$node['key'];$term=get_term_by('slug',$slug,'product_cat');
                if(!$term){$r=wp_insert_term($node['name'],'product_cat',['slug'=>$slug,'parent'=>$parent]);if(is_wp_error($r)&&$r->get_error_code()==='term_exists'){$term=get_term((int)$r->get_error_data(),'product_cat');}elseif(is_wp_error($r)){throw new \RuntimeException($r->get_error_message());}else{$term=get_term((int)$r['term_id'],'product_cat');$created[]=(int)$term->term_id;}}
                if(!$term||is_wp_error($term)){throw new \RuntimeException('Не удалось создать категорию '.$node['name']);}
                $r=wp_update_term($term->term_id,'product_cat',['name'=>$node['name'],'parent'=>$parent]);if(is_wp_error($r)){throw new \RuntimeException($r->get_error_message());}
                $map[$node['key']]=(int)$term->term_id;update_term_meta($term->term_id,'_mik_tree_key',$node['key']);update_term_meta($term->term_id,'_mik_order',(int)$root['order']*100+$position);delete_term_meta($term->term_id,'_mik_archived');delete_term_meta($term->term_id,'_mik_replacement');
            }
        }
        return ['map'=>$map,'created'=>$created];
    }
    public static function classify(string $name,array $sources=[],string $description=''): string {
        $clean=fn($s)=>mb_strtolower(html_entity_decode(wp_strip_all_tags((string)$s),ENT_QUOTES,'UTF-8'),'UTF-8');$n=$clean($name);$s=$clean(implode(' ',array_filter($sources)));$t=$s.' '.$n;
        foreach(self::profile() as $root){foreach($root['children'] as $c){if(in_array($clean($c['name']),array_map($clean,$sources),true)){return $c['key'];}}}
        $rules=[
            ['programmers','программатор|programmer|ch341a|\beeprom\b.*зажим'],
            ['charging','заряд|\bbms\b|балансир|балансиров|li.ion.*защит'],
            ['batteries','батарей|аккумулятор|\b18650\b|\bcr20\d{2}\b|\blr6\b|\blr03\b'],
            ['pfc','\bpfc\b|корректор.*мощност|preregulator'],
            ['pwm','шим|\bpwm\b|\bsmps\b|\bpsr\b|резонансн.*контроллер|контроллер.*ac.dc|ac.dc.*контроллер'],
            ['dc-dc','dc[\s\-/]*dc|step.down|step.up|dc[\s\-/]*ac|преобразовател.*(напряж|повыш|пониж)|повышающ.*контроллер|контроллер.*питани'],
            ['power-adapters','адаптер.*(сетев|питани|220)|сетев.*адаптер'],
            ['power-supplies','источник.*питани|блок.*питани|ac[\s\-/]*dc'],
            ['regulators','регулятор.*напр|регулятор.*напр\.|стабилизатор|линейн.*регулятор'],
            ['thermal-fuses','термопредохран'],['resettable-fuses','самовосстанавл|polyfuse'],['fuses','предохран'],
            ['thermistors','терморезист|термист|\bptc\b|\bntc\b'],['varistors','варистор|varistor'],['thermal-materials','термопаст|термопроклад|прокладк.*to.220|втулк.*изол|слюд|изоляционн'],['heatsinks','радиатор|вентилятор|кулер'],
            ['ssr','реле.*твердотел|твердотел.*реле|опто.реле|фотоэлектрическ.*реле'],
            ['phototransistors','оптотранзист|фототранзист'],['optocouplers','оптопар|оптрон|оптосимист|оптотирист|optocoupler'],
            ['drivers','(?:драйвер|driver).*(?:mos.?fet|igbt|полумост|двигател|светодиод)|(?:igbt|mos.?fet|led|светодиодн).*драйвер|регулятор.*светодиодн'],
            ['igbt','\bigbt\b|\bifbt\b'],['mosfet','mos[\s-]*fet|modfet|полев.*транзист'],['thyristors','тиристор|динистор|thyristor'],['triacs','симистор|\btriacs?\b|transient protected ac switch'],['bipolar','биполяр|darlington|дарлингтон|\brf транзистор'],
            ['tvs','диод.*(защит|огранич)|\btvs\b|\btransil\b'],['bridges','диодн.*мост|мост.*диод'],['zener','стабилитрон|zener'],['schottky','шоттки|schottky'],['ultrafast-diodes','супербыстр|сверхбыстр|ultrafast'],['fast-diodes','диод.*быстр|fast diode|диод.*демпер|damper.*diode'],['rectifiers','диод.*выпрям'],['rf-diodes','диод.*свч|диод.*вч|варикап|varicap'],['led','светодиод(?!н.*драйвер)|ик.излучател|\bled.*лента'],
            ['lamps','ламп|подсвет'],['diodes-general','диод|diode'],
            ['dip-sockets','панельк|\bdip\d*\s+панел'],['pin-headers','штырьков|\bpls\d+|\bpld\d+|\bpbs\d+'],['usb','usb.*раз[ъь]?[её]м|раз[ъь]?[её]м.*usb|переход.*(usb|otg)|адаптер.*usb'],
            ['breadboards','макетн.*плат|плат.*макет|breadboard|protoboard'],['breadboard-wires','макетн.*провод|провод.*макет'],['ribbon','шлейф'],['cables','кабел|провод|удлинител|переходник|переход.*шт|конвертер rs|rs.?.?.?.?usb'],['connectors-other','раз[ъь]?[её]м|гнездо|штекер|коннектор'],
            ['capacitors-smd','smd.конденсатор|конденсатор.*smd|\b\d+(?:[.,]\d+)?\s*pf\b'],['capacitors-electro','электролит|\b\d+(?:[.,]\d+)?\s*(?:uf|мкф)\b'],['capacitors-other','конденсатор|capacitor'],['resistors','резистор|потенциометр|сопротивлен'],['inductors','дроссел|индуктивност'],['quartz','кварц|\bmhz\b.*(?:hc.49|резонатор)|\bcsb\d'],['filters','пьезокерамич|фильтр'],['transformers','трансформатор|феррит'],
            ['wireless','беспроводн|wi.fi|wifi|bluetooth|\besp32\b|\besp8266\b|\bgps\b|\b433\s*(?:мгц|mhz)'],
            ['displays','дисплей|индикатор|\blcd\b|\btft\b|\boled\b'],['sensors','датчик|сенсор|sensor|компас|тензодатчик|фотопри[её]мник|ик.при[её]мник'],
            ['boards','arduino.*(?:uno|nano|mega)|плата.*микроконтроллер|контроллер nano|одноплатн|raspb|raspb|orange pi|отладочн.*плат'],['kits','набор.*ардуино|набор.*arduino|starter.?kit|набор.*электрон|набор.*элек|набор.*робот'],['modules','модул|shield|плата расширен'],
            ['soldering','паяль|припой|канифол|флюс|пинцет|опл[её]тк'],['meters','тестер|осциллограф|мультиметр|вольтметр|амперметр|частотомер|измеритель|измерительн'],['printing-3d','3d.*(?:принтер|печат)|пластик.*(?:pla|abs|petg)|фотополимер'],['laser-heads','лазерн.*голов|pick.?up|\bkss\d|\bsf.hd\d|\bsf.p\d|\braf\d|\bks.h\d'],['motors','мотор|сервопривод|шагов.*двигател'],['acoustic','микрофон|пьезоизлучател|зуммер|buzzer|динамик'],['relays-switches','реле|кнопк|тумблер|переключател|клавиатур'],
            ['opamps','операционн|опер\.?.*усил|усилител.*обратн.*связ'],['supervisors','компаратор|супервизор'],['memory','память|\beeprom\b|\bflash\b'],['audio-video','унч|усилител.*нч|аудио|ауидо|видео|видеопроц|развертк|развёртк|стерео|эквалайзер|pal/ntsc|запис.*воспроизв'],['interfaces','интерфейс|приемоперед|приёмоперед|трансивер|\bcan\b|\brs[ -]?(?:232|485|422)\b|приемник|приёмник|receiver'],['drivers','драйвер|motor driver|интеллектуальн.*(?:ключ|выключател)|\bconverter\b'],['adc-dac','мультиплекс|демультиплекс|коммутатор|аналогов.*переключател|аналого.цифров|цифро.аналогов|\bацп\b|\bцап\b|микроконвертер'],['logic','логич|триггер|тригер|счетчик|счётчик|регистр|дешифратор|\bhex not\b|элемент.*(?:или|и.не|xor|nand)|инвертор.*шмит|буфер'],['timers','таймер|генератор|часы реальн|хронометр|мультивибратор']
        ];
        foreach($rules as [$key,$pattern]){if(preg_match('~'.$pattern.'~iu',$t)){if($key==='modules'&&preg_match('~память|микросхем~u',$s)){continue;}return $key;}}
        if(preg_match('~микроконтроллер|\bpic\d|\bdspic|\batmega|\battiny|\bat90|\bstm32|\bstm8|\bmsp430~iu',$t)){if(preg_match('~\b(?:dspic|pic\d)~i',$n))return 'pic';if(preg_match('~\bat(?:mega|tiny|90)~i',$n))return 'avr';if(preg_match('~\b(?:stm32|stm8|msp430|lpc\d)~i',$n))return 'arm';return 'mcu-other';}
        if(preg_match('~микросхем~u',$s)){
            $d=$clean(mb_substr($description,0,1800));
            foreach([['drivers','(?:mosfet|igbt|motor|gate|led).*driver|драйвер'],['opamps','operational amplifier|операционн.*усилител'],['audio-video','audio.*amplifier|video.*processor|vertical.*deflection|усилитель.*нч|кадров.*разверт'],['memory','eeprom|flash memory'],['interfaces','rs.?485|rs.?232|can.transceiver'],['timers','timer|real.time.clock']] as [$key,$pattern]){if(preg_match('~'.$pattern.'~iu',$d))return $key;}
            return 'ic-general';
        }
        if(preg_match('~^(?:ir[flh]|irfb|irfz|fqp|fqu|fqpf|fds|fdd|fdn|fdh|2sk|2sj|ao\d|aod\d|aon\d|ipp\d|ipb\d|st[pfbw]\d+[np]|si\d|b[su]k\d|2n700|ntgb|lsb\d)~i',$n))return 'mosfet';
        if(preg_match('~^(?:2s[acbd]|bc\d|bd\d|bf\d|bfr\d|bfs\d|bu\w?\d|bul\d|buw\d|tip\d|mje\d|mj\d|mps\w?\d|dta\d|mm[bp]\w?\d|fzt\d|2n\d)~i',$n)||preg_match('~транзистор~u',$s))return 'bipolar';
        if(preg_match('~^(?:bta|btb|bt1\d|bcr\d|qk\d)~i',$n))return 'triacs';
        if(preg_match('~^(?:irg\d|ikw\d|fgh\d|hgt\d|skm\d)~i',$n))return 'igbt';
        if(preg_match('~^(?:top\d|tny\d|lnk\d|str[\w-]*\d|uc38\d|sg35\d|ka7500)~i',$n))return 'pwm';
        if(preg_match('~^(?:lm(?:1117|317|337|78|79)|ams1117|ncp1117|lt108\d|78[ml]?\d|79[ml]?\d)~i',$n))return 'regulators';
        if(preg_match('~^(?:at24|24[cl]|93c|w25|m24|mx25|m5m4)~i',$n))return 'memory';
        if(preg_match('~^(?:74[ahcls]|sn74|hef4|hcf4|cd4|tc74|m74|mc140)~i',$n))return 'logic';
        if(preg_match('~^(?:tl0[678]|lm(?:324|358|1458)|njm(?:2904|4558))~i',$n))return 'opamps';
        if(preg_match('~^(?:tda\d|tea\d|an\d|la\d|ha\d|upc\d)~i',$n))return 'ic-general';
        if(preg_match('~^(?:bav|bas|rl\d|1n\d|stth|mur\d|byv\d)~i',$n))return 'diodes-general';
        if(preg_match('~^(?:bzx|bzv|pdz)~i',$n))return 'zener';
        if(preg_match('~^(?:mbr|stps|bat\d|s[bst]r\d)~i',$n))return 'schottky';
        if(preg_match('~^[0-9\s-]+$~u',trim($s))&&preg_match('~[a-zа-я]\d|\d[a-zа-я]~iu',$n)&&!preg_match('~тест|^test$~iu',$n))return 'ic-general';
        return 'review';
    }
    public static function ids_for(string $name,array $sources=[],string $description=''): array {
        $state=(array)get_option('mik_category_tree',[]);if(empty($state['enabled']))return [];$key=self::classify($name,$sources,$description);$id=(int)($state['map'][$key]??0);return $id&&term_exists($id,'product_cat')?[$id]:[];
    }
}
