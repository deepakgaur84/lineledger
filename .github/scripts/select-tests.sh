#!/usr/bin/env bash
# Fork CI helper: pick the Pest test files that matter for a push, so the tests
# workflow does not run the whole (very slow) suite on every commit.
#
#   select-tests.sh <base-sha>
#
# Writes mode=full|partial|none, files=<space separated test files> and js=true|false
# to $GITHUB_OUTPUT (or stdout when run by hand). Conservative: anything global
# (composer, phpunit.xml, routes, test bootstrap) or a huge selection falls back to
# a full run. The full suite still runs nightly and from "Run workflow".
set -euo pipefail

base="${1:-}"
if [ -z "$base" ] || [[ "$base" =~ ^0+$ ]] || ! git cat-file -e "${base}^{commit}" 2>/dev/null; then
  base="$(git rev-parse HEAD^ 2>/dev/null || true)"
fi

out="${GITHUB_OUTPUT:-/dev/stdout}"
emit() { printf '%s=%s\n' "$1" "$2" >> "$out"; }

if [ -z "$base" ]; then
  emit mode full; emit files ""; emit js true
  exit 0
fi

changed="$(git -c core.quotepath=off diff --name-only --diff-filter=ACMR "$base" HEAD || true)"

js=false
if printf '%s\n' "$changed" | grep -qE '^(resources/js/|package(-lock)?\.json|vite\.config|.*\.test\.(js|ts)$)'; then js=true; fi

# Anything that can change every test -> run everything.
if printf '%s\n' "$changed" | grep -qE '^(\.fork-patches/)?(composer\.(json|lock)|phpunit\.xml|tests/(Pest|TestCase)\.php|routes/|bootstrap/)'; then
  emit mode full; emit files ""; emit js true
  exit 0
fi

declare -A picked=()
tokens=()

while IFS= read -r f; do
  [ -z "$f" ] && continue
  path="${f#.fork-patches/}"
  case "$path" in
    tests/*Test.php)
      if [ -f "$path" ]; then picked["$path"]=1; fi
      ;;
    resources/views/pages/*⚡*.blade.php)
      alias="${path#resources/views/pages/}"
      alias="$(printf '%s' "$alias" | sed -E 's#/⚡#/#; s#^⚡##; s#\.blade\.php$##; s#/#.#g')"
      tokens+=("pages::${alias}")
      ;;
    resources/views/*.blade.php)
      view="${path#resources/views/}"
      view="$(printf '%s' "$view" | sed -E 's#\.blade\.php$##; s#/#.#g')"
      tokens+=("$view" "$(basename "$path" .blade.php)")
      ;;
    app/*.php|database/factories/*.php|database/seeders/*.php|config/*.php)
      tokens+=("$(basename "$path" .php)")
      ;;
  esac
done <<< "$changed"

for t in "${tokens[@]:-}"; do
  [ -z "$t" ] && continue
  while IFS= read -r hit; do
    [ -n "$hit" ] && picked["$hit"]=1
  done < <(grep -rlwF --include='*Test.php' -e "$t" tests 2>/dev/null || true)
done

# Area folders: a changed file in app/.../Recurring/ or pages/recurring-journal/ also
# pulls in tests/Feature/Recurring* (matched on the first 5 letters, so Reports ~
# Reporting), unless that folder is huge. Catches tests that go through routes or
# views and never name the changed class.
generic=" app services http controllers models actions concerns support livewire enums jobs console commands resources requests providers policies exceptions views pages components partials lists settings "
while IFS= read -r f; do
  path="${f#.fork-patches/}"
  case "$path" in app/*|resources/views/pages/*) ;; *) continue ;; esac
  dir="$(dirname "$path")"
  IFS='/' read -ra segs <<< "$dir"
  for seg in "${segs[@]}"; do
    low="$(printf '%s' "$seg" | tr '[:upper:]' '[:lower:]')"
    [[ "$generic" == *" $low "* ]] && continue
    [ "${#low}" -lt 5 ] && continue
    stem="${low:0:5}"
    for td in tests/Feature/*/ tests/Unit/*/; do
      [ -d "$td" ] || continue
      tlow="$(basename "$td" | tr '[:upper:]' '[:lower:]')"
      [ "${tlow:0:5}" = "$stem" ] || continue
      mapfile -t area < <(find "$td" -name '*Test.php')
      if [ "${#area[@]}" -le 60 ]; then
        for t in "${area[@]}"; do picked["$t"]=1; done
      fi
    done
  done
done <<< "$changed"

count="${#picked[@]}"
if [ "$count" -gt 400 ]; then
  emit mode full; emit files ""; emit js "$js"
  exit 0
fi
if [ "$count" -eq 0 ]; then
  emit mode none; emit files ""; emit js "$js"
  exit 0
fi

emit mode partial
emit files "$(printf '%s\n' "${!picked[@]}" | sort | tr '\n' ' ')"
emit js "$js"
