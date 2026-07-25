#!/usr/bin/env bash
# Substitute media-ids.env values into a tokenized block file.
# Usage: content/blocks/render.sh content/blocks/home.html
set -euo pipefail
# Resolve the input path BEFORE cd, or a relative argument breaks.
src="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
cd "$(dirname "$0")"
[ -f media-ids.env ] || { echo "media-ids.env missing — run Task 3" >&2; exit 1; }
set -a; . ./media-ids.env; set +a
out="$(cat "$src")"
for k in HERO TILE_3DP TILE_LASER TILE_AUTO AUTO_BAND; do
  for s in ID URL; do
    v="${k}_${s}"
    [ -n "${!v:-}" ] || { echo "$v is empty in media-ids.env" >&2; exit 1; }
    out="${out//__${v}__/${!v}}"
  done
done
case "$out" in *__*_ID__*|*__*_URL__*) echo "unsubstituted tokens remain" >&2; exit 1;; esac
printf '%s' "$out"
