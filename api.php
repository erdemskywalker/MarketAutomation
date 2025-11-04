<?php
header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$productsFile = 'products.json';
$categoriesFile = 'categories.json';

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

$method = $_SERVER['REQUEST_METHOD'];

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'getProducts':
            $products = readJSON($productsFile);
            echo json_encode(['success' => true, 'data' => $products]);
            break;

        case 'addProduct':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['barcode']) || !isset($input['name']) || !isset($input['price'])) {
                throw new Exception('Eksik ürün bilgisi');
            }

            $products = readJSON($productsFile);
            
            foreach ($products as $p) {
                if ($p['barcode'] === $input['barcode']) {
                    throw new Exception('Bu barkod zaten mevcut!');
                }
            }

            $maxId = 0;
            foreach ($products as $p) {
                if ($p['id'] > $maxId) {
                    $maxId = $p['id'];
                }
            }

            $newProduct = [
                'id' => $maxId + 1,
                'barcode' => trim($input['barcode']),
                'name' => trim($input['name']),
                'desc' => trim($input['desc'] ?? ''),
                'price' => (float)$input['price'],
                'category' => trim($input['category'] ?? 'Genel'),
                'img' => trim($input['img'] ?? ''),
                'stock' => isset($input['stock']) ? max(0, (int)$input['stock']) : 0
            ];

            $products[] = $newProduct;
            
            if (writeJSON($productsFile, $products)) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Ürün başarıyla eklendi!',
                    'data' => $newProduct
                ]);
            } else {
                throw new Exception('Ürün kaydedilemedi!');
            }
            break;

        case 'updateProduct':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['id'])) {
                throw new Exception('Ürün ID belirtilmedi');
            }

            $products = readJSON($productsFile);
            $found = false;
            
            foreach ($products as $index => $p) {
                if ($p['id'] == $input['id']) {
                    foreach ($products as $checkProduct) {
                        if ($checkProduct['id'] != $input['id'] && $checkProduct['barcode'] === $input['barcode']) {
                            throw new Exception('Bu barkod başka bir üründe kullanılıyor!');
                        }
                    }

                    $products[$index] = [
                        'id' => (int)$input['id'],
                        'barcode' => trim($input['barcode']),
                        'name' => trim($input['name']),
                        'desc' => trim($input['desc'] ?? ''),
                        'price' => (float)$input['price'],
                        'category' => trim($input['category'] ?? 'Genel'),
                        'img' => trim($input['img'] ?? ''),
                        'stock' => isset($input['stock']) ? max(0, (int)$input['stock']) : (isset($p['stock']) ? (int)$p['stock'] : 0)
                    ];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new Exception('Ürün bulunamadı!');
            }

            if (writeJSON($productsFile, $products)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Ürün başarıyla güncellendi!',
                    'data' => $products[$index]
                ]);
            } else {
                throw new Exception('Ürün güncellenemedi!');
            }
            break;

        case 'deleteProduct':
            $id = (int)($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('Geçersiz ürün ID');
            }

            $products = readJSON($productsFile);
            $newProducts = [];
            $deleted = null;

            foreach ($products as $p) {
                if ($p['id'] == $id) {
                    $deleted = $p;
                } else {
                    $newProducts[] = $p;
                }
            }

            if (!$deleted) {
                throw new Exception('Ürün bulunamadı!');
            }

            if (writeJSON($productsFile, $newProducts)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Ürün başarıyla silindi!',
                    'data' => $deleted
                ]);
            } else {
                throw new Exception('Ürün silinemedi!');
            }
            break;

        case 'getSales':
            $purchasesDir = __DIR__ . '/purchases';
            $salesData = [];

            if (!is_dir($purchasesDir)) {
                echo json_encode(['success' => true, 'data' => []]);
                break;
            }

            $monthFolders = array_diff(scandir($purchasesDir), ['.', '..']);
            
            foreach ($monthFolders as $monthYear) {
                $monthPath = $purchasesDir . '/' . $monthYear;
                
                if (!is_dir($monthPath)) continue;
                
                $salesData[$monthYear] = [];
                
                $dayFiles = array_diff(scandir($monthPath), ['.', '..']);
                
                foreach ($dayFiles as $dayFile) {
                    if (preg_match('/^(\d{2})\.json$/', $dayFile, $matches)) {
                        $day = $matches[1];
                        $dayPath = $monthPath . '/' . $dayFile;
                        
                        $dayData = json_decode(file_get_contents($dayPath), true);
                        if ($dayData) {
                            $salesData[$monthYear][$day] = $dayData;
                        }
                    }
                }
            }

            echo json_encode(['success' => true, 'data' => $salesData]);
            break;

        case 'purchase':
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input || !isset($input['items']) || !is_array($input['items']) || count($input['items']) === 0) {
                throw new Exception('Geçersiz satın alma verisi');
            }

            $items = $input['items'];
            $products = readJSON($productsFile);

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

            foreach ($items as $it) {
                $id = isset($it['id']) ? (int)$it['id'] : 0;
                $qty = isset($it['qty']) ? (int)$it['qty'] : 0;
                if ($id <= 0 || $qty <= 0) continue;

                $idx = $byId[$id]['index'];
                $products[$idx]['stock'] = max(0, (int)($products[$idx]['stock'] ?? 0) - $qty);
            }

            $purchaseData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'items' => [],
                'total' => 0
            ];

            foreach ($items as $it) {
                $id = isset($it['id']) ? (int)$it['id'] : 0;
                $qty = isset($it['qty']) ? (int)$it['qty'] : 0;
                if ($id <= 0 || $qty <= 0) continue;

                $product = $byId[$id]['product'];
                $lineTotal = $product['price'] * $qty;
                
                $purchaseData['items'][] = [
                    'id' => $id,
                    'barcode' => $product['barcode'],
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'qty' => $qty,
                    'total' => $lineTotal
                ];
                
                $purchaseData['total'] += $lineTotal;
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

            $dailyPurchases[] = $purchaseData;

            if (!file_put_contents($dailyFile, json_encode($dailyPurchases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                throw new Exception('Satın alım kaydı oluşturulamadı');
            }

            if (writeJSON($productsFile, $products)) {
                echo json_encode([ 
                    'success' => true, 
                    'message' => 'Satın alma başarıyla tamamlandı', 
                    'data' => $products,
                    'purchase_file' => $monthYear . '/' . $day . '.json'
                ]);
            } else {
                throw new Exception('Satın alma sırasında stok güncellenemedi');
            }|
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
  bv ||| 