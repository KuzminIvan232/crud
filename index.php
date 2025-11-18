<?php
// Встановлення заголовків для RESTful API та CORS
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Функції для роботи з файлом (імітація БД)
function getNotes() {
    if (!file_exists('data.json')) {
        file_put_contents('data.json', json_encode([])); // Створення пустого файлу, якщо він відсутній
    }
    $data = file_get_contents('data.json');
    return json_decode($data, true) ?: [];
}

function saveNotes(array $notes) {
    file_put_contents('data.json', json_encode($notes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Функція для генерації UUID (для id нотаток)
function generateUuid() {
    return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
        mt_rand( 0, 0xffff ),
        mt_rand( 0, 0x0fff ) | 0x4000,
        mt_rand( 0, 0x3fff ) | 0x8000,
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
    );
}

// -----------------------------------------------------
// 1. ОТРИМАННЯ МЕТОДУ ТА ID
// -----------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];

// Отримання ID з URI (наприклад, /index.php/notes/id-123)
// Це припущення роутингу, коли ID є останнім елементом шляху
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/index.php', '', $uri); // Видаляємо /index.php, якщо він є у шляху
$uriParts = array_values(array_filter(explode('/', $uri))); // Очищуємо від пустих елементів
$id = isset($uriParts[count($uriParts) - 1]) ? $uriParts[count($uriParts) - 1] : null;

// Якщо шлях містить 'notes', ID - це наступний елемент
$id = (strtolower(isset($uriParts[0]) ? $uriParts[0] : '') !== 'notes' || count($uriParts) < 2) ? null : $uriParts[1];


// -----------------------------------------------------
// 2. ОБРОБКА OPTIONS (Preflight запити)
// -----------------------------------------------------
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -----------------------------------------------------
// 3. CRUD ОПЕРАЦІЇ
// -----------------------------------------------------

$notes = getNotes();

switch ($method) {
    // GET: Отримати всі нотатки або одну за ID
    case 'GET':
        if ($id) {
            // GET /notes/{id}
            $note = array_filter($notes, function ($n) use ($id) {
                return $n['id'] === $id;
            });
            if ($note) {
                http_response_code(200);
                echo json_encode(array_values($note)[0], JSON_UNESCAPED_UNICODE);
            } else {
                // RESTful: Повертаємо 404 Not Found, якщо ресурс не знайдено
                http_response_code(404);
                echo json_encode(["error" => "Note with ID $id not found"]);
            }
        } else {
            // GET /notes
            http_response_code(200);
            echo json_encode($notes, JSON_UNESCAPED_UNICODE);
        }
        break;

    // POST: Створити нову нотатку
    case 'POST':
        // Перевірка наявності тіла запиту та декодування
        $data = json_decode(file_get_contents("php://input"), true);

        // Валідація обов'язкових полів
        if (empty($data['title']) || empty($data['content'])) {
            // RESTful: 400 Bad Request
            http_response_code(400);
            echo json_encode(["error" => "Missing required fields: title and content are mandatory."]);
            break;
        }

        // Створення нової нотатки
        $newNote = [
            'id' => generateUuid(),
            'title' => $data['title'],
            'content' => $data['content'],
            'created_at' => date('c'),
        ];

        $notes[] = $newNote;
        saveNotes($notes);

        // RESTful: 201 Created
        http_response_code(201);
        echo json_encode($newNote, JSON_UNESCAPED_UNICODE);
        break;

    // PATCH: Часткове оновлення нотатки за ID
    case 'PATCH':
        if (!$id) {
            http_response_code(400);
            echo json_encode(["error" => "ID is required for PATCH method."]);
            break;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $noteFound = false;

        foreach ($notes as $key => $note) {
            if ($note['id'] === $id) {
                // Оновлюємо лише надіслані поля (title або content)
                if (isset($data['title'])) {
                    $notes[$key]['title'] = $data['title'];
                }
                if (isset($data['content'])) {
                    $notes[$key]['content'] = $data['content'];
                }
                $noteFound = true;
                $updatedNote = $notes[$key];
                break;
            }
        }

        if ($noteFound) {
            saveNotes($notes);
            http_response_code(200);
            echo json_encode($updatedNote, JSON_UNESCAPED_UNICODE);
        } else {
            // RESTful: 404 Not Found
            http_response_code(404);
            echo json_encode(["error" => "Note with ID $id not found for update."]);
        }
        break;

    // DELETE: Видалити нотатку за ID
    case 'DELETE':
        if (!$id) {
            http_response_code(400);
            echo json_encode(["error" => "ID is required for DELETE method."]);
            break;
        }

        $initialCount = count($notes);
        // Фільтруємо масив, залишаючи всі нотатки, окрім тієї, що видаляється
        $notes = array_filter($notes, function ($n) use ($id) {
            return $n['id'] !== $id;
        });
        $notes = array_values($notes); // Переіндексуємо масив

        if (count($notes) < $initialCount) {
            saveNotes($notes);
            // RESTful: 204 No Content - успішне видалення без тіла відповіді
            http_response_code(204);
        } else {
            // RESTful: 404 Not Found
            http_response_code(404);
            echo json_encode(["error" => "Note with ID $id not found for deletion."]);
        }
        break;

    default:
        // RESTful: 405 Method Not Allowed
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed."]);
        break;
}
?>