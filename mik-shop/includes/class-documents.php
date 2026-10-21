<?php
namespace MIK;
defined('ABSPATH') || exit;

class Documents {
    private static function record(int $id,string $data=''): string { return pack('vv',$id,strlen($data)).$data; }
    private static function unicode(string $s): string { return function_exists('mb_convert_encoding')?mb_convert_encoding($s,'UTF-16LE','UTF-8'):iconv('UTF-8','UTF-16LE//IGNORE',$s); }
    /** Actual BIFF8 workbook in an OLE compound file, not HTML renamed to .xls. */
    public static function xls(array $o): string {
        $rows=[['Заказ №',(string)$o['id']],['Дата',$o['created_at']],['Покупатель',$o['customer']['name']],['Email',$o['customer']['email']],['Телефон',$o['customer']['phone']],['Адрес',$o['customer']['address']],['Валюта',$o['currency']]];
        foreach($o['customer']['billing']??[] as $k=>$v){if($v!==''){$rows[]=[$k,$v];}}
        $rows[]=['Артикул','Наименование','Количество','Цена','Сумма'];
        foreach($o['items'] as $i){$rows[]=[(string)$i['sku'],(string)$i['name'],(int)$i['qty'],(float)$i['price'],round($i['qty']*$i['price'],4)];}
        $rows[]=['Итого','','','',(float)$o['total']];
        $bof=fn($type)=>self::record(0x0809,pack('vvvvVV',0x0600,$type,0x0DBB,0x07CC,0x41,0x06));
        $strings=[];$ids=[];foreach($rows as $row){foreach($row as $cell){if(is_string($cell)&&!isset($ids[$cell])){$ids[$cell]=count($strings);$strings[]=$cell;}}}
        $sst=pack('VV',count($strings),count($strings));$sst_records='';
        foreach($strings as $s){$s=mb_substr($s,0,1000);$u=self::unicode($s);$entry=pack('vC',strlen($u)/2,1).$u;if(strlen($sst)+strlen($entry)>8000){$sst_records.=self::record($sst_records===''?0x00FC:0x003C,$sst);$sst='';}$sst.=$entry;}
        $sst_records.=self::record($sst_records===''?0x00FC:0x003C,$sst);
        $global=$bof(0x0005).self::record(0x0042,pack('v',1200)).self::record(0x003D,pack('vvvvvvvvv',0,0,0x3FCF,0x2A4E,0x0038,0,0,1,600)).self::record(0x00E0,pack('vvv',0,0,0).str_repeat("\0",14)).$sst_records;
        $sheet_name=self::unicode('Заказ');$bound_size=4+4+1+1+1+1+strlen($sheet_name);$offset=strlen($global)+$bound_size+4;
        $global.=self::record(0x0085,pack('VCCCC',$offset,0,0,5,1).$sheet_name).self::record(0x000A);
        $sheet=$bof(0x0010).self::record(0x0200,pack('VVvvv',0,count($rows),0,5,0));
        foreach($rows as $ri=>$row){foreach($row as $ci=>$v){$header=pack('vvv',$ri,$ci,0);$sheet.=is_string($v)?self::record(0x00FD,$header.pack('V',$ids[$v])):self::record(0x0203,$header.pack('e',(float)$v));}}
        $sheet.=self::record(0x000A);return self::ole($global.$sheet);
    }
    private static function ole(string $stream): string {
        $stream=str_pad($stream,max(4096,(int)(ceil(strlen($stream)/512)*512)),"\0");$n=(int)(strlen($stream)/512);$fat=1;
        while($fat*128<$n+1+$fat){$fat++;}if($fat>109){throw new \RuntimeException('Слишком большой заказ для XLS. Разделите заказ.');}
        $fat_start=$n+1;$fat_ids=range($fat_start,$fat_start+$fat-1);
        $header="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0",16).pack('vvvvv',0x003E,3,0xFFFE,9,6).str_repeat("\0",6).pack('VVVVVVVVV',0,$fat,$n,0,4096,0xFFFFFFFE,0,0xFFFFFFFE,0);
        for($i=0;$i<109;$i++){$header.=pack('V',$fat_ids[$i]??0xFFFFFFFF);}
        $dir=function($name,$type,$left,$right,$child,$start,$size){$u=self::unicode($name."\0");return str_pad($u,64,"\0").pack('vCCVVV',strlen($u),$type,1,$left,$right,$child).str_repeat("\0",16).pack('V',0).str_repeat("\0",16).pack('VVV',$start,$size,0);};
        $directory=$dir('Root Entry',5,0xFFFFFFFF,0xFFFFFFFF,1,0xFFFFFFFE,0).$dir('Workbook',2,0xFFFFFFFF,0xFFFFFFFF,0xFFFFFFFF,0,strlen($stream));$directory=str_pad($directory,512,"\0");
        $entries=[];for($i=0;$i<$n;$i++){$entries[]=$i===$n-1?0xFFFFFFFE:$i+1;}$entries[]=0xFFFFFFFE;foreach($fat_ids as $_){$entries[]=0xFFFFFFFD;}
        $f='';for($i=0;$i<$fat*128;$i++){$f.=pack('V',$entries[$i]??0xFFFFFFFF);}return $header.$stream.$directory.$f;
    }
    public static function invoice_html(array $o): string {
        $s=json_decode($o['invoice_seller']??'{}',true)?:Settings::get('seller');$c=$o['customer'];$e=fn($v)=>esc_html((string)$v);
        $html='<!doctype html><html lang="ru"><meta charset="utf-8"><title>Счёт '.$e($o['invoice_number']).'</title><style>body{font:14px Arial;color:#111;margin:35px;line-height:1.5}table{width:100%;border-collapse:collapse}td,th{border:1px solid #bbb;padding:8px;text-align:left}h1{font-size:24px}.right{text-align:right}@media print{button{display:none}body{margin:10mm}}</style><button onclick="window.print()">Печать / сохранить PDF</button><h1>Счёт на оплату № '.$e($o['invoice_number']).' от '.$e($o['invoice_date']).'</h1>';
        $html.='<p><b>Поставщик:</b> '.$e($s['name']).', ИНН '.$e($s['inn']).', КПП '.$e($s['kpp']).'<br>'.$e($s['address']).'</p><p><b>Банк:</b> '.$e($s['bank']).'<br>БИК '.$e($s['bik']).'<br>Р/с '.$e($s['account']).'<br>К/с '.$e($s['correspondent']).'</p>';
        $html.='<p><b>Покупатель:</b> '.$e($c['billing']['company']?:$c['name']).'<br>ИНН '.$e($c['billing']['inn']).' КПП '.$e($c['billing']['kpp']).'<br>'.$e($c['billing']['legal_address']?:$c['address']).'</p><p>Валюта: '.$e($o['currency']).'</p><table><tr><th>№</th><th>Артикул</th><th>Наименование</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr>';
        foreach($o['items'] as $n=>$i){$html.='<tr><td>'.($n+1).'</td><td>'.$e($i['sku']).'</td><td>'.$e($i['name']).'</td><td>'.$e($i['qty']).'</td><td>'.$e(number_format((float)$i['price'],2,',',' ')).'</td><td>'.$e(number_format($i['qty']*$i['price'],2,',',' ')).'</td></tr>';}
        return $html.'</table><p class="right"><b>Итого: '.$e(number_format((float)$o['total'],2,',',' ')).' '.$e($o['currency']).'</b></p><p>'.$e(Settings::get('tax_label')).'</p><p>Заказ № '.$e($o['id']).'. Стоимость доставки согласовывается отдельно.</p></html>';
    }
}
