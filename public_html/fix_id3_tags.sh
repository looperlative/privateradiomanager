#!/bin/bash
# Copyright (c) 2025 Robert Amstadt
# 
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
# 
# The above copyright notice and this permission notice shall be included in all
# copies or substantial portions of the Software.
# 
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
# SOFTWARE.

# Fix MP3 files with corrupted ID3v2 tags by recreating clean ID3v2.3 tags
# Usage: ./fix_id3_tags.sh [directory] [--dry-run]
# Set RADIO_BASE_DIR environment variable or pass directory as first argument

DRY_RUN=false

# Check for dry-run flag and handle arguments
if [[ "$1" == "--dry-run" ]]; then
    DRY_RUN=true
    SEARCH_DIR="${2:-${RADIO_BASE_DIR}}"
elif [[ "$2" == "--dry-run" ]]; then
    DRY_RUN=true
    SEARCH_DIR="${1}"
else
    SEARCH_DIR="${1:-${RADIO_BASE_DIR}}"
fi

# Validate that SEARCH_DIR is set
if [ -z "$SEARCH_DIR" ]; then
    echo "ERROR: No directory specified."
    echo "Usage: $0 [directory] [--dry-run]"
    echo "Or set RADIO_BASE_DIR environment variable"
    exit 1
fi

if [ "$DRY_RUN" = true ]; then
    echo "DRY RUN MODE - No files will be modified"
    echo "=================================="
fi

FIXED_COUNT=0
ERROR_COUNT=0

# Use process substitution to avoid subshell issues with counters
while IFS= read -r -d '' file; do
    # Check for various ID3v2 tag corruption issues
    probe_output=$(ffprobe -v error -show_entries format_tags "$file" 2>&1)
    
    if echo "$probe_output" | grep -qE "(Incorrect BOM|Error reading frame|skipped)"; then
        echo "Found corrupted tags: $file"
        
        # Extract error details for logging
        if echo "$probe_output" | grep -q "Incorrect BOM"; then
            echo "  Issue: Incorrect BOM value in ID3v2 tags"
        fi
        if echo "$probe_output" | grep -q "Error reading frame"; then
            echo "  Issue: Corrupted ID3v2 frames detected"
        fi
        
        if [ "$DRY_RUN" = true ]; then
            echo "  Would fix: $file"
            continue
        fi
        
        # Get the good metadata from ID3v1/format tags (these are usually intact)
        title=$(ffprobe -v quiet -show_entries format_tags=title -of csv=p=0 "$file" 2>/dev/null)
        artist=$(ffprobe -v quiet -show_entries format_tags=artist -of csv=p=0 "$file" 2>/dev/null)
        album=$(ffprobe -v quiet -show_entries format_tags=album -of csv=p=0 "$file" 2>/dev/null)
        date=$(ffprobe -v quiet -show_entries format_tags=date -of csv=p=0 "$file" 2>/dev/null)
        track=$(ffprobe -v quiet -show_entries format_tags=track -of csv=p=0 "$file" 2>/dev/null)
        genre=$(ffprobe -v quiet -show_entries format_tags=genre -of csv=p=0 "$file" 2>/dev/null)
        
        # Create temp file with corrected tags using ffmpeg
        temp_file="${file}.tmp.mp3"
        
        # Build ffmpeg command arguments array to handle special characters
        ffmpeg_args=(-y -v quiet -i "$file" -map 0:a -c:a copy -map_metadata -1)
        
        [ -n "$title" ] && ffmpeg_args+=(-metadata "title=$title")
        [ -n "$artist" ] && ffmpeg_args+=(-metadata "artist=$artist")
        [ -n "$album" ] && ffmpeg_args+=(-metadata "album=$album")
        [ -n "$date" ] && ffmpeg_args+=(-metadata "date=$date")
        [ -n "$track" ] && ffmpeg_args+=(-metadata "track=$track")
        [ -n "$genre" ] && ffmpeg_args+=(-metadata "genre=$genre")
        
        ffmpeg_args+=(-id3v2_version 3 "$temp_file")
        
        # Execute the ffmpeg command
        ffmpeg "${ffmpeg_args[@]}"
        
        if [ -f "$temp_file" ] && [ -s "$temp_file" ]; then
            # Verify the fixed file has no errors
            if ! ffprobe -v error -show_entries format_tags "$temp_file" 2>&1 | grep -qE "(Incorrect BOM|Error reading frame)"; then
                mv "$temp_file" "$file"
                echo "  ✓ Fixed: $artist - $title"
                ((FIXED_COUNT++))
            else
                rm -f "$temp_file"
                echo "  ✗ ERROR: Fixed file still has issues: $file"
                ((ERROR_COUNT++))
            fi
        else
            rm -f "$temp_file"
            echo "  ✗ ERROR: Failed to create fixed file: $file"
            ((ERROR_COUNT++))
        fi
    fi
done < <(find "$SEARCH_DIR" -iname "*.mp3" -print0)

echo "=================================="
if [ "$DRY_RUN" = true ]; then
    echo "Dry run completed - no files were modified"
else
    echo "Fixed: $FIXED_COUNT files"
    [ $ERROR_COUNT -gt 0 ] && echo "Errors: $ERROR_COUNT files"
    echo "Done!"
fi
