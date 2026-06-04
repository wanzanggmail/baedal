#!/usr/bin/env python3
"""
암호로 보호된 Office xlsx 복호화 (파일 열기 암호)
사용: python decrypt_xlsx.py <입력.xlsx> <출력.xlsx> <비밀번호>
필요: pip install msoffcrypto-tool
"""
from __future__ import annotations

import sys


def main() -> int:
    if len(sys.argv) != 4:
        sys.stderr.write("Usage: decrypt_xlsx.py <input> <output> <password>\n")
        return 1

    inp, out, password = sys.argv[1], sys.argv[2], sys.argv[3]

    try:
        import msoffcrypto
    except ImportError:
        sys.stderr.write(
            "msoffcrypto-tool 미설치: pip install msoffcrypto-tool\n"
        )
        return 2

    try:
        with open(inp, "rb") as encrypted:
            office = msoffcrypto.OfficeFile(encrypted)
            office.load_key(password=password)
            with open(out, "wb") as decrypted:
                office.decrypt(decrypted)
    except Exception as exc:  # noqa: BLE001
        sys.stderr.write(str(exc) + "\n")
        return 3

    try:
        with open(out, "rb") as check:
            head = check.read(4)
    except OSError as exc:
        sys.stderr.write(str(exc) + "\n")
        return 5

    if head == b"PK\x03\x04":
        return 0
    if head == b"\xd0\xcf\x11\xe0":
        sys.stderr.write("decrypted_ole_xls: xls 형식입니다. xlsx로 저장된 파일을 업로드하세요.\n")
        return 6
    if head == b"":
        sys.stderr.write("decrypted_empty\n")
        return 5

    sys.stderr.write(
        "decrypted_invalid: 비밀번호가 틀렸거나 손상된 출력(header=%s)\n"
        % head.hex()
    )
    return 4


if __name__ == "__main__":
    sys.exit(main())
