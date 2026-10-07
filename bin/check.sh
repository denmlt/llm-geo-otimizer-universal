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
# Needs curl and xargs. Links are fetched JOBS at a time (default 4): a translated document that is not
# cached yet costs the server a page render, so one-by-one took 15–20 minutes on a 260-page site.
# 🔴 Keep JOBS at most HALF the site's PHP workers (pm.max_children): an uncached translated .md holds a
# worker while it requests its own page from the same server. With 8 jobs on a 2-worker local site every
# render timed out and the documents fell back to the untranslated text (Thermador, 2026-10-07).
# A local copy: JOBS=1.
# Exit status is the number of failures (capped at 255).
set -uo pipefail

SITE="${1:?usage: check.sh https://example.com}"
SITE="${SITE%/}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
fails=0
: > "$TMP/langs"
JOBS="${JOBS:-4}"
fail() { echo "   FAIL $*"; fails=$((fails + 1)); }

# One link: prints "   FAIL …" lines only. Runs in a child shell (xargs), so it reports, never counts.
check_link() {
  local url="$1" home="$2" doc code cited
  doc="$(mktemp)"; trap 'rm -f "$doc"' RETURN
  code="$(curl -s -o "$doc" -w '%{http_code}' "$url")"
  [ "$code" = 200 ] || { echo "   FAIL $code $url"; return; }
  case "$url" in
    *.md|*format=md)
      cited="$(sed -n 's/^url: "\(.*\)"$/\1/p' "$doc" | head -1)"
      [ -n "$cited" ] || { echo "   FAIL no url in front matter: $url"; return; }
      case "$cited" in "$home"*) ;; *) echo "   FAIL front matter url outside $home: $cited ($url)";; esac
      code="$(curl -s -o /dev/null -w '%{http_code}' "$cited")"
      [ "$code" = 200 ] || echo "   FAIL front matter url answers $code: $cited ($url)"
      ;;
  esac
}
export -f check_link

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

  xargs -P "$JOBS" -I{} bash -c 'check_link "$1" "$2"' _ {} "$home" < "$TMP/links" > "$TMP/out"
  cat "$TMP/out"
  fails=$((fails + $(grep -c '^   FAIL' "$TMP/out")))

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
