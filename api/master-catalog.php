<?php
/* Read-only catalog and backward-compatible specialist profiles. No writes here. */
function klio_read_array($file) {
    $v = json_decode((string)@file_get_contents($file), true);
    return is_array($v) ? $v : null;
}
function klio_catalog() {
    $data = klio_read_array(__DIR__.'/content.json');
    $out = [];
    foreach (($data['prices'] ?? []) as $cat) {
        if (!is_string($cat['cat'] ?? null)) continue;
        foreach (($cat['rows'] ?? []) as $row) {
            if (!is_string($row[0] ?? null)) continue;
            $out[] = ['dir'=>$cat['cat'], 'name'=>trim($row[0]), 'price'=>(string)($row[1] ?? '')];
        }
    }
    return $out;
}
function klio_profiles($meta) {
    if (isset($meta['master_profiles']) && is_array($meta['master_profiles'])) return array_values($meta['master_profiles']);
    $out = [];
    foreach (($meta['specialists'] ?? []) as $name) {
        if (!is_string($name) || trim($name)==='') continue;
        $out[] = ['id'=>'legacy-'.substr(hash('sha256',$name),0,24), 'name'=>$name, 'active'=>true, 'services'=>[], 'configured'=>false];
    }
    return $out;
}
function klio_master_meta() {
    $m = klio_read_array(__DIR__.'/data/manage-meta.json');
    return $m;
}
function klio_has_service($profile,$dir,$name) {
    foreach (($profile['services'] ?? []) as $s)
        if (($s['dir'] ?? '') === $dir && ($s['name'] ?? '') === $name) return true;
    return false;
}
function klio_find_master($profiles,$name,$id='') {
    foreach ($profiles as $p) if (($id!=='' && ($p['id'] ?? '')===$id) || ($id==='' && ($p['name'] ?? '')===$name)) return $p;
    return null;
}
function klio_public_catalog() {
    $meta=klio_master_meta();
    $catalog=klio_catalog();
    $masters=[];
    foreach (klio_profiles($meta ?? []) as $p) {
        if (empty($p['active']) || empty($p['configured'])) continue;
        $services=[];
        foreach ($catalog as $s) if (klio_has_service($p,$s['dir'],$s['name'])) $services[]=['dir'=>$s['dir'],'name'=>$s['name']];
        if ($services) $masters[]=['id'=>$p['id'],'name'=>$p['name'],'services'=>$services];
    }
    return ['ok'=>true,'services'=>$catalog,'masters'=>$masters];
}
function klio_assignment_ok($meta,$name,$dir,$services) {
    if ($dir==='Солярий') return in_array($name,['Солярий синий','Солярий оранжевый'],true);
    if (!isset($meta['master_profiles'])) return true; // legacy CRM before migration
    $p=klio_find_master(klio_profiles($meta),$name);
    if (!$p || empty($p['active']) || empty($p['configured'])) return false;
    foreach ($services as $s) if (!klio_has_service($p,$dir,$s)) return false;
    return true;
}
