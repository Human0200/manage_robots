<?php
// Настройка логирования
function logToFile($data)
{
    $logFile = __DIR__ . '/lead_contact_attach.log';
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
    !isset($data['properties']['ID']) || !isset($data['properties']['Phone'])
) {
    logToFile('Ошибка: Не хватает обязательных полей в запросе');
    http_response_code(400);
    echo json_encode(['error' => 'Требуемые поля: access_token, domain, LeadID, LeadPhone']);
    exit;
}

// Параметры запроса
$access_token = $data['auth']['access_token'];
$domain = $data['auth']['domain'];
$entity_type = $data['properties']['entity_type']; // 'lead' или 'deal'
$leadPhone = $data['properties']['Phone'];
$leadId = $data['properties']['ID'];
$eventToken = $data['event_token'];

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

function findContactByPhone($phone, $access_token, $domain)
{
    // Вариант 1: Поиск по приведенному номеру (без нецифровых символов)
    $formattedPhone = preg_replace('/\D/', '', $phone);

    $contacts = callB24Api('crm.contact.list', [
        'filter' => ['PHONE' => $formattedPhone],
        'select' => ['ID', 'PHONE']
    ], $access_token, $domain);

    $foundContactId = null;
    if ($contacts && isset($contacts['result'])) {
        foreach ($contacts['result'] as $contact) {
            if (!empty($contact['PHONE'])) {
                foreach ($contact['PHONE'] as $phoneData) {
                    if (preg_replace('/\D/', '', $phoneData['VALUE']) == $formattedPhone) {
                        return $contact['ID']; // Возвращаем ID найденного контакта
                    }
                }
            }
        }
    }

    // Если по приведенному номеру не найдено, Вариант 2: Поиск по оригинальному номеру
    $contacts = callB24Api('crm.contact.list', [
        'filter' => ['PHONE' => $phone],
        'select' => ['ID', 'PHONE']
    ], $access_token, $domain);
    if ($contacts && isset($contacts['result'])) {
        foreach ($contacts['result'] as $contact) {
            if (!empty($contact['PHONE'])) {
                foreach ($contact['PHONE'] as $phoneData) {
                    if ($phoneData['VALUE'] == $phone) {
                        return $contact['ID']; // Возвращаем ID найденного контакта
                    }
                }
            }
        }
    }

    return null; // Контакт не найден
}

$foundContactId = findContactByPhone($leadPhone, $access_token, $domain);

if ($foundContactId) {
    if ($entity_type === 'deal') {
        // 2. Обновляем сделку, прикрепляя контакт
        $updateResult = callB24Api('crm.deal.update', [
            'id' => $leadId,
            'fields' => ['CONTACT_ID' => $foundContactId]
        ], $access_token, $domain);
        $result = callB24Api(
            'bizproc.event.send',
            [
                'event_token' => $eventToken,
                'return_values' => [
                    'response' => 'bdskjfnh'
                ]
            ],
            $access_token,
            $domain
        );
    } elseif ($entity_type === 'lead') {
        // 2. Обновляем лид, прикрепляя контакт
        $updateResult = callB24Api('crm.lead.update', [
            'id' => $leadId,
            'fields' => ['CONTACT_ID' => $foundContactId]
        ], $access_token, $domain);
        $result = callB24Api(
            'bizproc.event.send',
            [
                'event_token' => $eventToken,
                'return_values' => [
                    'response' => 'bdskjfnh'
                ]
            ],
            $access_token,
            $domain
        );
    }

    if ($updateResult && isset($updateResult['result']) && $updateResult['result'] === true) {
        echo json_encode([
            'success' => true,
            'message' => 'Контакт успешно прикреплен',
            'contact_id' => $foundContactId,
            'lead_id' => $leadId
        ]);
    } else {
        $result = callB24Api(
            'bizproc.event.send',
            [
                'event_token' => $eventToken,
                'return_values' => [
                    'response' => 'bdskjfnh'
                ]
            ],
            $access_token,
            $domain
        );
        logToFile("Ошибка при обновлении лида {$leadId}: " . print_r($updateResult, true));
        http_response_code(500);
        echo json_encode([
            'error' => "Ошибка при обновлении лида: {$leadId}",
            'details' => $updateResult
        ]);
    }
} else {

    $result = callB24Api(
        'bizproc.event.send',
        [
            'event_token' => $eventToken,
            'return_values' => [
                'response' => 'bdskjfnh'
            ]
        ],
        $access_token,
        $domain
    );


    logToFile("Контакт с телефоном {$leadPhone} не найден:");
    http_response_code(404);
    echo json_encode([
        'error' => 'Контакт с таким номером не найден',
        'phone' => $leadPhone
    ]);
}
?>