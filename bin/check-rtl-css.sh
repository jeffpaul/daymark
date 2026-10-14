#!/usr/bin/env bash
#
# Checks that Daymark's stylesheets use logical CSS properties for RTL layout support.
#
# Verifies that assets/app.css, assets/featured-content.css, and
# assets/featured-content-editor.css do not declare physical left/right
# properties (left:, right:, margin-left:, margin-right:, padding-left:,
# padding-right:, border-left:, border-right:, text-align: left/right).
#
# Usage: bin/check-rtl-css.sh
#

set -euo pipefail

cd "$(dirname "$0")/.."

node -e '
const fs = require("fs");
const files = [
  "assets/app.css",
  "assets/featured-content.css",
  "assets/featured-content-editor.css"
];

let totalViolations = 0;

files.forEach(file => {
  if (!fs.existsSync(file)) {
    console.error(`::error::File not found: ${file}`);
    process.exit(1);
  }

  const content = fs.readFileSync(file, "utf8");
  const lines = content.split("\n");
  let inBlockComment = false;

  lines.forEach((line, idx) => {
    let clean = line;

    // Handle block comments across lines
    if (inBlockComment) {
      const endComment = clean.indexOf("*/");
      if (endComment !== -1) {
        clean = clean.slice(endComment + 2);
        inBlockComment = false;
      } else {
        return;
      }
    }

    while (clean.includes("/*")) {
      const start = clean.indexOf("/*");
      const end = clean.indexOf("*/", start + 2);
      if (end !== -1) {
        clean = clean.slice(0, start) + clean.slice(end + 2);
      } else {
        clean = clean.slice(0, start);
        inBlockComment = true;
        break;
      }
    }

    // Match CSS declarations
    const declRegex = /(?:^|[;{\s])([a-zA-Z-]+)\s*:\s*([^;{}]+)/g;
    let match;
    while ((match = declRegex.exec(clean)) !== null) {
      const prop = match[1].toLowerCase();
      const val = match[2].trim().toLowerCase();

      const isPhysicalProp = /^(left|right|margin-left|margin-right|padding-left|padding-right|border-left|border-right)$/.test(prop);
      const isPhysicalAlign = prop === "text-align" && /^(left|right)$/.test(val);

      if (isPhysicalProp || isPhysicalAlign) {
        console.error(`::error file=${file},line=${idx + 1}::Physical CSS property "${prop}: ${val}" found in ${file}:${idx + 1}. Use logical properties (e.g. margin-inline-start, inset-inline-start, text-align: start).`);
        totalViolations++;
      }
    }
  });
});

if (totalViolations > 0) {
  console.error(`Found ${totalViolations} physical CSS property declaration(s).`);
  process.exit(1);
} else {
  console.log("All app stylesheets use logical properties for RTL support.");
}
'

