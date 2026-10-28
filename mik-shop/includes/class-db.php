<?php
namespace MIK;
defined('ABSPATH') || exit;

class DB {
    public static function table(string $name): string { global $wpdb; return $wpdb->prefix.'mik_'.$name; }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        $schemas=[
            'customers'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, email varchar(190) NOT NULL, password varchar(255) NOT NULL, name varchar(190) NOT NULL, phone varchar(50) NOT NULL DEFAULT '', type varchar(20) NOT NULL DEFAULT 'physical', status varchar(20) NOT NULL DEFAULT 'active', group_id bigint unsigned NOT NULL DEFAULT 1, address text, billing longtext, permanent_status varchar(20) NOT NULL DEFAULT 'none', reset_hash varchar(64) DEFAULT NULL, reset_expires datetime DEFAULT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY email (email)",
            'sessions'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, token_hash varchar(64) NOT NULL, csrf varchar(64) NOT NULL, customer_id bigint unsigned NOT NULL DEFAULT 0, expires_at datetime NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY token_hash (token_hash), KEY customer_id (customer_id), KEY expires_at (expires_at)",
            'lists'=>"owner varchar(80) NOT NULL, kind varchar(12) NOT NULL, product_id bigint unsigned NOT NULL, qty int unsigned NOT NULL DEFAULT 1, PRIMARY KEY  (owner,kind,product_id)",
            'groups'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL, currency varchar(3) NOT NULL DEFAULT 'RUB', fallback_base tinyint NOT NULL DEFAULT 0, PRIMARY KEY  (id)",
            'prices'=>"product_id bigint unsigned NOT NULL, group_id bigint unsigned NOT NULL, price decimal(18,4) NOT NULL, PRIMARY KEY  (product_id,group_id), KEY group_price (group_id,price)",
            'orders'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, customer_id bigint unsigned NOT NULL DEFAULT 0, session_id bigint unsigned NOT NULL, request_key varchar(64) NOT NULL, status varchar(30) NOT NULL DEFAULT 'pending', currency varchar(3) NOT NULL DEFAULT 'RUB', total decimal(18,4) NOT NULL, customer_json longtext NOT NULL, delivery varchar(30) NOT NULL, payment varchar(30) NOT NULL, notes text, stock_released tinyint NOT NULL DEFAULT 0, invoice_number varchar(80) NOT NULL DEFAULT '', invoice_date date DEFAULT NULL, invoice_seller longtext, mail_status varchar(20) NOT NULL DEFAULT 'pending', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency (session_id,request_key), KEY customer_id (customer_id), KEY status_created (status,created_at)",
            'order_items'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, order_id bigint unsigned NOT NULL, product_id bigint unsigned NOT NULL, sku varchar(190) NOT NULL, name text NOT NULL, qty int unsigned NOT NULL, price decimal(18,4) NOT NULL, PRIMARY KEY  (id), KEY order_id (order_id)",
            'inventory'=>"product_id bigint unsigned NOT NULL, quantity int NOT NULL DEFAULT 0, updated_at datetime NOT NULL, PRIMARY KEY  (product_id)",
            'movements'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, product_id bigint unsigned NOT NULL, delta int NOT NULL, reason varchar(190) NOT NULL, order_id bigint unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY product_id (product_id)",
            'specs'=>"product_id bigint unsigned NOT NULL, param_key varchar(100) NOT NULL, label varchar(190) NOT NULL, value varchar(190) NOT NULL, unit varchar(30) NOT NULL DEFAULT '', sort int NOT NULL DEFAULT 0, PRIMARY KEY  (product_id,param_key), KEY parameter_value (param_key,value)",
            'imports'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, status varchar(30) NOT NULL DEFAULT 'uploaded', file_path text NOT NULL, byte_offset bigint unsigned NOT NULL DEFAULT 0, headers longtext, plan_bytes bigint unsigned NOT NULL DEFAULT 52428800, planner_version smallint unsigned NOT NULL DEFAULT 1, current_package int unsigned NOT NULL DEFAULT 1, package_bytes bigint unsigned NOT NULL DEFAULT 0, total_rows int unsigned NOT NULL DEFAULT 0, processed int unsigned NOT NULL DEFAULT 0, error_count int unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id)",
            'plan_assets'=>"import_id bigint unsigned NOT NULL, package_no int unsigned NOT NULL, path_hash char(64) NOT NULL, path text NOT NULL, file_size bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (import_id,package_no,path_hash)",
            'import_rows'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, import_id bigint unsigned NOT NULL, package_no int unsigned NOT NULL, legacy_id varchar(80) NOT NULL, row_json longtext NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending', error text, PRIMARY KEY  (id), KEY import_package_status (import_id,package_no,status)",
            'packages'=>"import_id bigint unsigned NOT NULL, package_no int unsigned NOT NULL, filename varchar(190) NOT NULL, estimated_bytes bigint unsigned NOT NULL DEFAULT 0, rows_count int unsigned NOT NULL DEFAULT 0, selected tinyint NOT NULL DEFAULT 1, parts_total int unsigned NOT NULL DEFAULT 0, part_set varchar(64) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'planned', zip_path text, row_cursor bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (import_id,package_no)",
            'package_parts'=>"import_id bigint unsigned NOT NULL, package_no int unsigned NOT NULL, part_no int unsigned NOT NULL, filename varchar(190) NOT NULL, zip_path text NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (import_id,package_no,part_no)",
            'package_media'=>"import_id bigint unsigned NOT NULL, package_no int unsigned NOT NULL, path_hash char(64) NOT NULL, part_no int unsigned NOT NULL, actual_path text NOT NULL, sha256 char(64) NOT NULL, file_size bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (import_id,package_no,path_hash), KEY package_part (import_id,package_no,part_no)",
            'category_jobs'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(16) NOT NULL, source_id bigint unsigned NOT NULL DEFAULT 0, status varchar(16) NOT NULL DEFAULT 'queued', phase varchar(32) NOT NULL, cursor_id bigint unsigned NOT NULL DEFAULT 0, max_product bigint unsigned NOT NULL DEFAULT 0, total bigint unsigned NOT NULL DEFAULT 0, done bigint unsigned NOT NULL DEFAULT 0, backup_ready tinyint NOT NULL DEFAULT 0, config_json longtext, map_json longtext, created_json longtext, error text, created_at datetime NOT NULL, updated_at datetime NOT NULL, queued_at datetime DEFAULT NULL, PRIMARY KEY  (id), KEY status (status)",
            'category_terms'=>"job_id bigint unsigned NOT NULL, term_id bigint unsigned NOT NULL, data_json longtext NOT NULL, PRIMARY KEY  (job_id,term_id)",
            'category_products'=>"job_id bigint unsigned NOT NULL, product_id bigint unsigned NOT NULL, data_json longtext NOT NULL, PRIMARY KEY  (job_id,product_id)",
            'limits'=>"bucket varchar(190) NOT NULL, hits int unsigned NOT NULL DEFAULT 1, expires_at datetime NOT NULL, PRIMARY KEY  (bucket), KEY expires_at (expires_at)",
        ];
        foreach($schemas as $name=>$sql){dbDelta('CREATE TABLE '.self::table($name).' ('.str_replace(', ',",\n",$sql).") ENGINE=InnoDB $c;");}
        foreach(array_keys($schemas) as $name){$table=self::table($name);if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table){throw new \RuntimeException('MIK Shop: не удалось создать таблицу '.$table.'. Проверьте права MySQL и InnoDB.');}}
        $t=self::table('groups');
        $wpdb->query("INSERT IGNORE INTO $t (id,name,currency,fallback_base) VALUES (1,'Базовая (руб)','RUB',1),(2,'Базовая (USD)','USD',0),(3,'МелкОпт (руб)','RUB',0)");
        update_option('mik_schema_version',MIK_VERSION,false);
        if(!wp_next_scheduled('mik_cleanup')){wp_schedule_event(time()+3600,'daily','mik_cleanup');}
    }
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public static function transaction(callable $fn) {
        global $wpdb;$wpdb->query('START TRANSACTION');
        try {$value=$fn();$wpdb->query('COMMIT');return $value;} catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }
    public static function json($v): string { return wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public static function require_result($v): void { global $wpdb;if($v===false){throw new \RuntimeException('Не удалось сохранить данные. Повторите действие.');} }
}
