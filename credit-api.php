<?php
header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$creditsFile = 'credits.json';
$productsFile = 'products.json';

function readJSON($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    return json_decode($content, true) ?: [];
}

function writeJSON($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($file, $json) !== false;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'getCredits':
            $credits = readJSON($creditsFile);
            echo json_encode(['success' => true, 'data' => $credits]);
            break;

        case 'addCredit':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['customerName']) || !isset($input['amount']) || !isset($input['items'])) {
                throw new Exception('Eksik veresiye bilgisi');
            }

            $credits = readJSON($creditsFile);
            $products = readJSON($productsFile);

            $maxId = 0;
            foreach ($credits as $c) {
                if ($c['id'] > $maxId) {
                    $maxId = $c['id'];
                }
            }

            $items = $input['items'];
            $byId = [];
            foreach ($products as $idx => $prod) {
                $byId[$prod['id']] = ['index' => $idx, 'product' => $prod];
            }

            $insufficient = [];
            foreach ($items as $it) {
                $id = isset($it['id']) ? (int)$it['id'] : 0;
                $qty = isset($it['qty']) ? (int)$it['qty'] : 0;
                
                if ($id <= 0 || $qty <= 0) continue;

                if (!isset($byId[$id])) {
                    $insufficient[] = "Ürün ID {$id} bulunamadı";
                    continue;
                }

                $currentStock = isset($byId[$id]['product']['stock']) ? (int)$byId[$id]['product']['stock'] : 0;
                if ($currentStock < $qty) {
                    $insufficient[] = "{$byId[$id]['product']['name']} (ID:{$id}) - mevcut: {$currentStock}, talep: {$qty}";
                }
            }

            if (count($insufficient) > 0) {
                throw new Exception('Yetersiz stok: ' . implode('; ', $insufficient));
            }

            $stockUpdated = false;
            foreach ($items as $it) {
                $id = isset($it['id']) ? (int)$it['id'] : 0;
                $qty = isset($it['qty']) ? (int)$it['qty'] : 0;
                
                if ($id <= 0 || $qty <= 0) continue;

                if (isset($byId[$id])) {
                    $idx = $byId[$id]['index'];
                    $products[$idx]['stock'] = max(0, (int)($products[$idx]['stock'] ?? 0) - $qty);
                    $stockUpdated = true;
                }
            }

            if ($stockUpdated && !writeJSON($productsFile, $products)) {
                throw new Exception('Stok güncellenemedi');
            }

            $monthYear = date('m-Y');
            $purchaseDir = __DIR__ . '/purchases/' . $monthYear;
            
            if (!file_exists($purchaseDir)) {
                mkdir($purchaseDir, 0755, true);
            }

            $day = date('d');
            $dailyFile = $purchaseDir . '/' . $day . '.json';

            $dailyPurchases = [];
            if (file_exists($dailyFile)) {
                $dailyPurchases = json_decode(file_get_contents($dailyFile), true) ?: [];
            }

            $saleRecord = [
                'timestamp' => date('Y-m-d H:i:s'),
                'type' => 'credit',
                'customer' => trim($input['customerName']),
                'phone' => trim($input['customerPhone'] ?? ''),
                'items' => [],
                'total' => (float)$input['amount']
            ];

            $dailyPurchases[] = $saleRecord;
            file_put_contents($dailyFile, json_encode($dailyPurchases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $newCredit = [
                'id' => $maxId + 1,
                'customerName' => trim($input['customerName']),
                'customerPhone' => trim($input['customerPhone'] ?? ''),
                'amount' => (float)$input['amount'],
                'paid' => 0.0,
                'remaining' => (float)$input['amount'],
                'items' => $items,
                'createdAt' => date('Y-m-d H:i:s'),
                'status' => 'open',
                'payments' => []
            ];

            $credits[] = $newCredit;
            
            if (writeJSON($creditsFile, $credits)) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Veresiye kaydı başarıyla oluşturuldu!',
                    'data' => $newCredit
                ]);
            } else {
                throw new Exception('Veresiye kaydı oluşturulamadı!');
            }
            break;

        case 'makePayment':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['id']) || !isset($input['amount'])) {
                throw new Exception('Eksik ödeme bilgisi');
            }

            $creditId = (int)$input['id'];
            $paymentAmount = (float)$input['amount'];

            if ($paymentAmount <= 0) {
                throw new Exception('Geçersiz ödeme tutarı');
            }

            $credits = readJSON($creditsFile);
            $found = false;
            
            foreach ($credits as $index => $credit) {
                if ($credit['id'] == $creditId) {
                    $remaining = (float)$credit['remaining'];
                    
                    if ($paymentAmount > $remaining) {
                        throw new Exception('Ödeme tutarı kalan borçtan fazla olamaz!');
                    }

                    $payment = [
                        'amount' => $paymentAmount,
                        'date' => date('Y-m-d H:i:s'),
                        'note' => trim($input['note'] ?? '')
                    ];

                    if (!isset($credits[$index]['payments'])) {
                        $credits[$index]['payments'] = [];
                    }
                    $credits[$index]['payments'][] = $payment;

                    $credits[$index]['paid'] = (float)$credit['paid'] + $paymentAmount;
                    $credits[$index]['remaining'] = $remaining - $paymentAmount;

                    if ($credits[$index]['remaining'] <= 0.01) {
                        $credits[$index]['remaining'] = 0;
                        $credits[$index]['status'] = 'paid';
                    } else if ($credits[$index]['paid'] > 0) {
                        $credits[$index]['status'] = 'partial';
                    }

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new Exception('Veresiye kaydı bulunamadı!');
            }

            if (writeJSON($creditsFile, $credits)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Ödeme başarıyla kaydedildi!',
                    'data' => $credits[$index]
                ]);
            } else {
                throw new Exception('Ödeme kaydedilemedi!');
            }
            break;

        case 'deleteCredit':
            $id = (int)($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('Geçersiz veresiye ID');
            }

            $credits = readJSON($creditsFile);
            $newCredits = [];
            $deleted = null;

            foreach ($credits as $credit) {
                if ($credit['id'] == $id) {
                    if ($credit['status'] !== 'paid') {
                        throw new Exception('Sadece tamamen ödenen veresiyeler silinebilir!');
                    }
                    $deleted = $credit;
                } else {
                    $newCredits[] = $credit;
                }
            }

            if (!$deleted) {
                throw new Exception('Veresiye kaydı bulunamadı!');
            }

            if (writeJSON($creditsFile, $newCredits)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Veresiye kaydı başarıyla silindi!',
                    'data' => $deleted
                ]);
            } else {
                throw new Exception('Veresiye kaydı silinemedi!');
            }
            break;

        default:
            throw new Exception('Geçersiz işlem');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
