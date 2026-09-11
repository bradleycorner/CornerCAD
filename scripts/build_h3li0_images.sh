#!/bin/bash
# Rename + optimise h3li0 product photography.
# Reads  : "h3li0 Media/<pattern>_<type>_photos/"   (originals are never modified)
# Writes : "h3li0 Media (web)/<slug>/<slug>-NN.jpg" + manifest.json
# Aspect ratio is preserved — no destructive cropping.
set -euo pipefail
SRC="h3li0 Media"; OUT="h3li0 Media (web)"; MAX=1500; Q=82
ONLY="${1:-}"
mkdir -p "$OUT"; : > "$OUT/.manifest.tmp"
total=0; folders=0
for dir in "$SRC"/*/; do
  name=$(basename "$dir")
  if [ -n "$ONLY" ] && [ "$name" != "$ONLY" ]; then continue; fi
  slug=$(echo "$name" \
        | sed -E 's/_?photos?$//; s/[[:space:]]+//g; s/[_]+/-/g; s/-+$//; s/^-+//' \
        | tr 'A-Z' 'a-z')
  tmp=$(mktemp)
  find "$dir" -maxdepth 1 -type f \
       \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' -o -iname '*.webp' \) \
       | LC_ALL=C sort > "$tmp"
  count=$(wc -l < "$tmp" | tr -d ' ')
  if [ "$count" -eq 0 ]; then rm -f "$tmp"; continue; fi
  mkdir -p "$OUT/$slug"; i=0; list=""
  while IFS= read -r f; do
    i=$((i+1)); n=$(printf "%02d" "$i"); dest="$OUT/$slug/$slug-$n.jpg"
    if magick "$f" -auto-orient -resize "${MAX}x${MAX}>" -strip \
              -quality $Q -interlace Plane "$dest" 2>/dev/null; then
      list="$list\"$slug-$n.jpg\","
    else
      echo "  !! failed: $f" >&2; i=$((i-1))
    fi
  done < "$tmp"
  rm -f "$tmp"
  printf '{"slug":"%s","source_folder":"%s","image_count":%d,"images":[%s]},\n' \
         "$slug" "$name" "$i" "${list%,}" >> "$OUT/.manifest.tmp"
  folders=$((folders+1)); total=$((total+i))
  if [ -n "$ONLY" ]; then echo "  $slug: $i images"; fi
done
{ echo '{"products":['; sed '$ s/,$//' "$OUT/.manifest.tmp"; echo ']}'; } > "$OUT/manifest.json"
rm -f "$OUT/.manifest.tmp"
echo "folders: $folders   images: $total"
