<?php
require_once __DIR__ . '/session_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(array('status'=>'error','message'=>'Authentication required'));
    exit;
}

$allowedTemplates = array('energy_diagram','chiller_diagram','template1','template2','template3','template4','template5','template6');
$template = (string)($_GET['template'] ?? $_POST['template'] ?? '');
if (!in_array($template, $allowedTemplates, true)) {
    http_response_code(422);
    echo json_encode(array('status'=>'error','message'=>'Invalid template'));
    exit;
}

$configPath = __DIR__ . '/config/dashboard_layout.json';
$readConfig = static function () use ($configPath) {
    $data = json_decode((string)@file_get_contents($configPath), true);
    return is_array($data) ? $data : array('version'=>1,'layouts'=>array());
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $config = $readConfig();
    echo json_encode(array('status'=>'ok','template'=>$template,'layout'=>$config['layouts'][$template] ?? null), JSON_UNESCAPED_SLASHES);
    exit;
}

$userType = strtolower((string)($_SESSION['user_type'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $userType !== 'superadmin') {
    http_response_code(403);
    echo json_encode(array('status'=>'error','message'=>'Permission denied'));
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = array();
$action = (string)($input['action'] ?? 'save');
$config = $readConfig();
if (!isset($config['layouts']) || !is_array($config['layouts'])) $config['layouts'] = array();

if ($action === 'reset') {
    unset($config['layouts'][$template]);
} else {
    $rawLayout = $input['layout'] ?? null;
    $rawGroups = is_array($rawLayout) ? ($rawLayout['groups'] ?? null) : null;
    if (!is_array($rawGroups) || count($rawGroups) > 30) {
        http_response_code(422);
        echo json_encode(array('status'=>'error','message'=>'Invalid layout'));
        exit;
    }
    $groups = array();
    $totalItems = 0;
    foreach ($rawGroups as $groupId=>$rawGroup) {
        $groupId = (string)$groupId;
        $rawItems = is_array($rawGroup) ? ($rawGroup['items'] ?? null) : null;
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $groupId) || !is_array($rawItems) || count($rawItems) > 150) continue;
        $items = array();
        foreach ($rawItems as $id=>$item) {
            if (++$totalItems > 500) break 2;
            $id = (string)$id;
            if (!preg_match('/^[a-zA-Z0-9_.-]{1,120}$/', $id) || !is_array($item)) continue;
            $items[$id] = array(
                'x'=>max(-300, min(300, round((float)($item['x'] ?? 0), 4))),
                'y'=>max(-30000, min(30000, round((float)($item['y'] ?? 0), 1))),
                'w'=>max(2, min(100, round((float)($item['w'] ?? 100), 4))),
                'h'=>max(20, min(15000, round((float)($item['h'] ?? 120), 1))),
                'z'=>max(-100, min(1000, (int)($item['z'] ?? 0)))
            );
        }
        $groups[$groupId] = array(
            'canvasHeight'=>max(80, min(30000, (int)($rawGroup['canvasHeight'] ?? 400))),
            'items'=>$items
        );
    }
    $texts = array();
    $rawTexts = is_array($rawLayout['texts'] ?? null) ? $rawLayout['texts'] : array();
    foreach (array_slice($rawTexts, 0, 500, true) as $textId=>$textValue) {
        $textId = (string)$textId;
        if (!preg_match('/^[a-zA-Z0-9_.:|-]{1,180}$/', $textId)) continue;
        $textValue = trim(strip_tags((string)$textValue));
        $texts[$textId] = mb_substr($textValue, 0, 300, 'UTF-8');
    }
    $fonts = array();
    $rawFonts = is_array($rawLayout['fonts'] ?? null) ? $rawLayout['fonts'] : array();
    foreach (array_slice($rawFonts, 0, 1000, true) as $fontId=>$fontValue) {
        $fontId = (string)$fontId;
        if (!preg_match('/^[a-zA-Z0-9_.:|-]{1,180}$/', $fontId)) continue;
        $fonts[$fontId] = max(8, min(72, round((float)$fontValue, 1)));
    }
    $widgets = array();
    $rawWidgets = is_array($rawLayout['widgets'] ?? null) ? $rawLayout['widgets'] : array();
    foreach (array_slice($rawWidgets, 0, 100) as $widget) {
        if (!is_array($widget)) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($widget['id'] ?? ''));
        $type = (string)($widget['type'] ?? 'text');
        if ($id === '' || !in_array($type, array('shape','text','value'), true)) continue;
        $shape = (string)($widget['shape'] ?? 'rectangle');
        if (!in_array($shape, array('rectangle','circle','line','transparent'), true)) $shape = 'rectangle';
        $cleanColor = static function ($value, $fallback) {
            $value = trim((string)$value);
            return preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9., %]+\)|transparent)$/', $value) ? $value : $fallback;
        };
        $widgets[] = array(
            'id'=>mb_substr($id, 0, 60, 'UTF-8'),
            'type'=>$type,
            'shape'=>$shape,
            'text'=>mb_substr(trim(strip_tags((string)($widget['text'] ?? 'Text'))), 0, 300, 'UTF-8'),
            'device'=>mb_substr(trim((string)($widget['device'] ?? '')), 0, 100, 'UTF-8'),
            'parameter'=>mb_substr(trim((string)($widget['parameter'] ?? '')), 0, 100, 'UTF-8'),
            'unit'=>mb_substr(trim(strip_tags((string)($widget['unit'] ?? ''))), 0, 30, 'UTF-8'),
            'background'=>$cleanColor($widget['background'] ?? '', '#1769d2'),
            'borderColor'=>$cleanColor($widget['borderColor'] ?? '', '#1769d2'),
            'color'=>$cleanColor($widget['color'] ?? '', '#ffffff'),
            'borderWidth'=>max(0, min(20, (int)($widget['borderWidth'] ?? 2)))
        );
    }
    $hidden = array();
    $rawHidden = is_array($rawLayout['hidden'] ?? null) ? $rawLayout['hidden'] : array();
    foreach (array_slice($rawHidden, 0, 500) as $hiddenId) {
        $hiddenId = (string)$hiddenId;
        if (preg_match('/^[a-zA-Z0-9_.-]{1,120}$/', $hiddenId) && !in_array($hiddenId, $hidden, true)) $hidden[] = $hiddenId;
    }
    $config['layouts'][$template] = array(
        'groups'=>$groups,
        'texts'=>$texts,
        'fonts'=>$fonts,
        'widgets'=>$widgets,
        'hidden'=>$hidden,
        'updatedAt'=>date(DATE_ATOM),
        'updatedBy'=>(string)$_SESSION['username']
    );
}

$config['version'] = 1;
$temporary = $configPath . '.tmp';
$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $configPath)) {
    @unlink($temporary);
    http_response_code(500);
    echo json_encode(array('status'=>'error','message'=>'Unable to save layout'));
    exit;
}

echo json_encode(array('status'=>'ok','template'=>$template,'layout'=>$config['layouts'][$template] ?? null), JSON_UNESCAPED_SLASHES);
