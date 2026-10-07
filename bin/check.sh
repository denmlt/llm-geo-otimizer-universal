#!/usr/bin/env bash
# check.sh <site-url> — read the site the way an assistant does, in every language it publishes.
#
#   - llms.txt answers 200 and has a title and a summary line
#   - every link in it answers 200
#   - every markdown document has front matter whose `url` answers 200 DIRECTLY (no redirect)
#     and, on a translated llms.txt, stays in that language
#   - llms-full.txt answers 200
#   - the languages listed under "## Other languages" are checked the same way
#
# Needs curl only. Exit status is the number of failures (capped at 255).
set -uo pipefail

SITE="${1:?usage: check.sh https://example.com}"
SITE="${SITE%/}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
fails=0
: > "$TMP/langs"
fail() { echo "   FAIL $*"; fails=$((fails + 1)); }

origin="$(printf '%s' "$SITE" | sed -E 's#^(https?://[^/]+).*#\1#')"

check_index() {
  local index="$1" home="${1%llms.txt}"
  echo "── $index"

  local code; code="$(curl -s -o "$TMP/llms.txt" -w '%{http_code}' "$index")"
  [ "$code" = 200 ] || { fail "$code $index"; return; }
  grep -q '^# ' "$TMP/llms.txt" || fail "no '# title' line"
  grep -q '^> ' "$TMP/llms.txt" || fail "no '> summary' line"

  grep -oE '\]\([^) ]+\)' "$TMP/llms.txt" | sed -E 's/^\]\(//; s/\)$//' | sed "s#^/#$origin/#" | sort -u > "$TMP/links"
  echo "   $(grep -c '^- \[' "$TMP/llms.txt") entries, $(wc -l < "$TMP/links") distinct links"

  while read -r url; do
    code="$(curl -s -o "$TMP/doc" -w '%{http_code}' "$url")"
    [ "$code" = 200 ] || { fail "$code $url"; continue; }
    case "$url" in
      *.md|*format=md)
        local cited; cited="$(sed -n 's/^url: "\(.*\)"$/\1/p' "$TMP/doc" | head -1)"
        [ -n "$cited" ] || { fail "no url in front matter: $url"; continue; }
        case "$cited" in "$home"*) ;; *) fail "front matter url outside $home: $cited ($url)";; esac
        code="$(curl -s -o /dev/null -w '%{http_code}' "$cited")"
        [ "$code" = 200 ] || fail "front matter url answers $code: $cited ($url)"
        ;;
    esac
  done < "$TMP/links"

  code="$(curl -s -o /dev/null -w '%{http_code}' "${home}llms-full.txt")"
  [ "$code" = 200 ] || fail "$code ${home}llms-full.txt"

  # Other languages, once each, from the default-language file only.
  if [ "$index" = "$SITE/llms.txt" ]; then
    sed -n '/^## Other languages/,/^$/p' "$TMP/llms.txt" | grep -oE '\]\([^)]+llms\.txt\)' | sed -E 's/^\]\(//; s/\)$//' > "$TMP/langs"
  fi
}

check_index "$SITE/llms.txt"
while read -r other; do check_index "$other"; done < "$TMP/langs"

echo
[ "$fails" = 0 ] && echo "RESULT: PASS" || echo "RESULT: $fails failures"
exit $(( fails > 255 ? 255 : fails ))
