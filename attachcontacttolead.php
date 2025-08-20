<?php
// Настройка логирования
function logToFile($data)
{
    $logFile = __DIR__ . '/attach_contact_to_lead.log';
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
    !isset($data['properties']['ID']) || !isset($data['properties']['Phone']) ||
    !isset($data['properties']['entity_type'])
) {
    logToFile('Ошибка: Не хватает обязательных полей в запросе');
    http_response_code(400);
    echo json_encode(['error' => 'Требуемые поля: access_token, domain, ID, Phone, entity_type']);
    exit;
}

// Параметры запроса
$access_token = $data['auth']['access_token'];
$domain = $data['auth']['domain'];
$entity_type = $data['properties']['entity_type']; // 'lead' или 'deal'
$entityPhone = $data['properties']['Phone'];
$entityId = intval($data['properties']['ID']);
$eventToken = isset($data['event_token']) ? $data['event_token'] : null;

// Логирование начала работы
logToFile([
    'action' => 'start',
    'entity_type' => $entity_type,
    'entity_id' => $entityId,
    'phone' => $entityPhone
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        logToFile('CURL Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    return json_decode($response, true);
}

// Функция отправки события в бизнес-процесс
function sendBizprocEvent($eventToken, $returnValues, $access_token, $domain)
{
    if (!$eventToken) {
        return true; // Если нет токена события, не отправляем
    }
    
    return callB24Api(
        'bizproc.event.send',
        [
            'event_token' => $eventToken,
            'return_values' => $returnValues
        ],
        $access_token,
        $domain
    );
}

// Функция поиска контакта по телефону
function findContactByPhone($phone, $access_token, $domain)
{
    logToFile(['search_phone' => $phone]);
    
    // Приводим номер к единому формату (только цифры)
    $formattedPhone = preg_replace('/\D/', '', $phone);
    
    // Если номер слишком короткий, возвращаем null
    if (strlen($formattedPhone) < 7) {
        logToFile(['error' => 'Номер телефона слишком короткий', 'phone' => $phone]);
        return null;
    }
    
    // Вариант 1: Поиск по приведенному номеру (без нецифровых символов)
    $contacts = callB24Api('crm.contact.list', [
        'filter' => ['PHONE' => $formattedPhone],
        'select' => ['ID', 'PHONE', 'NAME', 'LAST_NAME']
    ], $access_token, $domain);

    if ($contacts && isset($contacts['result']) && !empty($contacts['result'])) {
        foreach ($contacts['result'] as $contact) {
            if (!empty($contact['PHONE'])) {
                foreach ($contact['PHONE'] as $phoneData) {
                    $contactFormattedPhone = preg_replace('/\D/', '', $phoneData['VALUE']);
                    if ($contactFormattedPhone === $formattedPhone) {
                        logToFile(['found_contact_formatted' => $contact['ID'], 'contact_name' => $contact['NAME'] . ' ' . $contact['LAST_NAME']]);
                        return $contact['ID'];
                    }
                }
            }
        }
    }

    // Вариант 2: Поиск по оригинальному номеру
    $contacts = callB24Api('crm.contact.list', [
        'filter' => ['PHONE' => $phone],
        'select' => ['ID', 'PHONE', 'NAME', 'LAST_NAME']
    ], $access_token, $domain);
    
    if ($contacts && isset($contacts['result']) && !empty($contacts['result'])) {
        foreach ($contacts['result'] as $contact) {
            if (!empty($contact['PHONE'])) {
                foreach ($contact['PHONE'] as $phoneData) {
                    if ($phoneData['VALUE'] === $phone) {
                        logToFile(['found_contact_original' => $contact['ID'], 'contact_name' => $contact['NAME'] . ' ' . $contact['LAST_NAME']]);
                        return $contact['ID'];
                    }
                }
            }
        }
    }

    // Вариант 3: Поиск по частичному совпадению (последние 7-10 цифр)
    if (strlen($formattedPhone) >= 10) {
        $shortPhone = substr($formattedPhone, -7); // Последние 7 цифр
        
        $contacts = callB24Api('crm.contact.list', [
            'filter' => ['PHONE' => $shortPhone],
            'select' => ['ID', 'PHONE', 'NAME', 'LAST_NAME']
        ], $access_token, $domain);
        
        if ($contacts && isset($contacts['result']) && !empty($contacts['result'])) {
            foreach ($contacts['result'] as $contact) {
                if (!empty($contact['PHONE'])) {
                    foreach ($contact['PHONE'] as $phoneData) {
                        $contactFormattedPhone = preg_replace('/\D/', '', $phoneData['VALUE']);
                        if (substr($contactFormattedPhone, -7) === $shortPhone) {
                            logToFile(['found_contact_partial' => $contact['ID'], 'contact_name' => $contact['NAME'] . ' ' . $contact['LAST_NAME']]);
                            return $contact['ID'];
                        }
                    }
                }
            }
        }
    }

    logToFile(['contact_not_found' => $phone]);
    return null;
}

// Основная логика
try {
    // Поиск контакта по телефону
    $foundContactId = findContactByPhone($entityPhone, $access_token, $domain);

    if ($foundContactId) {
        // Обновляем сущность, прикрепляя контакт
        $updateMethod = ($entity_type === 'deal') ? 'crm.deal.update' : 'crm.lead.update';
        
        $updateResult = callB24Api($updateMethod, [
            'id' => $entityId,
            'fields' => ['CONTACT_ID' => $foundContactId]
        ], $access_token, $domain);

        if ($updateResult && isset($updateResult['result']) && $updateResult['result'] === true) {
            // Успешное обновление
            $response = [
                'success' => true,
                'message' => 'Контакт успешно прикреплен',
                'contact_id' => $foundContactId,
                'entity_id' => $entityId,
                'entity_type' => $entity_type
            ];

            // Отправляем событие в бизнес-процесс
            sendBizprocEvent($eventToken, [
                'success' => 'true',
                'contact_id' => $foundContactId,
                'message' => 'Контакт найден и прикреплен'
            ], $access_token, $domain);

            logToFile(['success' => $response]);
            echo json_encode($response);
        } else {
            // Ошибка при обновлении
            sendBizprocEvent($eventToken, [
                'success' => 'false',
                'error' => 'Ошибка при обновлении сущности',
                'details' => $updateResult
            ], $access_token, $domain);

            logToFile("Ошибка при обновлении {$entity_type} {$entityId}: " . print_r($updateResult, true));
            http_response_code(500);
            echo json_encode([
                'error' => "Ошибка при обновлении {$entity_type}: {$entityId}",
                'details' => $updateResult
            ]);
        }
    } else {
        // Контакт не найден
        sendBizprocEvent($eventToken, [
            'success' => 'false',
            'error' => 'Контакт не найден',
            'phone' => $entityPhone
        ], $access_token, $domain);

        logToFile("Контакт с телефоном {$entityPhone} не найден");
        http_response_code(404);
        echo json_encode([
            'error' => 'Контакт с таким номером не найден',
            'phone' => $entityPhone,
            'entity_type' => $entity_type,
            'entity_id' => $entityId
        ]);
    }

} catch (Exception $e) {
    // Обработка исключений
    $errorMessage = 'Внутренняя ошибка сервера: ' . $e->getMessage();
    
    sendBizprocEvent($eventToken, [
        'success' => 'false',
        'error' => $errorMessage
    ], $access_token, $domain);

    logToFile(['exception' => $errorMessage, 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode([
        'error' => $errorMessage
    ]);
}
?>