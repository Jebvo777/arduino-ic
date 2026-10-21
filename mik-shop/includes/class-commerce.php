<?php
namespace MIK;
defined('ABSPATH') || exit;

class Commerce {
    public static function lists(string $kind): array {
        global $wpdb;$owner=Auth::owner();$rows=$wpdb->get_results($wpdb->prepare('SELECT product_id,qty FROM '.DB::table('lists').' WHERE owner=%s AND kind=%s ORDER BY product_id',$owner,$kind),ARRAY_A);$items=[];$total=0;$can=true;$currency=Catalog::group()['currency'];
        foreach($rows as $row){$p=Catalog::product((int)$row['product_id']);if(!$p){continue;}$p['qty']=(int)$row['qty'];$p['line_total']=$p['price']?round($p['price']['value']*$p['qty'],4):null;$items[]=$p;$total+=$p['line_total']??0;if(!$p['price']||$p['stock']<$p['qty']){$can=false;}}
        return ['items'=>$items,'count'=>array_sum(array_column($items,'qty')),'total'=>round($total,4),'currency'=>$currency,'can_order'=>$can&&count($items)>0];
    }
    public static function update_list(string $kind,array $p): array {
        global $wpdb;$id=absint($p['product_id']??0);$product=Catalog::product($id);if(!$product){throw new \InvalidArgumentException('Товар не найден.');}
        $owner=Auth::owner();$t=DB::table('lists');$current=(int)$wpdb->get_var($wpdb->prepare("SELECT qty FROM $t WHERE owner=%s AND kind=%s AND product_id=%d",$owner,$kind,$id));
        if(($p['op']??'')==='remove'){$qty=0;}elseif($kind==='wishlist'){$qty=($p['op']??'')==='toggle'?($current?0:1):1;}else{
            $raw=$p['qty']??1;if(filter_var($raw,FILTER_VALIDATE_INT)===false){throw new \InvalidArgumentException('Количество должно быть целым числом.');}
            $qty=($p['op']??'add')==='set'?(int)$raw:$current+(int)$raw;
            if($qty<0||$qty>999999){throw new \InvalidArgumentException('Недопустимое количество.');}
            if($qty>$product['stock']){throw new \InvalidArgumentException('Недостаточно товара. Доступно: '.$product['stock'].' шт.');}
        }
        if($qty){DB::require_result($wpdb->replace($t,['owner'=>$owner,'kind'=>$kind,'product_id'=>$id,'qty'=>$qty]));}else{$wpdb->delete($t,['owner'=>$owner,'kind'=>$kind,'product_id'=>$id]);}
        return self::lists($kind);
    }
    public static function checkout(array $p): array {
        global $wpdb;Auth::limit('checkout',20,3600);$session=Auth::session();$c=Auth::customer();$key=sanitize_text_field($p['request_key']??'');
        if(!preg_match('/^[a-zA-Z0-9_-]{16,64}$/',$key)){throw new \InvalidArgumentException('Обновите страницу и повторите заказ.');}
        $existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('orders').' WHERE session_id=%d AND request_key=%s',$session['id'],$key));
        if($existing){return self::order((int)$existing);}
        if(empty($p['consent'])){throw new \InvalidArgumentException('Подтвердите согласие на обработку данных.');}
        $input=(array)($p['customer']??[]);
        $customer=['name'=>sanitize_text_field($input['name']??$c['name']??''),'email'=>sanitize_email($input['email']??$c['email']??''),'phone'=>sanitize_text_field($input['phone']??$c['phone']??''),'address'=>sanitize_textarea_field($input['address']??$c['address']??''),'type'=>($c['type']??$input['type']??'physical')==='legal'?'legal':'physical','billing'=>Auth::billing((array)($input['billing']??json_decode($c['billing']??'{}',true)))];
        if(!$customer['name']||!is_email($customer['email'])||strlen(preg_replace('/\D/','',$customer['phone']))<10){throw new \InvalidArgumentException('Заполните имя, email и телефон покупателя.');}
        if($customer['type']==='legal'&&(!$customer['billing']['company']||!preg_match('/^\d{10}(\d{2})?$/',$customer['billing']['inn']))){throw new \InvalidArgumentException('Для счёта организации нужны название и ИНН.');}
        $delivery=$p['delivery']??'cdek_pickup';$payment=$p['payment']??'tbank';
        if(!in_array($delivery,['cdek_pickup','cdek_courier','pickup'],true)||($delivery==='pickup'&&!Settings::get('pickup'))){throw new \InvalidArgumentException('Выберите доступный способ доставки.');}
        if($delivery!=='pickup'&&!trim($customer['address'])){throw new \InvalidArgumentException('Укажите адрес доставки или пункт выдачи СДЭК.');}
        if(!in_array($payment,['tbank','invoice','pickup'],true)||($payment==='pickup'&&$delivery!=='pickup')){throw new \InvalidArgumentException('Выберите способ оплаты.');}
        $cart=self::lists('cart');if(!$cart['can_order']){throw new \InvalidArgumentException('Проверьте цены, количество и наличие товаров в корзине.');}
        $id=DB::transaction(function() use($wpdb,$session,$c,$key,$customer,$delivery,$payment,$cart,$p){
            $orders=DB::table('orders');$inv=DB::table('inventory');$total=0;$lines=[];
            // Every checkout locks stock in the same ascending product order.
            $items=$cart['items'];usort($items,fn($a,$b)=>$a['id']<=>$b['id']);
            foreach($items as $item){
                $qty=(int)$item['qty'];$available=$wpdb->get_var($wpdb->prepare("SELECT quantity FROM $inv WHERE product_id=%d FOR UPDATE",$item['id']));
                $price=Catalog::price($item['id']);
                if($available===null||(int)$available<$qty||!$price||$price['currency']!==$cart['currency']){throw new \RuntimeException('Наличие или цена изменились: '.$item['name'].'. Обновите корзину.');}
                $total+=$price['value']*$qty;$lines[]=['product_id'=>$item['id'],'sku'=>$item['sku'],'name'=>$item['name'],'qty'=>$qty,'price'=>$price['value']];
            }
            $data=['customer_id'=>$c['id']??0,'session_id'=>$session['id'],'request_key'=>$key,'status'=>'pending','currency'=>$cart['currency'],'total'=>round($total,4),'customer_json'=>DB::json($customer),'delivery'=>$delivery,'payment'=>$payment,'notes'=>sanitize_textarea_field($p['notes']??''),'created_at'=>DB::now(),'updated_at'=>DB::now()];
            DB::require_result($wpdb->insert($orders,$data));$id=(int)$wpdb->insert_id;
            foreach($lines as $line){
                $line['order_id']=$id;DB::require_result($wpdb->insert(DB::table('order_items'),$line));
                $changed=$wpdb->query($wpdb->prepare("UPDATE $inv SET quantity=quantity-%d,updated_at=%s WHERE product_id=%d AND quantity>=%d",$line['qty'],DB::now(),$line['product_id'],$line['qty']));
                if($changed!==1){throw new \RuntimeException('Не удалось зарезервировать товар.');}
                $new=(int)$wpdb->get_var($wpdb->prepare("SELECT quantity FROM $inv WHERE product_id=%d",$line['product_id']));Catalog::write_wc_stock((int)$line['product_id'],$new);
                $wpdb->insert(DB::table('movements'),['product_id'=>$line['product_id'],'delta'=>-$line['qty'],'reason'=>'Заказ #'.$id,'order_id'=>$id,'created_at'=>DB::now()]);
            }
            $wpdb->delete(DB::table('lists'),['owner'=>Auth::owner(),'kind'=>'cart']);return $id;
        });
        self::notify($id);return self::order($id);
    }
    public static function order(int $id,bool $admin=false): array {
        global $wpdb;$o=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('orders').' WHERE id=%d',$id),ARRAY_A);
        if(!$o){throw new \InvalidArgumentException('Заказ не найден.');}
        $c=Auth::customer();$s=Auth::session(false);
        if(!$admin&&(!($c&&(int)$c['id']===(int)$o['customer_id'])&&!($s&&(int)$s['id']===(int)$o['session_id']))){throw new \RuntimeException('Нет доступа к заказу.');}
        $o['customer']=json_decode($o['customer_json'],true);$o['items']=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('order_items').' WHERE order_id=%d',$id),ARRAY_A);unset($o['customer_json'],$o['request_key']);return $o;
    }
    public static function orders(): array {
        global $wpdb;$c=Auth::customer();if(!$c){return [];}
        return $wpdb->get_results($wpdb->prepare('SELECT id,status,currency,total,invoice_number,created_at FROM '.DB::table('orders').' WHERE customer_id=%d ORDER BY id DESC LIMIT 100',$c['id']),ARRAY_A);
    }
    public static function status(int $id,string $status): void {
        global $wpdb;if(!in_array($status,['pending','confirmed','invoiced','paid','shipped','completed','cancelled'],true)){throw new \InvalidArgumentException('Неизвестный статус.');}
        DB::transaction(function() use($wpdb,$id,$status){
            $t=DB::table('orders');$o=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d FOR UPDATE",$id),ARRAY_A);if(!$o){throw new \RuntimeException('Заказ не найден.');}
            if($o['status']==='cancelled'&&$status!=='cancelled'){throw new \InvalidArgumentException('Отменённый заказ нельзя возобновить. Создайте новый заказ.');}
            $data=['status'=>$status,'updated_at'=>DB::now()];
            if($status==='cancelled'&&!$o['stock_released']){
                $lines=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('order_items').' WHERE order_id=%d ORDER BY product_id',$id),ARRAY_A);
                foreach($lines as $line){
                    $tinv=DB::table('inventory');$wpdb->query($wpdb->prepare("UPDATE $tinv SET quantity=quantity+%d,updated_at=%s WHERE product_id=%d",$line['qty'],DB::now(),$line['product_id']));
                    $new=(int)$wpdb->get_var($wpdb->prepare("SELECT quantity FROM $tinv WHERE product_id=%d",$line['product_id']));Catalog::write_wc_stock((int)$line['product_id'],$new);
                    $wpdb->insert(DB::table('movements'),['product_id'=>$line['product_id'],'delta'=>$line['qty'],'reason'=>'Отмена #'.$id,'order_id'=>$id,'created_at'=>DB::now()]);
                }$data['stock_released']=1;
            }
            DB::require_result($wpdb->update($t,$data,['id'=>$id]));
        });
    }
    public static function invoice(int $id): array {
        global $wpdb;$o=self::order($id,true);if($o['status']==='cancelled'){throw new \InvalidArgumentException('Заказ отменён.');}
        $seller=Settings::get('seller');if(!$seller['name']||!$seller['inn']||!$seller['account']||!$seller['bik']){throw new \InvalidArgumentException('Сначала заполните реквизиты продавца в настройках.');}
        if(!$o['invoice_number']){$wpdb->update(DB::table('orders'),['invoice_number'=>'MIK-'.gmdate('Y').'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT),'invoice_date'=>gmdate('Y-m-d'),'invoice_seller'=>DB::json($seller),'updated_at'=>DB::now()],['id'=>$id]);}
        if(in_array($o['status'],['pending','confirmed'],true)){self::status($id,'invoiced');}return self::order($id,true);
    }
    public static function notify(int $id): bool {
        global $wpdb;$o=self::order($id,true);$path=wp_tempnam('mik-order-'.$id.'.xls');
        if(!$path){$wpdb->update(DB::table('orders'),['mail_status'=>'failed'],['id'=>$id]);return false;}
        file_put_contents($path,Documents::xls($o));
        $ok=wp_mail(Settings::get('email'),'Заказ MIK #'.$id,"Новый заказ #$id.\nПокупатель: ".$o['customer']['name']."\nEmail: ".$o['customer']['email']."\nТелефон: ".$o['customer']['phone']."\nЗаказ в приложении.\nАдминка: ".admin_url('admin.php?page=mik-shop&tab=orders'),[],[$path]);
        @unlink($path);$wpdb->update(DB::table('orders'),['mail_status'=>$ok?'sent':'failed'],['id'=>$id]);return $ok;
    }
}
