#!/usr/bin/env python3
"""
암호로 보호된 Office xlsx 복호화 (파일 열기 암호)
"""
from __future__ import annotations

import argparse
import sys


def read_password(args: argparse.Namespace) -> str:
    if args.password_file:
        with open(args.password_file, encoding="utf-8") as f:
            pw = f.read()
    else:
        pw = args.password or ""
    return pw.strip("\r\n\x00\t ")


def decrypt(inp: str, out: str, password: str, verbose: bool = False) -> int:
    try:
        import msoffcrypto
    except ImportError:
        sys.stderr.write("msoffcrypto-tool 미설치: pip install msoffcrypto-tool\n")
        return 2

    if password == "":
        sys.stderr.write("empty_password\n")
        return 1

    try:
        inp_size = 0
        with open(inp, "rb") as encrypted:
            inp_size = encrypted.seek(0, 2)
            encrypted.seek(0)
            head = encrypted.read(4)
            if verbose:
                sys.stderr.write(f"input_size={inp_size} input_head={head.hex()}\n")
                sys.stderr.write(f"msoffcrypto_version={getattr(msoffcrypto, '__version__', '?')}\n")

            office = msoffcrypto.OfficeFile(encrypted)
            if verbose:
                is_enc = office.is_encrypted()
                sys.stderr.write(f"is_encrypted={is_enc}\n")
                for attr in ("encryption_type", "encryption_version", "encryption_flags"):
                    if hasattr(office, attr):
                        sys.stderr.write(f"{attr}={getattr(office, attr)!r}\n")

            office.load_key(password=password)
            with open(out, "wb") as decrypted:
                office.decrypt(decrypted)
    except Exception as exc:  # noqa: BLE001
        sys.stderr.write(f"decrypt_error: {exc}\n")
        return 3

    try:
        with open(out, "rb") as check:
            head = check.read(4)
            out_size = check.seek(0, 2)
    except OSError as exc:
        sys.stderr.write(str(exc) + "\n")
        return 5

    if verbose:
        sys.stderr.write(f"output_size={out_size} output_head={head.hex()}\n")

    if head == b"PK\x03\x04":
        return 0
    if head == b"\xd0\xcf\x11\xe0":
        sys.stderr.write("decrypted_ole_xls\n")
        return 6
    if head == b"":
        sys.stderr.write("decrypted_empty\n")
        return 5

    sys.stderr.write(f"decrypted_invalid header={head.hex()}\n")
    return 4


def main() -> int:
    parser = argparse.ArgumentParser(description="Decrypt password-protected xlsx")
    parser.add_argument("input")
    parser.add_argument("output")
    parser.add_argument("password", nargs="?", default="")
    parser.add_argument("--password-file", dest="password_file", default="")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    password = read_password(args)
    if args.verbose:
        sys.stderr.write(f"password_len={len(password)}\n")

    return decrypt(args.input, args.output, password, verbose=args.verbose)


if __name__ == "__main__":
    sys.exit(main())
