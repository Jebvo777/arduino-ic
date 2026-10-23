<?php
namespace MIK;
defined('ABSPATH') || exit;

/** Registers independent ZIPs of a logical catalogue package without joining their bytes. */
class ImportParts {
    public static function filename(string $base,int $part,int $total): string {
        return preg_replace('/\.zip$/i','',$base).'-part'.str_pad((string)$part,4,'0',STR_PAD_LEFT).'-of'.str_pad((string)$total,4,'0',STR_PAD_LEFT).'.zip';
    }
    public static function key(string $path): string { return hash('sha256',mb_strtolower(str_replace('\\','/',$path),'UTF-8')); }
    public static function register(int $id,array $package,string $path,array $manifest): void {
        global $wpdb;$number=(int)$package['package_no'];$part=filter_var($manifest['part_no']??null,FILTER_VALIDATE_INT);$total=filter_var($manifest['parts_total']??null,FILTER_VALIDATE_INT);$set=(string)($manifest['part_set']??'');
        if(!$part||!$total||$part<1||$part>$total||$total>10000||!preg_match('/^[a-f0-9]{64}$/D',$set)){throw new \InvalidArgumentException('Некорректный номер или комплект ZIP-частей. Скачайте новый BAT и пересоберите архивы.');}
        $records=[];
        foreach($manifest['files'] as $actual=>$file){
            if(!is_array($file)||!preg_match('/^[a-f0-9]{64}$/D',(string)($file['sha256']??''))||!isset($file['size'])||!is_numeric($file['size'])||(int)$file['size']<0){throw new \InvalidArgumentException('Некорректные данные файла в ZIP-части.');}
            $records[self::key($actual)]=['path'=>$actual,'sha'=>$file['sha256'],'size'=>(int)$file['size']];
        }
        foreach(($manifest['aliases']??[]) as $requested=>$actual){$key=self::key($requested);if(!isset($records[$key])){$records[$key]=['path'=>$actual,'sha'=>$manifest['files'][$actual]['sha256'],'size'=>(int)$manifest['files'][$actual]['size']];}}
        DB::transaction(function()use($wpdb,$id,$number,$path,$part,$total,$set,$records){
            $packages=DB::table('packages');$parts=DB::table('package_parts');$media=DB::table('package_media');
            $p=$wpdb->get_row($wpdb->prepare("SELECT * FROM $packages WHERE import_id=%d AND package_no=%d FOR UPDATE",$id,$number),ARRAY_A);
            if(!$p||!$p['selected']){throw new \InvalidArgumentException('Пакет не выбран в плане.');}
            if($p['part_set']!==''&&(!hash_equals($p['part_set'],$set)||(int)$p['parts_total']!==$total)){throw new \InvalidArgumentException('Части относятся к разным сборкам. Используйте один комплект ZIP из одного запуска BAT. Для изменённого FULL создайте новый план через «Пересчитать пакеты».');}
            if(in_array($p['status'],['processing','done','done-errors'],true)){
                if($p['part_set']===$set&&$wpdb->get_var($wpdb->prepare("SELECT part_no FROM $parts WHERE import_id=%d AND package_no=%d AND part_no=%d",$id,$number,$part))){return;}
                throw new \InvalidArgumentException('Этот пакет уже импортируется или завершён. Для обновления создайте новый план.');
            }
            DB::require_result($wpdb->delete($media,['import_id'=>$id,'package_no'=>$number,'part_no'=>$part]));
            foreach(array_chunk($records,100,true) as $batch){
                $values=[];foreach($batch as $hash=>$r){$values[]=$wpdb->prepare('(%d,%d,%s,%d,%s,%s,%d)',$id,$number,$hash,$part,$r['path'],$r['sha'],$r['size']);}
                if($values){DB::require_result($wpdb->query("INSERT INTO $media (import_id,package_no,path_hash,part_no,actual_path,sha256,file_size) VALUES ".implode(',',$values).' ON DUPLICATE KEY UPDATE part_no=VALUES(part_no),actual_path=VALUES(actual_path),sha256=VALUES(sha256),file_size=VALUES(file_size)'));}
            }
            DB::require_result($wpdb->replace($parts,['import_id'=>$id,'package_no'=>$number,'part_no'=>$part,'filename'=>self::filename($p['filename'],$part,$total),'zip_path'=>$path,'created_at'=>DB::now()]));
            $received=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $parts WHERE import_id=%d AND package_no=%d",$id,$number));
            DB::require_result($wpdb->update($packages,['parts_total'=>$total,'part_set'=>$set,'zip_path'=>null,'status'=>$received===$total?'uploaded':'receiving'],['import_id'=>$id,'package_no'=>$number]));
        });
    }
    public static function decorate(int $id,array $packages): array {
        global $wpdb;$present=[];
        foreach($wpdb->get_results($wpdb->prepare('SELECT package_no,part_no FROM '.DB::table('package_parts').' WHERE import_id=%d',$id),ARRAY_A) as $row){$present[(int)$row['package_no']][(int)$row['part_no']]=true;}
        foreach($packages as &$p){$p['parts_total']=(int)($p['parts_total']??0);$loaded=$present[(int)$p['package_no']]??[];$p['parts_received']=count($loaded);$p['next_filename']='';
            if($p['parts_total']>$p['parts_received']){$next=1;while(isset($loaded[$next])){$next++;}$p['next_filename']=self::filename($p['filename'],$next,$p['parts_total']);}
        }unset($p);return $packages;
    }
}

/** Opens only the ZIPs needed by the current small batch of products. */
class ImportFiles {
    private int $id;
    private int $number;
    private array $paths=[];
    private array $handles=[];
    private array $legacy=[];
    private bool $multipart=false;
    public function __construct(int $id,array $package) {
        global $wpdb;$this->id=$id;$this->number=(int)$package['package_no'];$this->multipart=(int)($package['parts_total']??0)>0;
        if($this->multipart){
            $rows=$wpdb->get_results($wpdb->prepare('SELECT part_no,zip_path FROM '.DB::table('package_parts').' WHERE import_id=%d AND package_no=%d',$id,$this->number),ARRAY_A);
            if(count($rows)!==(int)$package['parts_total']){throw new \InvalidArgumentException('Сначала загрузите все ZIP-части этого пакета.');}
            foreach($rows as $r){if(!is_file($r['zip_path'])){throw new \RuntimeException('Временный файл ZIP-части недоступен. Повторно загрузите часть '.$r['part_no'].'.');}$this->paths[(int)$r['part_no']]=$r['zip_path'];}
        } else {
            $this->paths[0]=$package['zip_path']??'';$z=$this->open(0);$this->legacy=json_decode($z->getFromName('manifest.json'),true)?:[];
        }
    }
    private function open(int $part): \ZipArchive {
        if(isset($this->handles[$part])){return $this->handles[$part];}
        $path=$this->paths[$part]??'';if(!$path||!is_file($path)){throw new \RuntimeException('ZIP-часть не найдена на сервере. Загрузите её заново.');}
        $z=new \ZipArchive();if($z->open($path)!==true){throw new \RuntimeException('Не удалось открыть ZIP-часть '.$part.'.');}return $this->handles[$part]=$z;
    }
    public function find(string $requested): ?array {
        if(!$this->multipart){$z=$this->open(0);$path=Importer::resolve_media($requested,$z,$this->legacy);return $path===null?null:['path'=>$path,'expected'=>$this->legacy['files'][$path],'zip'=>$z];}
        global $wpdb;$candidates=[ImportParts::key($requested)];$extension=strtolower(pathinfo($requested,PATHINFO_EXTENSION));
        if(in_array($extension,Importer::IMAGE_EXTENSIONS,true)){$stem=substr($requested,0,-strlen($extension)-1);foreach(Importer::IMAGE_EXTENSIONS as $ext){$candidates[]=ImportParts::key($stem.'.'.$ext);}}
        $candidates=array_values(array_unique($candidates));$sql=$wpdb->prepare('SELECT path_hash,part_no,actual_path,sha256,file_size FROM '.DB::table('package_media').' WHERE import_id=%d AND package_no=%d AND path_hash IN ('.implode(',',array_fill(0,count($candidates),'%s')).')',array_merge([$this->id,$this->number],$candidates));$found=[];
        foreach($wpdb->get_results($sql,ARRAY_A) as $r){$found[$r['path_hash']]=$r;}
        foreach($candidates as $hash){if(isset($found[$hash])){$r=$found[$hash];$z=$this->open((int)$r['part_no']);if($z->locateName($r['actual_path'])===false){throw new \RuntimeException('В ZIP-части отсутствует заявленный файл: '.$r['actual_path']);}return ['path'=>$r['actual_path'],'expected'=>['sha256'=>$r['sha256'],'size'=>(int)$r['file_size']],'zip'=>$z];}}
        return null;
    }
    public function close(): void { foreach($this->handles as $z){$z->close();}$this->handles=[]; }
    public function __destruct() { $this->close(); }
}
