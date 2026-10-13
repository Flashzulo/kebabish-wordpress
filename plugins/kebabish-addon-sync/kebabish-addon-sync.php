<?php
/**
 * Plugin Name: Kebabish — Engangsoverføring av cateringtillegg
 * Description: Forhåndsviser og legger inn de eksisterende porsjons-/prisforslagene på 59 WooCommerce-tillegg. Ingen automatisk kjøring.
 * Version: 1.1.1
 */
if (!defined('ABSPATH')) exit;

final class Kebabish_Addon_Sync {
 const SNAP = 'kcs_snapshot_20261013';
 const DONE = 'kcs_done_20261013';
 const BASENAME = '_kc_catering_proposal_';
 static function init() { add_action('admin_menu', [__CLASS__, 'menu']); }
 static function menu() { add_submenu_page('woocommerce','Overfør cateringforslag','Overfør cateringforslag','manage_woocommerce','kebabish-addon-sync',[__CLASS__,'page']); }
 static function round9($n) { return max(19,(int)(ceil($n/10)*10-1)); }
 static function propose($name,$group,$unit,$tags,$price) {
  $n=strtolower($name); $f=null;
  if(strpos($n,'ship for')!==false) $s='Som i dag – hele fatet til 4 personer';
  elseif(strpos($n,'seekh')!==false) {$s='1 spyd per person (i dag 4 spyd per enhet)';$f=.25;}
  elseif(strpos($n,'chops')!==false && strpos($n,'mixed')===false) {$s='1 kotelett per person (i dag 5 per porsjon)';$f=.2;}
  elseif(strpos($n,'wings')!==false) {$s='3 vinger per person';$f=.45;}
  elseif($group==='Grillretter') {$s='Liten porsjon per person (ca. 3–4 biter)';$f=.45;}
  elseif(in_array($group,['Curry & Karahi','Kjøttretter','Vegetar'],true)) {$s='Liten porsjon per person (ca. 150 g)';$f=.45;}
  elseif($group==='Biryani') {$s='Liten porsjon per person (ca. 200 g)';$f=.45;}
  elseif($group==='Dessert') {$s='Liten porsjon per person (1–2 stk)';$f=.5;}
  elseif(in_array('drink',$tags,true)) $s='1 per person – som i dag';
  elseif(in_array('bread',$tags,true)) $s='1 per person – som i dag';
  elseif(in_array('rice',$tags,true)) $s='1 porsjon per 4 personer – som i dag';
  elseif(in_array('sauce',$tags,true)) $s='1 boks per bestilling (til ca. 10 personer)';
  elseif(strpos($n,'salat')!==false) $s='1 bolle per 5 personer';
  elseif($unit==='piece' && in_array('finger',$tags,true) && strpos($n,'roll')===false) $s='1 stk per person – pris per stk';
  elseif(strpos($n,'roll')!==false) {$s='Halv rull per person';$f=.5;}
  elseif($unit==='portion' && in_array($group,['Forretter','Snacks'],true)) {$s='Liten porsjon per person';$f=.5;}
  else $s='Som i dag';
  return ['portion'=>$s,'price'=>$f===null?(float)$price:self::round9($price*$f)];
 }
 static function items() {
  if(!class_exists('WooCommerce')||!class_exists('KC_Catalog')||!class_exists('KC_Advisor')) return new WP_Error('deps','WooCommerce / Kebabish-plugin mangler.');
  $products=KC_Catalog::addons();
  $rows=[];
  foreach($products as $p) {
   $id=(int)$p->get_id();
   if($id<141||$id>199) return new WP_Error('range','Uventet produkt-ID: '.$id);
   if($p->get_status()!=='publish'||$p->get_type()!=='simple') return new WP_Error('status','Uventet status/type for '.$id);
   if($p->get_sale_price()!=='') return new WP_Error('sale','Salgpris finnes på '.$id.'; stopp for manuell kontroll.');
   if($p->get_regular_price()==='') return new WP_Error('price','Ingen ordinærpris på '.$id);
   $group=trim((string)$p->get_meta('_kc_group'));if($group==='')$group='Annet';
   $unit=KC_Catalog::unit($p);$tags=KC_Advisor::product_tags($p);
   $price=(float)$p->get_price();
   $old=(string)$p->get_short_description();
   $proposal=self::propose($p->get_name(),$group,$unit,$tags,$price);
   $review=$p->get_meta('_kc_review');
   $review=is_array($review)?$review:[];
   // Existing Shammi corrections take precedence; only a blank review can be seeded as proposed.
   if(isset($review['portion'])&&trim((string)$review['portion'])!==''&&trim((string)$review['portion'])!==$proposal['portion']) return new WP_Error('review','Eksisterende avvikende porsjon i Shammi-svar på #'.$id.'; stopp for kontroll.');
   if(isset($review['price'])&&trim((string)$review['price'])!==''&&(float)str_replace(',','.',(string)$review['price'])!==(float)$proposal['price']) return new WP_Error('review','Eksisterende avvikende pris i Shammi-svar på #'.$id.'; stopp for kontroll.');
   $rows[$id]=['id'=>$id,'name'=>$p->get_name(),'sku'=>$p->get_sku(),'price'=>$price,'regular'=>$p->get_regular_price(),'sale'=>$p->get_sale_price(),'short'=>$old,'proposal'=>$proposal,'review'=>$review,'unit'=>$unit,'suggest_rule'=>(string)$p->get_meta('_kc_suggest')];
  }
  ksort($rows,SORT_NUMERIC);
  if(count($rows)!==59||array_keys($rows)!==range(141,199)) return new WP_Error('count','Forventet 59 produkter, ID 141–199; funnet '.count($rows));
  return $rows;
 }
 static function page() {
  if(!current_user_can('manage_woocommerce')) wp_die('Ingen tilgang');
  $action=isset($_POST['kcs_action'])?sanitize_key(wp_unslash($_POST['kcs_action'])):'';
  $msg='';
  if($action){check_admin_referer('kcs_run');if($action==='apply')$msg=self::apply();elseif($action==='restore')$msg=self::restore();}
  $done=(bool)get_option(self::DONE,false);
  $rows=$done?get_option(self::SNAP,[]):self::items();
  echo '<div class="wrap"><h1>Kebabish – cateringforslag til 59 tillegg</h1>';
  echo '<p><strong>Kun catering (ID 141–199).</strong> Ingen automatisk kjøring. Forslagene er ikke kjøkkengodkjent. Kunder ser oppdaterte cateringpriser når du trykker «Utfør». _kc_suggest / antallsreglene beholdes: mersalg er frivillige ekstravalg, ikke et nytt måltid per gjest.</p>';
  if($msg)echo '<div class="notice '.(strpos($msg,'PASS')===0?'notice-success':'notice-error').'"><p>'.esc_html($msg).'</p></div>';
  if(is_wp_error($rows)){echo '<div class="notice notice-error"><p>'.esc_html($rows->get_error_message()).'</p></div></div>';return;}
  echo '<p>Status: <b>'.($done?'Utført (kan tilbakeføres)':'Klar til forhåndsvisning').'</b> · '.count($rows).' varer</p>';
  if(!$done){ echo '<form method="post">';wp_nonce_field('kcs_run');echo '<input type="hidden" name="kcs_action" value="apply"><button class="button button-primary">Utfør engangsoverføring av 59 produkter</button></form>';}
  else {echo '<form method="post">';wp_nonce_field('kcs_run');echo '<input type="hidden" name="kcs_action" value="restore"><button class="button">Tilbakefør uendrede, migrerte produkter</button></form>';}
  echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Produkt</th><th>Gammel pris</th><th>Cateringpris</th><th>Cateringporsjon</th><th>Mersalgsregel – beholdes</th></tr></thead><tbody>';
  foreach($rows as $r)echo '<tr><td>'.esc_html($r['id']).'</td><td>'.esc_html($r['name']).'</td><td>'.esc_html($r['price']).'</td><td><b>'.esc_html($r['proposal']['price']).'</b></td><td>'.esc_html($r['proposal']['portion']).'</td><td>'.esc_html($r['suggest_rule']).'</td></tr>';
  echo '</tbody></table></div>';
 }
 static function apply(){
  if(get_option(self::DONE,false))return 'STOPP: Overføringen er allerede utført.';
  $rows=self::items();if(is_wp_error($rows))return 'STOPP: '.$rows->get_error_message();
  // Durable backup BEFORE first product write.
  if(!add_option(self::SNAP,$rows,'','no'))return 'STOPP: Backup finnes allerede. Ingen endringer utført.';
  $done=[];
  try{
   foreach($rows as $id=>$r){
    $p=wc_get_product($id);if(!$p||$p->get_status()!=='publish'||(float)$p->get_price()!==$r['price']||(string)$p->get_meta('_kc_suggest')!==$r['suggest_rule'])throw new Exception('Produkt endret under arbeid: '.$id);
    $p->update_meta_data('_kc_catering_proposal_original_price',(string)$r['price']);
    $p->update_meta_data('_kc_catering_proposal_original_suggest_rule',$r['suggest_rule']);
    $p->update_meta_data('_kc_catering_proposal_price',(string)$r['proposal']['price']);
    $p->update_meta_data('_kc_catering_proposal_portion',$r['proposal']['portion']);
    $p->update_meta_data('_kc_catering_proposal_source','kc-menu-review-v2.3-2026-10-13');
    $portion='<p><strong>Cateringporsjon:</strong> '.esc_html($r['proposal']['portion']).'</p>';
    if($r['proposal']['portion']!=='Som i dag')$p->set_short_description($portion."\n".$r['short']);
    $p->set_regular_price((string)$r['proposal']['price']);
    $p->save();$done[]=$id;
   }
   update_option(self::DONE,1,false);
   return 'PASS: 59/59 produkter overført. Kontroller kunderettet bestilling og Shammis vurderingsside.';
  }catch(Throwable $e){
   foreach($done as $id){$r=$rows[$id];$p=wc_get_product($id);if($p){$p->set_regular_price((string)$r['regular']);$p->set_sale_price((string)$r['sale']);$p->set_short_description($r['short']);foreach(['original_price','original_suggest_rule','price','portion','source'] as $k)$p->delete_meta_data('_kc_catering_proposal_'.$k);$p->save();}}
   delete_option(self::SNAP);
   return 'STOPP: '. $e->getMessage().'. '.count($done).' tidligere produkter forsøkt tilbakeført. Kontroller status.';
  }
 }
 static function restore(){
  if(!get_option(self::DONE,false))return 'STOPP: Ingen fullført overføring.';
  $rows=get_option(self::SNAP,[]);if(count($rows)!==59)return 'STOPP: Backup mangler.';
  $reverted=0;$skipped=[];
  foreach($rows as $id=>$r){
   $p=wc_get_product($id);if(!$p){$skipped[]=$id;continue;}
   // Do not overwrite post-migration edits by Shammi.
   if((float)$p->get_regular_price()!==(float)$r['proposal']['price']||(string)$p->get_meta('_kc_suggest')!==$r['suggest_rule']){$skipped[]=$id;continue;}
   $portion='<p><strong>Cateringporsjon:</strong> '.esc_html($r['proposal']['portion']).'</p>';
   $expected=$r['proposal']['portion']!=='Som i dag'?$portion."\n".$r['short']:$r['short'];
   if((string)$p->get_short_description()!==$expected){$skipped[]=$id;continue;}
   $p->set_regular_price((string)$r['regular']);$p->set_sale_price((string)$r['sale']);$p->set_short_description($r['short']);
   foreach(['original_price','original_suggest_rule','price','portion','source'] as $k)$p->delete_meta_data('_kc_catering_proposal_'.$k);
   $p->save();$reverted++;
  }
  if($reverted===59){delete_option(self::DONE);delete_option(self::SNAP);}
  return ($reverted===59?'PASS':'DELVIS').': '.$reverted.' tilbakeført; '.count($skipped).' beholdt fordi de er endret siden overføringen: '.implode(',',$skipped);
 }
}
add_action('plugins_loaded',['Kebabish_Addon_Sync','init']);
