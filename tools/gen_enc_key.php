<?php

declare(strict_types=1);

/**
 * 저장 암호화 키 생성 — `.env` 의 `APP_ENC_KEY` 에 넣을 값을 출력한다.
 *
 * ⚠️ **키는 서버마다 다르면 안 된다.** 한 번 만들어서 웹서버 전부에 같은 값을 넣어야 한다.
 *    키가 다르면 다른 서버가 저장한 값을 못 읽는다. 키를 잃어버리면 복구 방법이 없다 —
 *    비밀번호 관리자나 AWS Secrets Manager 에 따로 보관할 것.
 *
 *    사용:  php tools/gen_enc_key.php
 */

require_once dirname(__DIR__) . '/inc/Crypto.php';

$key = Crypto::generateKey();

echo "생성된 키 — .env 에 아래 한 줄을 추가하세요:\n\n";
echo "APP_ENC_KEY={$key}\n\n";
echo "⚠️ 웹서버가 여러 대면 전부 같은 값을 넣어야 합니다.\n";
echo "⚠️ 이 키를 잃으면 암호화된 결제키·계좌번호를 복구할 수 없습니다. 별도 보관하세요.\n";
