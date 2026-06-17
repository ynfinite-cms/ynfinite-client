# Client Update Script

This script automates the process of a client update into a customer repo. It ensures that target projects are cleanly updated with the latest standardized files.

## 🛠 Prerequisites

Before running this script, ensure that:

1. You have a terminal environment available (such as Terminal on macOS, or Git Bash / WSL on Windows).

## 🚀 Setup Instructions

1. **Make it executable:** Grant execution permissions to the script so your system can run it. Open your terminal in the client folder and run (you need to do this only once):
    ```bash
    chmod +x auto_client_update.sh
    ```

## 💻 Usage

Simply execute the script from your terminal. The script will automatically detect your operating system and open a native graphical file explorer window for you to select the target project directory.

```bash
./auto_client_update.sh
```

- **On macOS:** A Finder dialog will appear prompting you to select the folder.
- **On Windows:** A Windows File Explorer dialog will appear prompting you to select the folder.

### Global Usage (Optional)

If you want to run this script from anywhere on your system without typing the `./` or navigating to its folder, move it to your system's binaries folder:

```bash
sudo mv auto_client_update.sh /usr/local/bin/client-update
```

Now, you can execute it from anywhere by simply typing:

```bash
client-update
```

## 📂 What Gets Copied?

The script dynamically copies specific items. Folders are cleanly replaced (existing folders are deleted before the new one is pasted), while files force-overwrite existing target files.

### Folders Copied:

- `config`
- `development/docker`
- `scripts`
- `src`
- `tmp`
- `public/assets/vendor/ynfinite`

### Files Copied:

- `build.mjs`
- `hot-reload-snippet.html`
- `postcss.config.js`
- `public/index.php`
- `vite.config.js`
- `README.md`
- `.gitignore`
- `.prettierrc`
- `composer.json`
- `composer.lock`
