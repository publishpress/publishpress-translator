# PublishPress Translations

AI-powered translation automation for PublishPress plugins using Potomatic and OpenAI.

## Features

- AI-powered translations using OpenAI GPT models
- Automatic detection of `.pot` files
- Merges with existing translations (preserves manual edits)
- Cost-effective (~$0.03 per language for 1,744 strings)
- Supports 10+ languages by default
- Dry-run mode for cost estimation

## Requirements

- PHP 7.2.5 or higher
- Node.js 18+ and npm (for Potomatic CLI tool)
- OpenAI API key ([Get one here](https://platform.openai.com/api-keys))
- Plugin must have a `languages/` directory with `.pot` files

## Installation

**Note:** This setup works the same whether you're working from the plugin root or inside dev-workspace.

### Step 1: Add to `lib/composer.json`

⚠️ **Current setup (until published on Packagist):**

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/publishpress/publishpress-translator.git"
        }
    ],
    "require": {
        "publishpress/translations": "dev-main"
    }
}
```

### Step 2: Add to root `composer.json`

```json
{
    "scripts": {
        "translate": "lib/vendor/bin/publishpress-translate",
        "translate:dry-run": "lib/vendor/bin/publishpress-translate --dry-run"
    }
}
```

### Step 3: Install

```bash
composer update
```

### Step 4: Setup Potomatic

⚠️ **Required for now (until published on Packagist):**

```bash
php lib/vendor/publishpress/translations/bin/setup-potomatic.php
```

---

**Once on Packagist:** Change Step 1 to:
```json
{
    "require": {
        "publishpress/translations": "^1.0"
    }
}
```
And Step 4 (manual Potomatic setup) won't be needed anymore!

## Usage

### Set OpenAI API Key

Before translating, set your OpenAI API key as an environment variable:

**Windows (PowerShell):**
```powershell
$env:OPENAI_API_KEY="your-api-key-here"
```

**Windows (CMD):**
```cmd
set OPENAI_API_KEY=your-api-key-here
```

**Mac/Linux:**
```bash
export OPENAI_API_KEY=your-api-key-here
```

Or create a `.env` file in your plugin root (don't commit this!):
```
OPENAI_API_KEY=your-api-key-here
```

### Translate Your Plugin

**From dev-workspace (PublishPress plugins with Docker):**
```bash
# Enter dev-workspace
./run

# Dry run (preview cost, no API calls)
composer translate:dry-run

# Actual translation
composer translate
```

**From plugin root (standard WordPress plugins):**
```bash
# Dry run
composer translate:dry-run

# Actual translation
composer translate
```

**Advanced options:**
```bash
# Custom languages only
vendor/bin/publishpress-translate --languages=de_DE,fr_FR,es_ES

# Force re-translate all strings (ignore existing translations)
vendor/bin/publishpress-translate --force
```

**Note:** The library automatically detects your environment (dev-workspace vs plugin root) and uses the correct vendor path.

### Default Languages

The tool translates into these languages by default:
- German (de_DE)
- Spanish (es_ES)
- French (fr_FR)
- Hebrew (he_IL)
- Italian (it_IT)
- Japanese (ja)
- Korean (ko_KR)
- Russian (ru_RU)
- Chinese Simplified (zh_CN)
- Chinese Traditional (zh_TW)

## How It Works

1. Scans your plugin's `languages/` directory for `.pot` files
2. For each `.pot` file, generates AI translations using OpenAI
3. Creates/updates `.po` files for each target language
4. Merges with existing translations (preserves manual edits)
5. Outputs translation files ready for WordPress to use

## One-Time Setup

Set your OpenAI API key permanently (recommended):

**Windows:**
```powershell
[System.Environment]::SetEnvironmentVariable('OPENAI_API_KEY', 'sk-proj-your-key', 'User')
```

**Mac/Linux (add to ~/.bashrc or ~/.zshrc):**
```bash
export OPENAI_API_KEY=sk-proj-your-key
```

## Troubleshooting

### "Potomatic not found" Error

This shouldn't happen if you installed via Composer. If it does, please report it as a bug.

### "OPENAI_API_KEY not set" Error

Make sure you've set the environment variable before running the translation command.

### "No .pot files found" Error

Ensure your plugin has a `languages/` directory with `.pot` translation template files. Generate these using tools like:
- [WP-CLI i18n make-pot](https://developer.wordpress.org/cli/commands/i18n/make-pot/)
- [Poedit](https://poedit.net/)
- [Loco Translate](https://wordpress.org/plugins/loco-translate/)

## Development

### Clone the Repository

```bash
git clone https://github.com/publishpress/translations.git
cd translations
composer install
```

### Testing Locally

To test the library before publishing:

1. In your plugin's `composer.json`, add a repository:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../publishpress-translations"
        }
    ],
    "require": {
        "publishpress/translations": "@dev"
    }
}
```

2. Run `composer install`

## License

GPL-3.0-or-later

## Credits

Built with [Potomatic](https://github.com/GravityKit/potomatic) by GravityKit.