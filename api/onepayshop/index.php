<?php
date_default_timezone_set('Asia/Bangkok');
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once 'class.php';

$api = new BcelApi();
$data = json_decode(file_get_contents("php://input"), true);
$action = $_GET['action'] ?? '';

function writeLog($action, $data, $response = null, $type = 'INFO') {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $filename = $logDir . '/api_' . date('Y-m-d') . '.log';
    $timestamp = date('H:i:s');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    $logMessage = "[$timestamp] [$type] [$clientIp] Action: $action\n";
    $logMessage .= ">> Request: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    
    if ($response !== null) {
        $logMessage .= "<< Response: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n";
    }
    
    $logMessage .= "----------------------------------------------------------------------\n";
    
    file_put_contents($filename, $logMessage, FILE_APPEND);
}

switch ($action) {
    case 'register':
        $res = $api->register();
        writeLog($action, $data, $res);
        http_response_code($res['status']);
        echo json_encode($res['data']);
        break;

    case 'activate':
        $res = $api->activate($data['activationcode'], $data['devicetoken']);
        writeLog($action, $data, $res);
        http_response_code($res['status']);
        echo json_encode($res);
        break;

    case 'info':
        $res = $api->getInfo();
        writeLog($action, $data, $res);
        http_response_code($res['status']);
        echo json_encode($res['data']);
        break;

    case 'generate-qr':
        if (!$data['mcid'] || !$data['merchantName'] || !$data['terminalId']) {
            writeLog($action, $data, $err, 'ERROR');
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }
        $qr = $api->buildLaoQr($data['mcid'], $data['merchantName'], $data['terminalId'], $data['amount']);
        writeLog($action, $data, $res);
        echo json_encode(['success' => true, 'qr_string' => $qr]);
        break;

    case 'verify-payment':
        $res = $api->getHistory($data['mcid']);
        $targetAmount = (float) $data['targetAmount'];
        $histories = $res['data']['histories'] ?? [];

        $databaseFile = 'used_tx.json';
        if (!file_exists($databaseFile)) {
            file_put_contents($databaseFile, json_encode([]));
        }
        $usedTransactions = json_decode(file_get_contents($databaseFile), true);

        $found = null;
        $currentTime = time();
        $timeLimit = 5 * 60;

        foreach ($histories as $item) {
            $txFccref = $item['fccref'];
            $txAmount = (float)$item['amount'];
            $txTimestamp = strtotime($item['txtime']);
            $timeDiff = $currentTime - $txTimestamp;

            if (abs($txAmount - $targetAmount) < 0.01 && 
                $timeDiff >= 0 && $timeDiff <= $timeLimit &&
                !isset($usedTransactions[$txFccref])) {
                $found = $item;
                $usedTransactions[$txFccref] = [
                    'id' => $item['id'],
                    'used_at' => date('Y-m-d H:i:s'),
                    'amount' => $txAmount
                ];
                
                file_put_contents($databaseFile, json_encode($usedTransactions, JSON_PRETTY_PRINT));
                break;
            }
        }

        if ($found) {
            writeLog($action, $data, $finalRes);
            echo json_encode(['success' => true, 'data' => $found]);
        } else {
            writeLog($action, ['target' => $targetAmount, 'mcid' => $data['mcid']], ['status' => 'NOT_FOUND', 'history_count' => count($histories)], 'WARN');
            echo json_encode([
                'success' => false, 
                'message' => 'ไม่พบรายการโอน'
            ]);
        }
        break;

    case 'history':
        $res = $api->getHistory($data['mcid']);
        writeLog($action, $data, $res);
        http_response_code($res['status']);
        echo json_encode($res['data']);
        break;

    default:
        writeLog($action, $data, ['error' => 'Action not found'], 'ERROR');
        http_response_code(404);
        echo json_encode(['error' => 'Action not found']);
        break;
}
?>