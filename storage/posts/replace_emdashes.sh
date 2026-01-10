#!/bin/bash

# ============================================================================
#       ____       __ 
#   ___/ / /__ _  / / 
#  (_-<_  _/  ' \/ _ \
# /___//_//_/_/_/_.__/
#                      s4mb.net
#
# Em Dash Replacement Script
# ============================================================================
# Drop this script into any folder containing .md files and run it.
# It replaces em dashes (—) with weighted random alternatives:
#   - 90%: " - " (space hyphen space)
#   -  5%: ";" (semicolon)  
#   -  5%: ". " + capitalize next word (creates new sentence)
#
# Usage:
#   ./replace_emdashes.sh              # Replace em dashes in current directory
#   ./replace_emdashes.sh --dry-run    # Preview changes without modifying files
#   ./replace_emdashes.sh --backup     # Create .bak files before modifying
#
# Requirements: Perl (pre-installed on macOS/Linux)
# ============================================================================

set -euo pipefail

# Use the directory where the script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRY_RUN=false
BACKUP=false

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --backup)
            BACKUP=true
            shift
            ;;
        -h|--help)
            echo "Em Dash Replacement Script"
            echo ""
            echo "Replaces em dashes (—) in .md files with:"
            echo "  - 90%: \" - \" (space hyphen space)"
            echo "  -  5%: \";\" (semicolon)"
            echo "  -  5%: \". \" + capitalize next word"
            echo ""
            echo "Usage: $0 [--dry-run] [--backup]"
            echo ""
            echo "Options:"
            echo "  --dry-run   Preview changes without modifying files"
            echo "  --backup    Create .bak backup files before modifying"
            echo "  -h, --help  Show this help message"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [--dry-run] [--backup]"
            exit 1
            ;;
    esac
done

# Find all markdown files in the script's directory (non-recursive)
files=()
while IFS= read -r file; do
    [[ -n "$file" ]] && files+=("$file")
done < <(find "$SCRIPT_DIR" -maxdepth 1 -name "*.md" -type f | sort)

if [[ ${#files[@]} -eq 0 ]]; then
    echo "No .md files found in $(basename "$SCRIPT_DIR")/"
    exit 0
fi

echo "Found ${#files[@]} markdown file(s) in $(basename "$SCRIPT_DIR")/"
if [[ "$DRY_RUN" == true ]]; then
    echo "DRY RUN MODE - No files will be modified"
fi
echo ""

processed=0
replaced=0

for file in "${files[@]}"; do
    # Count em dashes in file
    set +e
    em_dash_count=$(grep -o '—' "$file" 2>/dev/null | wc -l | tr -d ' ')
    set -euo pipefail
    
    if [[ -z "$em_dash_count" ]] || [[ "$em_dash_count" -eq 0 ]]; then
        echo "✓ $(basename "$file"): No em dashes found"
        continue
    fi
    
    echo "Processing: $(basename "$file") (found $em_dash_count em dash(es))"
    
    if [[ "$DRY_RUN" == true ]]; then
        # Dry run: show what would be replaced
        perl -pe '
            s/—/sub {
                my $rand = int(rand(100));
                if ($rand < 90) {
                    return " - ";
                } elsif ($rand < 95) {
                    return ";";
                } else {
                    return "___PERIOD___";
                }
            }->()/ge;
            s/___PERIOD___([\s]*)([a-z])/. $1\u$2/g;
            s/___PERIOD___/. /g;
        ' "$file" > /tmp/replace_preview.txt
        
        echo "Preview of changes:"
        diff -u "$file" /tmp/replace_preview.txt || true
        rm -f /tmp/replace_preview.txt
        echo ""
    else
        # Create backup if requested
        if [[ "$BACKUP" == true ]]; then
            cp "$file" "$file.bak"
        fi
        
        # Pass 1: Replace em dashes with weighted random choice
        perl -i -pe '
            s/—/sub {
                my $rand = int(rand(100));
                if ($rand < 90) {
                    return " - ";
                } elsif ($rand < 95) {
                    return ";";
                } else {
                    return "___PERIOD___";
                }
            }->()/ge;
        ' "$file"
        
        # Pass 2: Handle full stop + capitalization
        perl -i -pe '
            s/___PERIOD___([\s]*)([a-z])/. $1\u$2/g;
            s/___PERIOD___/. /g;
        ' "$file"
        
        # Count replacements made
        set +e
        new_em_dash_count=$(grep -o '—' "$file" 2>/dev/null | wc -l | tr -d ' ')
        set -euo pipefail
        [[ -z "$new_em_dash_count" ]] && new_em_dash_count=0
        replacements_made=$((em_dash_count - new_em_dash_count))
        replaced=$((replaced + replacements_made))
        
        if [[ "$BACKUP" == true ]]; then
            echo "  ✓ Replaced $replacements_made em dash(es) (backup created)"
        else
            echo "  ✓ Replaced $replacements_made em dash(es)"
        fi
        echo ""
    fi
    
    processed=$((processed + 1))
done

echo "========================================="
echo "Processed: $processed file(s)"
if [[ "$DRY_RUN" == false ]]; then
    echo "Total replacements: $replaced em dash(es)"
fi
