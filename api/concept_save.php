<?php
require __DIR__.'/../app/bootstrap.php';
$uid = require_auth();
verify_csrf();
FinanceSchema::ensure($uid);
$d = request_json();

$type = in_array($d['type'] ?? '', ['income','expense'], true) ? $d['type'] : '';
$categoryId = (int)($d['category_id'] ?? 0);
$name = trim((string)($d['name'] ?? ''));
$defaultRaw = trim((string)($d['default_amount'] ?? ''));
$defaultAmount = $defaultRaw === '' ? null : round(max(0, (float)$defaultRaw), 2);
$isAnt = $type === 'expense' && !empty($d['is_ant_expense']);

if ($type === '' || !$categoryId || $name === '') {
    json_response(['ok'=>false,'message'=>'Escribe un nombre y selecciona una categoría.'],422);
}
if (mb_strlen($name) > 120) {
    json_response(['ok'=>false,'message'=>'El nombre del concepto es demasiado largo.'],422);
}

$pdo = db();
try {
    $catSt = $pdo->prepare('SELECT id,name,type,icon FROM categories WHERE id=? AND user_id=? AND active=1');
    $catSt->execute([$categoryId,$uid]);
    $cat = $catSt->fetch();
    if (!$cat) throw new DomainException('Categoría inválida.');
    if ($cat['type'] !== $type) {
        throw new DomainException($type === 'income' ? 'Selecciona una categoría de ingreso.' : 'Selecciona una categoría de egreso.');
    }

    // Evita duplicados por nombre dentro de la misma categoría.
    $find = $pdo->prepare('SELECT id,category_id,name,default_amount,is_ant_expense FROM concepts WHERE user_id=? AND category_id=? AND active=1 AND LOWER(name)=LOWER(?) ORDER BY id LIMIT 1');
    $find->execute([$uid,$categoryId,$name]);
    $existing = $find->fetch();
    if ($existing) {
        json_response([
            'ok'=>true,
            'existing'=>true,
            'message'=>'Ese concepto ya existía y quedó seleccionado.',
            'concept'=>[
                'id'=>(int)$existing['id'],
                'category_id'=>(int)$existing['category_id'],
                'name'=>$existing['name'],
                'default_amount'=>$existing['default_amount'],
                'is_ant_expense'=>(int)$existing['is_ant_expense'],
            ],
        ]);
    }

    $st = $pdo->prepare('INSERT INTO concepts(user_id,category_id,name,default_amount,is_ant_expense) VALUES(?,?,?,?,?)');
    $st->execute([$uid,$categoryId,$name,$defaultAmount,$isAnt ? 1 : 0]);
    $id = (int)$pdo->lastInsertId();
    emit_event($uid,'config_changed',['concept_id'=>$id]);

    json_response([
        'ok'=>true,
        'existing'=>false,
        'message'=>'Concepto creado.',
        'concept'=>[
            'id'=>$id,
            'category_id'=>$categoryId,
            'name'=>$name,
            'default_amount'=>$defaultAmount,
            'is_ant_expense'=>$isAnt ? 1 : 0,
        ],
    ]);
} catch (DomainException $e) {
    json_response(['ok'=>false,'message'=>$e->getMessage()],422);
} catch (Throwable $e) {
    json_response(['ok'=>false,'message'=>'No se pudo crear el concepto.'],500);
}
