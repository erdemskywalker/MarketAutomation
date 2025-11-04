<?php
header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

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

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'getCategories':
            $categories = readJSON($categoriesFile);
            echo json_encode(['success' => true, 'data' => $categories]);
            break;

        case 'addCategory':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['name']) || trim($input['name']) === '') {
                throw new Exception('Kategori adı boş olamaz!');
            }

            $categories = readJSON($categoriesFile);
            
            foreach ($categories as $c) {
                if (strtolower($c['name']) === strtolower(trim($input['name']))) {
                    throw new Exception('Bu kategori zaten mevcut!');
                }
            }

            $maxId = 0;
            foreach ($categories as $c) {
                if ($c['id'] > $maxId) {
                    $maxId = $c['id'];
                }
            }

            $newCategory = [
                'id' => $maxId + 1,
                'name' => trim($input['name']),
                'icon' => trim($input['icon'] ?? 'bi-tag-fill'),
                'color' => trim($input['color'] ?? '#667eea')
            ];

            $categories[] = $newCategory;
            
            if (writeJSON($categoriesFile, $categories)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Kategori başarıyla eklendi!',
                    'data' => $newCategory
                ]);
            } else {
                throw new Exception('Kategori kaydedilemedi!');
            }
            break;

        case 'updateCategory':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['id'])) {
                throw new Exception('Kategori ID belirtilmedi');
            }

            $categories = readJSON($categoriesFile);
            $found = false;
            
            foreach ($categories as $index => $c) {
                if ($c['id'] == $input['id']) {
                    foreach ($categories as $checkCat) {
                        if ($checkCat['id'] != $input['id'] && 
                            strtolower($checkCat['name']) === strtolower(trim($input['name']))) {
                            throw new Exception('Bu kategori adı başka bir kategoride kullanılıyor!');
                        }
                    }

                    $categories[$index] = [
                        'id' => (int)$input['id'],
                        'name' => trim($input['name']),
                        'icon' => trim($input['icon'] ?? 'bi-tag-fill'),
                        'color' => trim($input['color'] ?? '#667eea')
                    ];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new Exception('Kategori bulunamadı!');
            }

            if (writeJSON($categoriesFile, $categories)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Kategori başarıyla güncellendi!',
                    'data' => $categories[$index]
                ]);
            } else {
                throw new Exception('Kategori güncellenemedi!');
            }
            break;

        case 'deleteCategory':
            $id = (int)($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('Geçersiz kategori ID');
            }

            $categories = readJSON($categoriesFile);
            $newCategories = [];
            $deleted = null;

            foreach ($categories as $c) {
                if ($c['id'] == $id) {
                    $deleted = $c;
                } else {
                    $newCategories[] = $c;
                }
            }

            if (!$deleted) {
                throw new Exception('Kategori bulunamadı!');
            }

            if (writeJSON($categoriesFile, $newCategories)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Kategori başarıyla silindi!',
                    'data' => $deleted
                ]);
            } else {
                throw new Exception('Kategori silinemedi!');
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
