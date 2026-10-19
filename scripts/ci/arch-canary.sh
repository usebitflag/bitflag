#!/usr/bin/env bash
# Proves that the architecture rules work. For each canary in tests/Arch/Canaries, the violating files are copied into
# the application, the checker named in the canary must fail for the stated reason, and the files are removed again.
# The job fails if any canary passes, because that rule would not catch a real violation.
#
# Canary format:
#   // expect: arch | phpstan      which checker must fail (Pest architecture tests or PHPStan)
#   // fails: <text>               text that must appear in the failure output
#   //// <path>                    starts a file; everything up to the next marker is written to <path>
set -uo pipefail

cd "$(dirname "$0")/../.."

PEST="${PEST:-vendor/bin/pest}"
PHPSTAN="${PHPSTAN:-vendor/bin/phpstan}"
created=()

cleanup() {
  for file in "${created[@]:-}"; do
    [ -n "$file" ] && rm -f "$file"
  done
  created=()
}
trap cleanup EXIT

run_checker() {
  case "$1" in
    arch) "$PEST" --group=arch 2>&1 ;;
    phpstan) "$PHPSTAN" analyse --memory-limit=1G --error-format=raw 2>&1 ;;
    *) echo "unknown checker: $1" >&2; return 2 ;;
  esac
}

echo "Baseline: both checkers must pass without a canary."
if ! run_checker arch >/dev/null || ! run_checker phpstan >/dev/null; then
  echo "The baseline already fails; fix it before checking the canaries." >&2
  exit 1
fi

failures=0

for canary in tests/Arch/Canaries/*.php.txt; do
  name="$(basename "$canary" .php.txt)"
  expect="$(sed -n 's#^// expect: ##p' "$canary" | head -1)"
  fails="$(sed -n 's#^// fails: ##p' "$canary" | head -1)"

  target=""
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      "//// "*)
        target="${line#//// }"
        mkdir -p "$(dirname "$target")"
        : > "$target"
        created+=("$target")
        ;;
      "// expect:"*|"// fails:"*) ;;
      *) [ -n "$target" ] && printf '%s\n' "$line" >> "$target" ;;
    esac
  done < "$canary"

  if [ "$expect" = "arch" ]; then composer dump-autoload --quiet >/dev/null 2>&1 || true; fi

  output="$(run_checker "$expect")"
  status=$?
  cleanup

  if [ "$status" -ne 0 ] && printf '%s' "$output" | grep -q -- "$fails"; then
    echo "ok    $name ($expect failed with \"$fails\")"
  else
    echo "FAIL  $name: the $expect check did not fail for \"$fails\" (exit $status)" >&2
    failures=$((failures + 1))
  fi
done

composer dump-autoload --quiet >/dev/null 2>&1 || true

if [ "$failures" -gt 0 ]; then
  echo "$failures canary check(s) passed when they should have failed." >&2
  exit 1
fi

echo "All canaries were caught."
