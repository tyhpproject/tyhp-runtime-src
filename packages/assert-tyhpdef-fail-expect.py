#!/usr/bin/env python3
"""Assert `tyhp lint --format=json` output against a sidecar `.expect.json`.

Sidecar shape matches the conformance expectation subset used by
tests/Tyhp.Tests/TestHelpers/Conformance/ConformanceManifest.cs:

    {
      "errorCount": { "min": 1 },
      "codes": [4008],
      "warningCodes": [8021],
      "php": "8.4"
    }

`codes` are numeric diagnostic codes (TYHP4008 → 4008). Fail cases must
produce at least one error. `noDiagnostics` is rejected here (positives only).

Usage:
  assert-tyhpdef-fail-expect.py EXPECT.json LINT.json
  assert-tyhpdef-fail-expect.py --php-version EXPECT.json
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any


def die(message: str) -> None:
    print(f"error: {message}", file=sys.stderr)
    raise SystemExit(1)


def load_json_object(path: Path) -> Any:
    text = path.read_text(encoding="utf-8")
    start = text.find("{")
    end = text.rfind("}")
    if start < 0 or end < start:
        die(f"no JSON object in {path}")
    try:
        return json.loads(text[start : end + 1])
    except json.JSONDecodeError as exc:
        die(f"invalid JSON in {path}: {exc}")


def code_number(value: Any) -> int:
    if isinstance(value, int):
        return value
    text = str(value).strip().upper()
    if text.startswith("TYHP"):
        text = text[4:]
    try:
        return int(text)
    except ValueError:
        die(f"not a diagnostic code: {value!r}")


def count_bounds(spec: Any) -> tuple[int | None, int | None, int | None]:
    """Return (exact, min, max)."""
    if spec is None:
        return None, None, None
    if isinstance(spec, int):
        return spec, None, None
    if isinstance(spec, dict):
        exact = spec["exact"] if isinstance(spec.get("exact"), int) else None
        minimum = spec["min"] if isinstance(spec.get("min"), int) else None
        maximum = spec["max"] if isinstance(spec.get("max"), int) else None
        return exact, minimum, maximum
    die(f"invalid count spec: {spec!r}")


def severity_of(diag: dict[str, Any]) -> str:
    return str(diag.get("severity", "")).lower()


def collect_codes(diags: list[dict[str, Any]], severity: str) -> list[int]:
    return [code_number(d.get("code", 0)) for d in diags if severity_of(d) == severity]


def check_count(
    label: str,
    actual: int,
    exact: int | None,
    minimum: int | None,
    maximum: int | None,
    failures: list[str],
) -> None:
    if exact is not None and actual != exact:
        failures.append(f"{label} count {actual} != expected {exact}")
    if minimum is not None and actual < minimum:
        failures.append(f"{label} count {actual} < min {minimum}")
    if maximum is not None and actual > maximum:
        failures.append(f"{label} count {actual} > max {maximum}")


def print_php_version(expect_path: Path) -> None:
    expect = load_json_object(expect_path)
    php = expect.get("php")
    if php is None:
        return
    print(str(php).strip())


def assert_expect(expect_path: Path, lint_path: Path) -> None:
    expect = load_json_object(expect_path)
    lint = load_json_object(lint_path)

    if expect.get("noDiagnostics") is True:
        die(f"{expect_path.name}: noDiagnostics is for positive tests only")

    diagnostics = lint.get("diagnostics")
    if not isinstance(diagnostics, list):
        diagnostics = []

    errors = [d for d in diagnostics if isinstance(d, dict) and severity_of(d) == "error"]
    warnings = [d for d in diagnostics if isinstance(d, dict) and severity_of(d) == "warning"]
    error_codes = collect_codes(errors, "error")
    warning_codes = collect_codes(warnings, "warning")

    summary = lint.get("summary") if isinstance(lint.get("summary"), dict) else {}
    error_count = summary.get("errorCount")
    if not isinstance(error_count, int):
        error_count = len(errors)
    warning_count = summary.get("warningCount")
    if not isinstance(warning_count, int):
        warning_count = len(warnings)

    codes = expect.get("codes")
    warning_expect = expect.get("warningCodes")
    has_expectation = (
        expect.get("errorCount") is not None
        or expect.get("warningCount") is not None
        or (isinstance(codes, list) and len(codes) > 0)
        or (isinstance(warning_expect, list) and len(warning_expect) > 0)
    )
    if not has_expectation:
        die(
            f"{expect_path.name}: sidecar must specify errorCount, warningCount, "
            "codes, or warningCodes"
        )

    failures: list[str] = []

    if error_count < 1:
        failures.append("fail cases must produce at least one error (got 0)")

    exact, minimum, maximum = count_bounds(expect.get("errorCount"))
    check_count("error", error_count, exact, minimum, maximum, failures)

    exact_w, min_w, max_w = count_bounds(expect.get("warningCount"))
    check_count("warning", warning_count, exact_w, min_w, max_w, failures)

    if isinstance(codes, list) and codes:
        for code in codes:
            needed = code_number(code)
            if needed not in error_codes:
                failures.append(
                    f"missing error TYHP{needed} (got {format_codes(error_codes)})"
                )

    if isinstance(warning_expect, list) and warning_expect:
        for code in warning_expect:
            needed = code_number(code)
            if needed not in warning_codes:
                failures.append(
                    f"missing warning TYHP{needed} (got {format_codes(warning_codes)})"
                )

    if failures:
        detail = "; ".join(failures)
        print(
            f"FAIL {expect_path.name}: {detail}",
            file=sys.stderr,
        )
        print(
            f"  errors={format_codes(error_codes)} warnings={format_codes(warning_codes)}",
            file=sys.stderr,
        )
        raise SystemExit(1)

    print(
        f"PASS {expect_path.name}: {error_count} error(s) {format_codes(error_codes)}"
    )


def format_codes(codes: list[int]) -> str:
    if not codes:
        return "[]"
    return "[" + ", ".join(f"TYHP{c}" for c in codes) + "]"


def main(argv: list[str]) -> None:
    if len(argv) >= 1 and argv[0] in ("-h", "--help"):
        print(__doc__.strip())
        return
    if len(argv) == 2 and argv[0] == "--php-version":
        print_php_version(Path(argv[1]))
        return
    if len(argv) != 2:
        die("usage: assert-tyhpdef-fail-expect.py EXPECT.json LINT.json")
    assert_expect(Path(argv[0]), Path(argv[1]))


if __name__ == "__main__":
    main(sys.argv[1:])
