<?php
// Настройка логирования
function logToFile($data)
{
    $logFile = __DIR__ . '/task_result_to_entity.log';
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
    !isset($data['properties']['task_id']) || !isset($data['properties']['entity_type']) ||
    !isset($data['properties']['entity_id']) || !isset($data['properties']['field_code'])
) {
    logToFile('Ошибка: Не хватает обязательных полей в запросе');
    http_response_code(400);
    echo json_encode(['error' => 'Требуемые поля: access_token, domain, task_id, entity_type, entity_id, field_code']);
    exit;
}

// Параметры запроса
$access_token = $data['auth']['access_token'];
$domain = $data['auth']['domain'];
$task_id = intval($data['properties']['task_id']);
$entity_type = $data['properties']['entity_type']; // 'lead', 'contact', 'company', 'deal', 'smart'
$entity_id = intval($data['properties']['entity_id']);
$field_code = $data['properties']['field_code'];
$smart_process_id = isset($data['properties']['smart_process_id']) ? intval($data['properties']['smart_process_id']) : null;

// Логирование начала работы
logToFile([
    'action' => 'start',
    'task_id' => $task_id,
    'entity_type' => $entity_type,
    'entity_id' => $entity_id,
    'field_code' => $field_code,
    'smart_process_id' => $smart_process_id
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

// 1. Получаем данные задачи
$task = callB24Api("tasks.task.get", ['taskId' => $task_id], $access_token, $domain);
if (!$task || !isset($task['result']['task'])) {
    logToFile("Ошибка: Задача #{$task_id} не найдена");
    http_response_code(404);
    echo json_encode(['error' => "Задача #{$task_id} не найдена"]);
    exit;
}

$taskData = $task['result']['task'];
logToFile(['task_data' => $taskData]);

// 2. Получаем результаты задачи
$taskResults = callB24Api("tasks.task.result.list", ['taskId' => $task_id], $access_token, $domain);
if (!$taskResults || !isset($taskResults['result'])) {
    logToFile("Ошибка: Не удалось получить результаты задачи #{$task_id}");
    $taskResults = ['result' => []]; // Пустой массив для продолжения работы
}

$results = $taskResults['result'];
logToFile(['task_results' => $results]);

// 3. Формируем данные для записи в поле
$fileData = [];
$textData = '';

// Если есть результаты, обрабатываем их
if (!empty($results)) {
    $result = $results[0]; // Берем первый результат
    
    // Проверяем, есть ли файлы в результате
    if (!empty($result['FILES'])) {
        $file = $result['FILES'][0]; // Берем первый файл
        $fileData = [
            'fileData' => [
                $file['FILE_ID'],
                $file['NAME']
            ]
        ];
        
        // Также добавляем текстовую информацию о файле
        $textData = "Файл: " . $file['NAME'] . " (ID: " . $file['FILE_ID'] . ")";
    }
    
    // Если файлов нет, но есть текстовый результат
    if (empty($fileData) && !empty($result['TEXT'])) {
        $textData = $result['TEXT'];
    }
}

// Если нет результатов, используем описание задачи
if (empty($fileData) && empty($textData)) {
    $textData = !empty($taskData['DESCRIPTION']) ? $taskData['DESCRIPTION'] : 'Результат задачи получен';
}

// 4. Определяем метод API для обновления сущности
$updateMethod = '';
switch ($entity_type) {
    case 'lead':
        $updateMethod = 'crm.lead.update';
        break;
    case 'contact':
        $updateMethod = 'crm.contact.update';
        break;
    case 'company':
        $updateMethod = 'crm.company.update';
        break;
    case 'deal':
        $updateMethod = 'crm.deal.update';
        break;
    case 'smart':
        if (!$smart_process_id) {
            logToFile('Ошибка: Для смарт-процесса необходимо указать smart_process_id');
            http_response_code(400);
            echo json_encode(['error' => 'Для смарт-процесса необходимо указать smart_process_id']);
            exit;
        }
        $updateMethod = "crm.item.update";
        break;
    default:
        logToFile("Ошибка: Неподдерживаемый тип сущности: {$entity_type}");
        http_response_code(400);
        echo json_encode(['error' => "Неподдерживаемый тип сущности: {$entity_type}"]);
        exit;
}

// 5. Подготавливаем параметры для обновления
$updateParams = ['id' => $entity_id];

// Для смарт-процессов добавляем entityTypeId
if ($entity_type === 'smart') {
    $updateParams['entityTypeId'] = $smart_process_id;
}

// Формируем поля для обновления
$fields = [];

// Если есть файл, записываем его в указанное поле
if (!empty($fileData)) {
    $fields[$field_code] = $fileData['fileData'];
} else {
    // Если файла нет, записываем текст
    $fields[$field_code] = $textData;
}

$updateParams['fields'] = $fields;

logToFile([
    'update_method' => $updateMethod,
    'update_params' => $updateParams
]);

// 6. Обновляем сущность
$updateResult = callB24Api($updateMethod, $updateParams, $access_token, $domain);

if ($updateResult && isset($updateResult['result']) && $updateResult['result']) {
    $response = [
        'success' => true,
        'message' => 'Результат задачи успешно записан в сущность',
        'task_id' => $task_id,
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'field_code' => $field_code,
        'has_file' => !empty($fileData),
        'data_written' => !empty($fileData) ? 'file' : 'text',
        'results_count' => count($results)
    ];
    
    logToFile(['success' => $response]);
    echo json_encode($response);
} else {
    logToFile("Ошибка при обновлении {$entity_type} #{$entity_id}: " . print_r($updateResult, true));
    http_response_code(500);
    echo json_encode([
        'error' => "Ошибка при обновлении {$entity_type}",
        'details' => $updateResult
    ]);
}
?>