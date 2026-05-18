<?php
/**
 * Discussion Board API
 *
 * RESTful API for CRUD operations on discussion topics and their replies.
 * Uses PDO to interact with the MySQL database defined in schema.sql.
 *
 * Database Tables (ground truth: schema.sql):
 *
 * Table: topics
 *   id         INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   subject    VARCHAR(255)  NOT NULL
 *   message    TEXT          NOT NULL
 *   author     VARCHAR(100)  NOT NULL
 *   created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
 *
 * Table: replies
 *   id         INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   topic_id   INT UNSIGNED  NOT NULL — FK → topics.id (ON DELETE CASCADE)
 *   text       TEXT          NOT NULL
 *   author     VARCHAR(100)  NOT NULL
 *   created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
 *
 * HTTP Methods Supported:
 *   GET    — Retrieve topic(s) or replies
 *   POST   — Create a new topic or reply
 *   PUT    — Update an existing topic
 *   DELETE — Delete a topic (cascade removes its replies) or a reply
 *
 * URL scheme (all requests go to index.php):
 *
 *   Topics:
 *     GET    ./api/index.php                  — list all topics
 *     GET    ./api/index.php?id={id}           — get one topic by integer id
 *     POST   ./api/index.php                  — create a new topic
 *     PUT    ./api/index.php                  — update a topic (id in JSON body)
 *     DELETE ./api/index.php?id={id}           — delete a topic
 *
 *   Replies (action parameter selects the replies sub-resource):
 *     GET    ./api/index.php?action=replies&topic_id={id}
 *                                             — list replies for a topic
 *     POST   ./api/index.php?action=reply     — create a reply
 *     DELETE ./api/index.php?action=delete_reply&id={id}
 *                                             — delete a single reply
 *
 * Query parameters for GET all topics:
 *   search — filter rows where subject LIKE or message LIKE or author LIKE
 *   sort   — column to sort by; allowed: subject, author, created_at
 *            (default: created_at)
 *   order  — sort direction; allowed: asc, desc (default: desc)
 *
 * Response format: JSON
 *   Success: { "success": true,  "data": ... }
 *   Error:   { "success": false, "message": "..." }
 */

// ============================================================================
// HEADERS AND INITIALIZATION
// ============================================================================

header("Content-Type: application/json");

header("Access-Control-Allow-Origin: *");

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

header("Access-Control-Allow-Headers: Content-Type, Authorization");


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


require_once __DIR__ . '/../../common/db.php';


$db = getDBConnection();


$method = $_SERVER['REQUEST_METHOD'];


$rawData = file_get_contents('php://input');

$data = json_decode($rawData, true) ?? [];


$action = $_GET['action'] ?? null;

$id = $_GET['id'] ?? null;

$topicId = $_GET['topic_id'] ?? null;

// ============================================================================
// TOPICS FUNCTIONS
// ============================================================================

//----------------------
function getAllTopics(PDO $db): void
{
    $query = "
        SELECT id, subject, message, author, created_at
        FROM topics
    ";

    // search
    if (!empty($_GET['search'])) {

        $query .= "
            WHERE subject LIKE :search
            OR message LIKE :search
            OR author LIKE :search
        ";
    }

    // sort
    $allowedSort = ['subject', 'author', 'created_at'];

    $sort = $_GET['sort'] ?? 'created_at';

    if (!in_array($sort, $allowedSort)) {
        $sort = 'created_at';
    }

    // order
    $order = strtolower($_GET['order'] ?? 'desc');

    if (!in_array($order, ['asc', 'desc'])) {
        $order = 'desc';
    }

    $query .= " ORDER BY $sort $order";

    $stmt = $db->prepare($query);

    // bind search
    if (!empty($_GET['search'])) {

        $search = "%" . $_GET['search'] . "%";

        $stmt->bindParam(':search', $search);
    }

    $stmt->execute();

    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse([
        'success' => true,
        'data' => $topics
    ]);
}

//----------------------
function getTopicById(PDO $db, $id): void
{
    // validate id
    if (!$id || !is_numeric($id)) {

        sendResponse([
            'success' => false,
            'message' => 'Invalid topic ID'
        ], 400);
    }

    // query
    $stmt = $db->prepare("
        SELECT id, subject, message, author, created_at
        FROM topics
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    // fetch row
    $topic = $stmt->fetch(PDO::FETCH_ASSOC);

    // response
    if ($topic) {

        sendResponse([
            'success' => true,
            'data' => $topic
        ]);

    } else {

        sendResponse([
            'success' => false,
            'message' => 'Topic not found'
        ], 404);
    }
}

//----------------------
function createTopic(PDO $db, array $data): void
{
    // validation
    if (
        empty($data['subject']) ||
        empty($data['message']) ||
        empty($data['author'])
    ) {

        sendResponse([
            'success' => false,
            'message' => 'All fields are required'
        ], 400);
    }

    // trim data
    $subject = trim($data['subject']);
    $message = trim($data['message']);
    $author  = trim($data['author']);

    // insert
    $stmt = $db->prepare("
        INSERT INTO topics (subject, message, author)
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        $subject,
        $message,
        $author
    ]);

    // response
    if ($stmt->rowCount() > 0) {

        sendResponse([
            'success' => true,
            'message' => 'Topic created successfully',
            'id' => (int)$db->lastInsertId()
        ], 201);

    } else {

        sendResponse([
            'success' => false,
            'message' => 'Failed to create topic'
        ], 500);
    }
}

//----------------------
function updateTopic(PDO $db, array $data): void
{
    // validate id
    if (empty($data['id'])) {

        sendResponse([
            'success' => false,
            'message' => 'Topic ID is required'
        ], 400);
    }

    $id = $data['id'];

    // check topic exists
    $check = $db->prepare("
        SELECT id FROM topics WHERE id = ?
    ");

    $check->execute([$id]);

    if (!$check->fetch()) {

        sendResponse([
            'success' => false,
            'message' => 'Topic not found'
        ], 404);
    }

    // build update fields
    $fields = [];

    $values = [];

    if (!empty($data['subject'])) {

        $fields[] = "subject = ?";

        $values[] = trim($data['subject']);
    }

    if (!empty($data['message'])) {

        $fields[] = "message = ?";

        $values[] = trim($data['message']);
    }

    // no fields
    if (empty($fields)) {

        sendResponse([
            'success' => false,
            'message' => 'No fields to update'
        ], 400);
    }

    // query
    $query = "
        UPDATE topics
        SET " . implode(", ", $fields) . "
        WHERE id = ?
    ";

    $values[] = $id;

    $stmt = $db->prepare($query);

    $success = $stmt->execute($values);

    // response
    if ($success) {

        sendResponse([
            'success' => true,
            'message' => 'Topic updated successfully'
        ]);

    } else {

        sendResponse([
            'success' => false,
            'message' => 'Failed to update topic'
        ], 500);
    }
}

//----------------------
function deleteTopic(PDO $db, $id): void
{
    // validate id
    if (!$id || !is_numeric($id)) {

        sendResponse([
            'success' => false,
            'message' => 'Invalid topic ID'
        ], 400);
    }

    // check topic exists
    $check = $db->prepare("
        SELECT id FROM topics WHERE id = ?
    ");

    $check->execute([$id]);

    if (!$check->fetch()) {

        sendResponse([
            'success' => false,
            'message' => 'Topic not found'
        ], 404);
    }

    // delete topic
    $stmt = $db->prepare("
        DELETE FROM topics
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    // response
    if ($stmt->rowCount() > 0) {

        sendResponse([
            'success' => true,
            'message' => 'Topic deleted successfully'
        ]);

    } else {

        sendResponse([
            'success' => false,
            'message' => 'Failed to delete topic'
        ], 500);
    }
}


// ============================================================================
// REPLIES FUNCTIONS
// ============================================================================

//----------------------
function getRepliesByTopicId(PDO $db, $topicId): void
{
    // validate topic id
    if (!$topicId || !is_numeric($topicId)) {

        sendResponse([
            'success' => false,
            'message' => 'Invalid topic ID'
        ], 400);
    }

    // query
    $stmt = $db->prepare("
        SELECT id, topic_id, text, author, created_at
        FROM replies
        WHERE topic_id = ?
        ORDER BY created_at ASC
    ");

    $stmt->execute([$topicId]);

    // fetch replies
    $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // response
    sendResponse([
        'success' => true,
        'data' => $replies
    ]);
}

//----------------------
function createReply(PDO $db, array $data): void
{
    // validation
    if (
        empty($data['topic_id']) ||
        empty(trim($data['text'] ?? '')) ||
        empty(trim($data['author'] ?? ''))
    ) {

        sendResponse([
            'success' => false,
            'message' => 'All fields are required'
        ], 400);
    }

    // validate topic_id
    if (!is_numeric($data['topic_id'])) {

        sendResponse([
            'success' => false,
            'message' => 'Invalid topic ID'
        ], 400);
    }

    $topicId = (int)$data['topic_id'];

    $text = trim($data['text']);

    $author = trim($data['author']);

    // check topic exists
    $check = $db->prepare("
        SELECT id FROM topics WHERE id = ?
    ");

    $check->execute([$topicId]);

    if (!$check->fetch()) {

        sendResponse([
            'success' => false,
            'message' => 'Topic not found'
        ], 404);
    }

    // insert reply
    $stmt = $db->prepare("
        INSERT INTO replies (topic_id, text, author)
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        $topicId,
        $text,
        $author
    ]);

    // response
    if ($stmt->rowCount() > 0) {

        $newId = (int)$db->lastInsertId();

        sendResponse([
            'success' => true,
            'message' => 'Reply created successfully',
            'id' => $newId,
            'data' => [
                'id' => $newId,
                'topic_id' => $topicId,
                'text' => $text,
                'author' => $author
            ]
        ], 201);

    } else {

        sendResponse([
            'success' => false,
            'message' => 'Failed to create reply'
        ], 500);
    }
}

//----------------------
function deleteReply(PDO $db, $replyId): void
{
    // validate id
    if (!$replyId || !is_numeric($replyId)) {

        sendResponse([
            'success' => false,
            'message' => 'Invalid reply ID'
        ], 400);
    }

    // check reply exists
    $check = $db->prepare("
        SELECT id FROM replies WHERE id = ?
    ");

    $check->execute([$replyId]);

    if (!$check->fetch()) {

        sendResponse([
            'success' => false,
            'message' => 'Reply not found'
        ], 404);
    }

    // delete reply
    $stmt = $db->prepare("
        DELETE FROM replies
        WHERE id = ?
    ");

    $stmt->execute([$replyId]);

    // response
    if ($stmt->rowCount() > 0) {

        sendResponse([
            'success' => true,
            'message' => 'Reply deleted successfully'
        ]);

    } else {

        sendResponse([
            'success' => false,
            'message' => 'Failed to delete reply'
        ], 500);
    }
}

// ============================================================================
// MAIN REQUEST ROUTER
// ============================================================================

try {

    if ($method === 'GET') {

        // replies
        if ($action === 'replies') {

            getRepliesByTopicId($db, $topicId);

        // single topic
        } elseif ($id) {

            getTopicById($db, $id);

        // all topics
        } else {

            getAllTopics($db);
        }

    } elseif ($method === 'POST') {

        // create reply
        if ($action === 'reply') {

            createReply($db, $data);

        // create topic
        } else {

            createTopic($db, $data);
        }

    } elseif ($method === 'PUT') {

        // update topic
        updateTopic($db, $data);

    } elseif ($method === 'DELETE') {

        // delete reply
        if ($action === 'delete_reply') {

            deleteReply($db, $id);

        // delete topic
        } else {

            deleteTopic($db, $id);
        }

    } else {

        sendResponse([
            'success' => false,
            'message' => 'Method not allowed'
        ], 405);
    }

} catch (PDOException $e) {

    error_log($e->getMessage());

    sendResponse([
        'success' => false,
        'message' => 'Database error'
    ], 500);

} catch (Exception $e) {

    error_log($e->getMessage());

    sendResponse([
        'success' => false,
        'message' => 'Server error'
    ], 500);
}


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

//----------------------
function sendResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);

    echo json_encode($data, JSON_PRETTY_PRINT);

    exit;
}

//----------------------
function sanitizeInput(string $data): string
{
    return htmlspecialchars(
        strip_tags(trim($data)),
        ENT_QUOTES,
        'UTF-8'
    );
}
