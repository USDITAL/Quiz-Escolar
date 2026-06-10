<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$DATA_DIR    = __DIR__ . '/datos';
$USERS_FILE  = $DATA_DIR . '/users.json';
$ADMIN_FILE  = $DATA_DIR . '/admins.json';
$TOPICS_FILE = $DATA_DIR . '/topics.json';   // Ahora actúa como índice (sin preguntas)

if (!is_dir($DATA_DIR)) mkdir($DATA_DIR, 0755, true);
if (!file_exists($DATA_DIR.'/.htaccess')) file_put_contents($DATA_DIR.'/.htaccess',"Deny from all\n");
if (!file_exists($USERS_FILE))  file_put_contents($USERS_FILE,  '{}');
if (!file_exists($TOPICS_FILE)) file_put_contents($TOPICS_FILE, '{}');
if (!file_exists($ADMIN_FILE)) {
    file_put_contents($ADMIN_FILE, json_encode([
        'admin'=>['pass'=>password_hash('admin123',PASSWORD_DEFAULT),'name'=>'Administrador']
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

function readJSON($f){ $d=file_get_contents($f); $r=json_decode($d,true); return is_array($r)?$r:[];}
function writeJSON($f,$d){ file_put_contents($f,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
function ok($d=[]){ echo json_encode(array_merge(['ok'=>true],$d)); exit();}
function fail($msg){ echo json_encode(['ok'=>false,'msg'=>$msg]); exit();}

// Ruta del fichero individual de un tema
function topicFile($tid){
    global $DATA_DIR;
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tid);
    return $DATA_DIR . '/topic_' . $safe . '.json';
}

function readIndex(){ global $TOPICS_FILE; return readJSON($TOPICS_FILE); }
function writeIndex($i){ global $TOPICS_FILE; writeJSON($TOPICS_FILE,$i); }

function readTopicData($tid){
    $f=topicFile($tid); if(!file_exists($f)) return null; return readJSON($f);
}
function writeTopicData($tid,$data){ writeJSON(topicFile($tid),$data); }

// Migración automática: si topics.json tiene entradas con 'questions', separarlas
function migrateTopicsIfNeeded(){
    global $TOPICS_FILE;
    $topics=readJSON($TOPICS_FILE); $needsSave=false;
    foreach($topics as $tid=>&$t){
        if(isset($t['questions'])){
            writeTopicData($tid,['questions'=>$t['questions']]);
            $t['q_count']=count($t['questions']);
            unset($t['questions']); $needsSave=true;
        }
    } unset($t);
    if($needsSave) writeJSON($TOPICS_FILE,$topics);
}
migrateTopicsIfNeeded();

function requireAdmin($body){
    global $ADMIN_FILE;
    $user=trim($body['admin_user']??''); $pass=$body['admin_pass']??'';
    if(!$user||!$pass) fail('Credenciales requeridas.');
    $admins=readJSON($ADMIN_FILE);
    if(!isset($admins[$user])) fail('Administrador no encontrado.');
    if(!password_verify($pass,$admins[$user]['pass'])) fail('Contraseña incorrecta.');
    return $user;
}

$body  = json_decode(file_get_contents('php://input'),true) ?? [];
$action= $body['action'] ?? '';

switch($action){

    case 'register':
        $u=trim($body['username']??''); $p=$body['password']??'';
        if(strlen($u)<2) fail('El nombre debe tener al menos 2 caracteres.');
        if(strlen($p)<3) fail('La contraseña debe tener al menos 3 caracteres.');
        $users=readJSON($USERS_FILE);
        if(isset($users[$u])) fail('Ese nombre ya está en uso.');
        $users[$u]=['pass'=>password_hash($p,PASSWORD_DEFAULT),'scores'=>[]];
        writeJSON($USERS_FILE,$users); ok(['msg'=>'Cuenta creada.']);

    case 'login':
        $u=trim($body['username']??''); $p=$body['password']??'';
        $users=readJSON($USERS_FILE);
        if(!isset($users[$u])) fail('Usuario no encontrado.');
        if(!password_verify($p,$users[$u]['pass'])) fail('Contraseña incorrecta.');
        ok(['scores'=>$users[$u]['scores']??[]]);

    case 'get_topics':
        $index=readIndex(); $light=[];
        foreach($index as $tid=>$t){
            if(isset($t['active'])&&$t['active']===false) continue;
            $qCount=$t['q_count']??0;
            if(!$qCount){ $td=readTopicData($tid); $qCount=$td?count($td['questions']??[]):0; }
            $light[$tid]=['curso'=>$t['curso'],'asignatura'=>$t['asignatura'],'tema'=>$t['tema'],'q_count'=>$qCount];
        }
        ok(['topics'=>$light]);

    case 'get_questions':
        $tid=$body['topic_id']??'';
        $index=readIndex();
        if(!isset($index[$tid])) fail('Tema no encontrado.');
        $td=readTopicData($tid);
        if($td===null) fail('Fichero de preguntas no encontrado.');
        ok(['questions'=>$td['questions']??[]]);

    case 'save_score':
        $u=trim($body['username']??''); $tid=$body['topic_id']??''; $score=intval($body['score']??0);
        $users=readJSON($USERS_FILE);
        if(!isset($users[$u])) fail('Usuario no encontrado.');
        if(!isset($users[$u]['scores'])) $users[$u]['scores']=[];
        $prev=$users[$u]['scores'][$tid]??['best'=>0,'games'=>0];
        $prev['games']++; if($score>$prev['best']) $prev['best']=$score;
        $users[$u]['scores'][$tid]=$prev;
        writeJSON($USERS_FILE,$users); ok(['best'=>$prev['best'],'games'=>$prev['games']]);

    case 'ranking':
        $tid=$body['topic_id']??''; $users=readJSON($USERS_FILE); $rows=[];
        foreach($users as $name=>$data){
            $s=$data['scores'][$tid]??null;
            if($s) $rows[]=['name'=>$name,'best'=>$s['best'],'games'=>$s['games']];
        }
        usort($rows,fn($a,$b)=>$b['best']-$a['best']);
        ok(['ranking'=>array_slice($rows,0,50)]);

    case 'admin_login': requireAdmin($body); ok();

    // Solo metadatos del índice — sin leer ficheros de preguntas (muy rápido)
    case 'admin_get_topics_headers':
        requireAdmin($body);
        ok(['topics'=>readIndex()]);

    // Un único tema completo por ID
    case 'admin_get_topic':
        requireAdmin($body);
        $tid=$body['topic_id']??'';
        $index=readIndex();
        if(!isset($index[$tid])) fail('Tema no encontrado.');
        $td=readTopicData($tid);
        if($td===null) fail('Fichero de preguntas no encontrado.');
        ok(['topic'=>array_merge($index[$tid],['questions'=>$td['questions']??[]])]);

    case 'admin_get_topics':
        requireAdmin($body);
        $index=readIndex(); $full=[];
        foreach($index as $tid=>$t){
            $td=readTopicData($tid);
            $full[$tid]=array_merge($t,['questions'=>$td['questions']??[]]);
        }
        ok(['topics'=>$full]);

    case 'admin_save_topic':
        $caller=requireAdmin($body);
        $tid=trim($body['topic_id']??''); $curso=trim($body['curso']??'');
        $asig=trim($body['asignatura']??''); $tema=trim($body['tema']??'');
        $qs=$body['questions']??[]; $now=date('Y-m-d H:i');
        if(!$curso||!$asig||!$tema) fail('Faltan datos del tema.');
        if(empty($qs)) fail('El tema debe tener al menos una pregunta.');
        foreach($qs as $q){ if(empty($q['type'])||empty($q['q'])) fail('Formato de pregunta incorrecto.'); }
        $index=readIndex();
        if(!$tid) $tid=uniqid('t_');
        $active   =isset($index[$tid]['active'])    ?$index[$tid]['active']    :true;
        $createdBy=isset($index[$tid]['created_by'])?$index[$tid]['created_by']:$caller;
        $createdAt=isset($index[$tid]['created_at'])?$index[$tid]['created_at']:$now;
        $index[$tid]=[
            'curso'=>$curso,'asignatura'=>$asig,'tema'=>$tema,'active'=>$active,
            'created_by'=>$createdBy,'created_at'=>$createdAt,
            'updated_by'=>$caller,'updated_at'=>$now,'q_count'=>count($qs)
        ];
        writeIndex($index);
        writeTopicData($tid,['questions'=>$qs]);
        ok(['topic_id'=>$tid]);

    case 'admin_toggle_active':
        requireAdmin($body); $tid=$body['topic_id']??'';
        $index=readIndex();
        if(!isset($index[$tid])) fail('Tema no encontrado.');
        $index[$tid]['active']=!($index[$tid]['active']??true);
        writeIndex($index); ok(['active'=>$index[$tid]['active']]);

    case 'admin_delete_topic':
        requireAdmin($body); $tid=$body['topic_id']??'';
        $index=readIndex();
        if(!isset($index[$tid])) fail('Tema no encontrado.');
        $f=topicFile($tid); if(file_exists($f)) unlink($f);
        unset($index[$tid]); writeIndex($index); ok();

    case 'admin_get_admins':
        requireAdmin($body); $admins=readJSON($ADMIN_FILE); $safe=[];
        foreach($admins as $u=>$d) $safe[$u]=['name'=>$d['name']];
        ok(['admins'=>$safe]);

    case 'admin_save_admin':
        $caller=requireAdmin($body);
        $nu=trim($body['new_user']??''); $np=$body['new_pass']??''; $name=trim($body['new_name']??'');
        if(strlen($nu)<2) fail('Usuario mínimo 2 caracteres.');
        if(strlen($np)<4) fail('Contraseña mínimo 4 caracteres.');
        if(!$name) fail('Introduce un nombre.');
        $admins=readJSON($ADMIN_FILE); $isNew=!isset($admins[$nu]);
        $admins[$nu]=['pass'=>password_hash($np,PASSWORD_DEFAULT),'name'=>$name];
        writeJSON($ADMIN_FILE,$admins); ok(['msg'=>$isNew?'Profesor creado.':'Profesor actualizado.']);

    case 'admin_delete_admin':
        $caller=requireAdmin($body); $target=trim($body['target_user']??'');
        if($target===$caller) fail('No puedes eliminarte a ti mismo.');
        $admins=readJSON($ADMIN_FILE);
        if(!isset($admins[$target])) fail('Profesor no encontrado.');
        unset($admins[$target]); writeJSON($ADMIN_FILE,$admins); ok();

    case 'admin_get_users':
        requireAdmin($body); $users=readJSON($USERS_FILE); $list=[];
        foreach($users as $name=>$data){
            $g=0; $t=0;
            foreach(($data['scores']??[]) as $s){ $g+=($s['games']??0); $t++; }
            $list[]=['name'=>$name,'topics'=>$t,'games'=>$g];
        }
        usort($list,fn($a,$b)=>strcmp($a['name'],$b['name'])); ok(['users'=>$list]);

    case 'admin_delete_user':
        requireAdmin($body); $target=trim($body['target_user']??'');
        $users=readJSON($USERS_FILE);
        if(!isset($users[$target])) fail('Alumno no encontrado.');
        unset($users[$target]); writeJSON($USERS_FILE,$users); ok();

    case 'admin_delete_all_users':
        requireAdmin($body); writeJSON($USERS_FILE,[]); ok();

    case 'admin_reset_ranking':
        requireAdmin($body); $tid=trim($body['topic_id']??'');
        $users=readJSON($USERS_FILE);
        foreach($users as $n=>&$d){ if(isset($d['scores'][$tid])) unset($d['scores'][$tid]); }
        unset($d); writeJSON($USERS_FILE,$users); ok();

    case 'admin_reset_all_rankings':
        requireAdmin($body); $users=readJSON($USERS_FILE);
        foreach($users as $n=>&$d){ $d['scores']=[]; }
        unset($d); writeJSON($USERS_FILE,$users); ok();

    case 'save_branding':
        requireAdmin($body);
        $bd=$body['branding']??null;
        if(!is_array($bd)||empty($bd['nombre'])) fail('Datos de branding incompletos.');
        file_put_contents(__DIR__.'/branding.json', json_encode($bd, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        ok(['msg'=>'Branding guardado.']);

    case 'diagnose':
        requireAdmin($body);
        ok(['curl'=>function_exists('curl_init'),'allow_url_fopen'=>(bool)ini_get('allow_url_fopen'),'php_version'=>PHP_VERSION,'finfo'=>class_exists('finfo')]);

    case 'admin_change_user_password':
        requireAdmin($body);
        $target = trim($body['target_user'] ?? '');
        $newPass = $body['new_pass'] ?? '';
        if (!$target) fail('Usuario requerido.');
        if (strlen($newPass) < 3) fail('La contraseña debe tener al menos 3 caracteres.');
        $users = readJSON($USERS_FILE);
        if (!isset($users[$target])) fail('Alumno no encontrado.');
        $users[$target]['pass'] = password_hash($newPass, PASSWORD_DEFAULT);
        writeJSON($USERS_FILE, $users);
        ok(['msg' => 'Contraseña actualizada.']);
    
    case 'admin_save_gemini_key':
        requireAdmin($body);
        $key = trim($body['gemini_key'] ?? '');
        if (!$key) fail('La key no puede estar vacía.');
        $cfg = file_exists($DATA_DIR.'/gemini_config.json')
            ? json_decode(file_get_contents($DATA_DIR.'/gemini_config.json'), true)
            : [];
        $cfg['key'] = $key;
        file_put_contents($DATA_DIR.'/gemini_config.json', json_encode($cfg, JSON_PRETTY_PRINT));
        ok(['msg' => 'Key guardada.']);

    case 'admin_check_gemini_key':
        requireAdmin($body);
        $configured = false;
        if (file_exists($DATA_DIR.'/gemini_config.json')) {
            $cfg = json_decode(file_get_contents($DATA_DIR.'/gemini_config.json'), true);
            $configured = !empty($cfg['key']);
        }
        ok(['configured' => $configured]);
    
    case 'admin_get_stats':
        requireAdmin($body);
        $users = readJSON($USERS_FILE);
        $list = [];
        foreach($users as $name => $data){
            $scores = $data['scores'] ?? [];
            $total_games = 0;
            $scores_out = [];
            foreach($scores as $tid => $s){
                $total_games += $s['games'] ?? 0;
                $scores_out[$tid] = [
                    'games' => $s['games'] ?? 0,
                    'best'  => $s['best']  ?? 0
                ];
            }
            $list[] = [
                'name'        => $name,
                'total_games' => $total_games,
                'scores'      => $scores_out
            ];
        }
        usort($list, fn($a,$b) => strcmp($a['name'], $b['name']));
        ok(['users' => $list]);

    default: fail('Acción desconocida.');
}
?>
