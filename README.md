# Telegram ID-Format Bot (MVP)

This Laravel app provides a Telegram bot workflow that converts uploaded National ID / Fayda PDF or image files into printable variants.

Important boundary:
- This tool is for document formatting/conversion only.
- It does not verify authenticity.
- It does not issue IDs.
- It does not intentionally alter identity data.

## Stack
- Laravel 12 (PHP 8.2)
- MySQL
- Local storage only (`storage/app/private`)
- Python CLI processor (no persistent Python service)

## Generated Outputs
- `normal.png`
- `mirror.png`
- `a4_color.pdf`
- `a4_gray.pdf`

## Environment Variables
Add these to `.env`:

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_API=https://api.telegram.org
TELEGRAM_WEBHOOK_SECRET=
TELEGRAM_MAX_FILE_SIZE=5242880
TELEGRAM_CLEANUP_AFTER_HOURS=72
TELEGRAM_PROCESS_TIMEOUT=120

PYTHON_BIN=python3
PYTHON_PROCESSOR_PATH=processor/main.py
```

## Setup
1. Install PHP dependencies:

```bash
composer install
```

2. Configure your `.env` for MySQL and Telegram values.

3. Run migrations:

```bash
php artisan migrate
```

4. Set up Python dependencies:

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r processor/requirements.txt
```

5. Run the app:

```bash
php artisan serve
```

## Telegram Webhook Endpoint
- Endpoint: `POST /telegram/webhook`
- Security: validates `X-Telegram-Bot-Api-Secret-Token` against `TELEGRAM_WEBHOOK_SECRET`

Set webhook example:

```bash
curl -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook" \
  -d "url=https://your-domain.com/telegram/webhook" \
  -d "secret_token=${TELEGRAM_WEBHOOK_SECRET}"
```

## Cleanup Command
Delete old processing directories:

```bash
php artisan telegram:cleanup
```

Override retention window:

```bash
php artisan telegram:cleanup --hours=72
```

cPanel cron example (every hour):

```bash
0 * * * * /usr/local/bin/php /home/username/public_html/artisan telegram:cleanup --hours=72 >> /home/username/telegram_cleanup.log 2>&1
```

## Operational Checklist
- `TELEGRAM_BOT_TOKEN` and `TELEGRAM_WEBHOOK_SECRET` are set.
- Webhook points to `https://your-domain.com/telegram/webhook`.
- MySQL credentials are set and migrations are applied.
- `storage/` is writable.
- Python binary and `processor/main.py` are accessible.

## Short Testing Checklist
1. Send `/start` and `/help` to the bot.
2. Upload one PDF file and verify all 4 outputs are returned.
3. Upload one JPG or PNG file and verify all 4 outputs are returned.
4. Verify DB rows are created in `telegram_users`, `processing_jobs`, and `generated_files`.
5. Send unsupported file type and verify a friendly error response.
6. Run `php artisan telegram:cleanup` and verify old job folders are removed.
