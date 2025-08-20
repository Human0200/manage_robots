<?php
// Настройка логирования
function logToFile($data)
{
    $logFile = __DIR__ . '/duplicates_cleaner.log';
    $current = file_get_contents($logFile);
    $current .= date('Y-m-d H:i:s') . " - " . print_r($data, true) . "\n";
    file_put_contents($logFile, $current);
}

// Получение данных из POST-запроса
$input = file_get_contents('php://input');
parse_str($input, $data);
// Проверка обязательных полей
if (
    !isset($data['auth']['access_token']) || !isset($data['auth']['domain']) ||
    !isset($data['properties']['id_to_keep']) || !isset($data['properties']['entity_type'])
) {
    logToFile('Ошибка: Не хватает обязательных полей в запросе');
    http_response_code(400);
    echo json_encode(['error' => 'Требуемые поля: access_token, domain, id_to_keep, entity_type']);

    exit;
}

// Параметры запроса
$access_token = $data['auth']['access_token'];
$domain = $data['auth']['domain'];
$id_to_keep = intval($data['properties']['id_to_keep']);
$entity_type = $data['properties']['entity_type']; // 'lead' или 'deal'
$type_of_delete = $data['properties']['type_of_delete']; // 'this' или 'other'

// Логирование
logToFile([
    'action' => 'start',
    'entity_type' => $entity_type,
    'id_to_keep' => $id_to_keep
]);

// Функция вызова Bitrix24 API
function callB24Api($method, $params, $access_token, $domain)
{
    $url = "https://{$domain}/rest/{$method}?auth={$access_token}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        logToFile('CURL Error: ' . curl_error($ch));
        return false;
    }
    curl_close($ch);
    return json_decode($response, true);
}

// 1. Получаем данные элемента, который нужно оставить
$entity = callB24Api("crm.{$entity_type}.get", ['id' => $id_to_keep], $access_token, $domain);
if (!$entity || !isset($entity['result'])) {
    logToFile("Ошибка: Элемент #{$id_to_keep} не найден");
    http_response_code(404);
    echo json_encode(['error' => "{$entity_type} #{$id_to_keep} не найден"]);
    exit;
}

$entityData = $entity['result'];


// 2. Формируем фильтр для поиска дубликатов
$filter = [];

// Для сделок (deals)
if ($entity_type === 'deal') {
    if (!empty($entityData['CONTACT_ID'])) {
        $filter['CONTACT_ID'] = $entityData['CONTACT_ID'];
    }
     if (!empty($entityData['OPPORTUNITY'])) {
         $filter['OPPORTUNITY'] = $entityData['OPPORTUNITY'];
     }
    if (!empty($entityData['COMMENTS'])) {
        $filter['COMMENTS'] = $entityData['COMMENTS'];
    }
}
// Для лидов (leads)
elseif ($entity_type === 'lead') {
    logToFile([
        'сообщение' => 'ветка лидов'
    ]);
    if (!empty($entityData['PHONE'][0]['VALUE'])) {
        $filter['PHONE'] = $entityData['PHONE'][0]['VALUE'];
    }
    if (!empty($entityData['EMAIL'][0]['VALUE'])) {
        $filter['EMAIL'] = $entityData['EMAIL'][0]['VALUE'];
    }
    if (!empty($entityData['NAME'])) {
        $filter['NAME'] = $entityData['NAME'];
    }
    if (!empty($entityData['LAST_NAME'])) {
        $filter['LAST_NAME'] = $entityData['LAST_NAME'];
    }
    // if (!empty($entityData['TITLE'])) {
    //     $filter['TITLE'] = $entityData['TITLE'];
    // }
    logToFile([
        'массив данных' => json_encode($filter)
    ]);
}

// Если нет критериев для поиска
if (empty($filter)) {
    logToFile([
        'что_не_так' => 'Не найдено критериев для поиска'
    ]);
    echo json_encode(['success' => true, 'message' => 'Не найдено критериев для поиска дубликатов']);
    exit;
}

// 3. Ищем дубликаты
$duplicates = callB24Api("crm.{$entity_type}.list", [
    'filter' => $filter,
    'select' => ['ID']
], $access_token, $domain);
if (!$duplicates || empty($duplicates['result'])) {
    logToFile([
        'что_не_так' => 'дубликаты не найдены'
    ]);
    echo json_encode(['success' => true, 'message' => 'Дубликаты отсутствуют']);
    exit;
}

$duplicateIds = array_column($duplicates['result'], 'ID');


// 4. Удаляем дубликаты (кроме id_to_keep)
$deletedCount = 0;

foreach ($duplicateIds as $id) {
    // logToFile([
    //     'Дубликаты' => json_encode($duplicateIds),
    //     'Кол-во дубликов' => count($duplicateIds)
    // ]);
    if ($type_of_delete === 'this' && count($duplicateIds) > 1) {

        
        if ($id == $id_to_keep) {
            $deleteResult = callB24Api("crm.{$entity_type}.delete", ['id' => $id], $access_token, $domain);
            if ($deleteResult && !isset($deleteResult['error'])) {
                $deletedCount++;

            } else {
                logToFile("Ошибка удаления #{$id}: " . print_r($deleteResult, true));
            }
        }

    } else {
        if ($id != $id_to_keep) {
            $deleteResult = callB24Api("crm.{$entity_type}.delete", ['id' => $id], $access_token, $domain);
            if ($deleteResult && !isset($deleteResult['error'])) {
                $deletedCount++;

            } else {
                logToFile("Ошибка удаления #{$id}: " . print_r($deleteResult, true));
            }
        }
    }
}

// Результат
$response = [
    'success' => true,
    'message' => "Удалено дубликатов: {$deletedCount}",
    'kept_id' => $id_to_keep,
    'entity_type' => $entity_type
];


echo json_encode($response);
?>