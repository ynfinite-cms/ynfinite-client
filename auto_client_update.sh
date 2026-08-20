#!/bin/bash

# -----------------------------------------------------------------------
# SCRIPT CONFIGURATION
# -----------------------------------------------------------------------

# Source (Point A: The source repository/folder)
# Automatically sets the source to the directory where this script is located
SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"

# DEFINE THE RELATIVE PATHS OF ALL ITEMS TO COPY
FOLDERS_TO_COPY=("config" "development/docker" "scripts" "src" "tmp" "public/assets/vendor/ynfinite")
FILES_TO_COPY=("build.mjs" "hot-reload-snippet.html" "postcss.config.js" "public/index.php" "vite.config.js" "README.md" ".gitignore" ".prettierrc" "composer.json" "composer.lock")

# -----------------------------------------------------------------------
# GUI FOLDER SELECTION LOGIC
# -----------------------------------------------------------------------

echo "Prompting for target folder..."
DESTINATION_ROOT=""
OS="$(uname -s)"

if [[ "$OS" == "Darwin" ]]; then
    # macOS: Use AppleScript to open a Finder dialog
    DESTINATION_ROOT=$(osascript \
        -e 'tell application "Finder" to activate' \
        -e 'tell application "Finder" to return POSIX path of (choose folder with prompt "Select the Target Project Folder:")' 2>/dev/null)

elif [[ "$OS" == MINGW* || "$OS" == CYGWIN* || "$OS" == MSYS* || "$(uname -r)" == *microsoft* ]]; then
    # Windows (Git Bash / WSL): Use PowerShell to open a Folder Browser Dialog
    WIN_PATH=$(powershell.exe -NoProfile -Command "
        Add-Type -AssemblyName System.windows.forms
        \$dialog = New-Object System.Windows.Forms.FolderBrowserDialog
        \$dialog.Description = 'Select the Target Project Folder'
        \$dialog.ShowNewFolderButton = \$true
        if (\$dialog.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) {
            \$dialog.SelectedPath
        }
    " | tr -d '\r')

    if [ -n "$WIN_PATH" ]; then
        # Convert Windows path to a Bash-compatible Unix path
        if command -v cygpath &> /dev/null; then
            DESTINATION_ROOT=$(cygpath -u "$WIN_PATH")
        elif command -v wslpath &> /dev/null; then
            DESTINATION_ROOT=$(wslpath -u "$WIN_PATH")
        else
            DESTINATION_ROOT="$WIN_PATH" # Fallback
        fi
    fi
else
    echo "❌ Error: Unsupported OS for GUI folder selection."
    echo "Please run this script on macOS or Windows (Git Bash/WSL)."
    exit 1
fi

# 1. Check if the user canceled the dialog
if [ -z "$DESTINATION_ROOT" ]; then
    echo "❌ Action canceled. No folder was selected."
    exit 1
fi

# -----------------------------------------------------------------------
# EXECUTION LOGIC (The actual copy operation)
# -----------------------------------------------------------------------

echo "--- Project Client Update Script ---"
echo "Source Directory: $SOURCE_ROOT"
echo "Target Directory: $DESTINATION_ROOT"

# 2. Double-check if the selected destination actually exists
if [ ! -d "$DESTINATION_ROOT" ]; then
    echo "❌ ERROR: Selected destination folder is invalid or not found!"
    exit 1
fi

# 3. Ensure the target is not the same as the source folder
if [ "$SOURCE_ROOT" == "$DESTINATION_ROOT" ]; then
    echo "❌ ERROR: The target folder cannot be the same as the source folder where this script is located!"
    exit 1
fi

# 4. Ensure the target folder contains a 'src' directory
if [ ! -d "$DESTINATION_ROOT/src" ]; then
    echo "❌ ERROR: Invalid target! The selected folder does not contain a 'src' directory."
    exit 1
fi

echo "--- Starting Copy into existing directory '$DESTINATION_ROOT' ---"

# Merge the file and folder lists
ALL_ITEMS_TO_COPY=("${FOLDERS_TO_COPY[@]}" "${FILES_TO_COPY[@]}")

for ITEM in "${ALL_ITEMS_TO_COPY[@]}"; do
    SOURCE_PATH="$SOURCE_ROOT/$ITEM"
    DESTINATION_DIR="$(dirname "$DESTINATION_ROOT/$ITEM")"
    DESTINATION_PATH="$DESTINATION_ROOT/$ITEM"

    if [ -e "$SOURCE_PATH" ]; then
        # Ensure the destination parent directory exists
        mkdir -p "$DESTINATION_DIR"

        if [ -d "$SOURCE_PATH" ]; then
            # Copy Directory - remove existing first to ensure clean copy
            rm -rf "$DESTINATION_PATH"
            cp -rv "$SOURCE_PATH" "$DESTINATION_DIR" 
            echo "✅ Copied folder: $ITEM"
        else
            # Copy File - force overwrite
            echo "📝 Copying file: $ITEM"
            echo "   From: $SOURCE_PATH"
            echo "   To:   $DESTINATION_PATH"
            rm -f "$DESTINATION_PATH"
            cp -vf "$SOURCE_PATH" "$DESTINATION_PATH"
            
            if [ -f "$DESTINATION_PATH" ]; then
                echo "✅ Successfully copied: $ITEM"
            else
                echo "❌ FAILED to copy: $ITEM"
            fi
        fi
        
    else
        echo "⚠️ Warning: Source item '$SOURCE_PATH' not found. Skipping."
    fi
done

echo ""
echo "--- Update Complete for target: $DESTINATION_ROOT! ---"