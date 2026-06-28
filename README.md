# AI Decision Tree

> A WordPress plugin that displays a smart, interactive decision-tree popup on your posts — powered by Google Gemini, OpenAI, or Anthropic Claude — to qualify readers and present a personalized Call to Action.

---

## Table of Contents

* [Overview](#overview)
* [Features](#features)
* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)

  * [General Settings](#general-settings)
  * [Trigger Settings](#trigger-settings)
  * [CTA Settings](#cta-settings)
  * [AI Settings](#ai-settings)
* [How It Works](#how-it-works)
* [File Structure](#file-structure)
* [Database](#database)
* [Security](#security)
* [Known Limitations](#known-limitations)
* [License](#license)

---

## Overview

AI Decision Tree adds a lightweight popup to your WordPress posts that walks visitors through a short series of yes/no questions. Based on their answers, the plugin presents a tailored Call to Action — a contact button, WhatsApp link, or phone number.

Questions are generated automatically by an AI model using the post's title and excerpt, so every article gets a popup that is relevant to its specific topic. A static fallback tree is always available if AI generation is disabled or has not run yet.

---

## Features

* **AI-generated question trees** — Gemini, OpenAI GPT, and Anthropic Claude are all supported.
* **Static fallback tree** — covers SEO, Google Ads, and lead generation topics out of the box.
* **Three trigger modes** — scroll depth percentage, time delay, and exit intent (desktop only).
* **Per-post toggle** — enable or disable the popup on any individual post from the editor sidebar.
* **Global on/off switch** — disable the popup across the entire site in one click.
* **Flexible CTA link types** — direct URL, WhatsApp chat link, or phone call.
* **Visitor journey logging** — every completed path is stored in a custom database table.
* **Lead scoring** — each answer carries a score; the total is recorded with the journey.
* **Danger zone utilities** — reset all AI trees, wipe visitor localStorage state, or reset per-post toggles directly from the settings page.
* **API connection tester** — verify your API key and model before saving.
* **Fully RTL** — the popup UI is built for Arabic and right-to-left layouts.

---

## Requirements

* WordPress 5.8 or later
* PHP 7.4 or later
* An API key from at least one of: [Google AI Studio](https://aistudio.google.com/), [OpenAI](https://platform.openai.com/), or [Anthropic](https://console.anthropic.com/)
* The site must be able to make outbound HTTPS requests (standard hosting)

---

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:

   ```bash
   cd wp-content/plugins
   git clone https://github.com/your-username/ai-decision-tree.git
   ```

2. Log in to the WordPress admin and go to **Plugins → Installed Plugins**.

3. Find **AI Decision Tree** and click **Activate**.

4. Navigate to **Settings → AI Decision Tree** to configure the plugin.

> The plugin creates a `{prefix}adt_logs` database table automatically on first activation.

---

## Configuration

All settings live under **Settings → AI Decision Tree** in the WordPress admin.

### General Settings

| Setting       | Description                                                   |
| ------------- | ------------------------------------------------------------- |
| Plugin status | Master switch — disables the popup site-wide when turned off. |

### Trigger Settings

| Setting          | Default | Description                                                                         |
| ---------------- | ------- | ----------------------------------------------------------------------------------- |
| Scroll threshold | 40%     | Show popup after the visitor scrolls this far down the post. Set to `0` to disable. |
| Timer delay      | 0 s     | Show popup after this many seconds on the page. Set to `0` to disable.              |
| Exit intent      | Off     | Show popup when the mouse moves toward the browser address bar (desktop only).      |

All three triggers can be active at the same time. The popup shows on whichever fires first.

### CTA Settings

**Link type** — choose one:

* `URL` — any web address.
* `WhatsApp` — enter the international phone number without `+` (e.g. `201012345678`). The plugin builds the `wa.me/` link automatically.
* `Phone` — enter the number with country code (e.g. `+201012345678`).

**Link target** — open in a new tab (`_blank`) or the current tab (`_self`).

**Fallback CTA content** — title, body text, and button label shown when the AI CTA is unavailable. Defaults are provided in Arabic; replace them with your own copy.

### AI Settings

| Setting              | Description                                                                                                                                                   |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Enable AI generation | When off, the plugin uses the static decision tree for all posts.                                                                                             |
| Provider             | `Google Gemini`, `OpenAI`, or `Anthropic Claude`.                                                                                                             |
| Model                | Select from the available models for the chosen provider.                                                                                                     |
| API key              | Stored server-side only; never exposed to the browser. Use **Test connection** to verify before saving.                                                       |
| Question count       | Number of yes/no questions to generate per post (2–8). Default: 4.                                                                                            |
| Language / dialect   | Formal Arabic, Gulf dialect, Egyptian dialect, or a custom persona you write yourself.                                                                        |
| Prompt template      | The full prompt sent to the AI. Supports four variables: `{title}`, `{summary}`, `{question_count}`, `{persona}`. A **Reset to default** button is available. |

---

## How It Works

```
Visitor opens post
        │
        ▼
WordPress fires wp_enqueue_scripts
  └── adt_enqueue_assets() loads popup.css + popup.js
      └── wp_localize_script() injects settings into adtData

WordPress fires wp_footer
  └── adt_render_popup() outputs the popup HTML shell

Trigger fires in the browser (scroll / timer / exit intent)
  └── showPopup() → renderNode("start")
        └── fetchNode() → POST to admin-ajax.php
              └── adt_get_node() returns question + answers
                    ├── Uses AI tree from post_meta if available
                    └── Falls back to static tree; schedules AI generation

Visitor answers all questions
  └── saveJourney() → POST to admin-ajax.php
        └── adt_save_journey()
              ├── Inserts each step into adt_logs table
              └── adt_get_cta() picks the best CTA
                    ├── AI-generated CTA (post_meta)
                    ├── Fallback CTA (settings page)
                    └── Category preset (SEO / Ads / Lead / Default)

Browser renders the CTA card
  └── Button click opens ctaLink in ctaTarget window
```

**AI tree generation** runs as a background WordPress cron job. It is triggered once per post — either when the post is published, or the first time a visitor opens a post that has no tree yet. A five-minute transient lock prevents duplicate generation on high-traffic posts.

---

## File Structure

```
ai-decision-tree/
├── plugin.php                  # Entry point — loads files, registers hooks, AJAX handlers
├── includes/
│   ├── decision-tree.php       # Hardcoded static fallback question tree
│   ├── ai-generator.php        # Cron callback — calls AI API, saves tree to post_meta
│   ├── popup-toggle.php        # Per-post meta box (enable / disable popup per article)
│   └── settings.php            # Settings page, save handler, AJAX: test API, danger zone
├── templates/
│   └── popup.php               # HTML markup for the popup overlay
└── assets/
    ├── css/
    │   ├── popup.css           # Front-end popup styles (RTL, Tajawal font)
    │   └── settings.css        # Admin settings page styles
    └── js/
        ├── popup.js            # Front-end state machine — triggers, node rendering, journey
        └── settings.js         # Admin JS — provider/model sync, API test, danger zone
```

---

## Database

The plugin creates one table on activation:

**`{prefix}adt_logs`**

| Column        | Type              | Description                               |
| ------------- | ----------------- | ----------------------------------------- |
| `id`          | `BIGINT UNSIGNED` | Auto-increment primary key                |
| `post_id`     | `BIGINT UNSIGNED` | The post where the popup ran              |
| `question_id` | `VARCHAR(50)`     | Node key (e.g. `start`, `seo_1`, `ai_q2`) |
| `answer`      | `TEXT`            | Text of the selected answer               |
| `created_at`  | `DATETIME`        | Timestamp of the submission               |

Each row is one question-answer step. A single visitor journey that covered four questions produces four rows, all sharing the same `post_id` and a close `created_at` timestamp.

**Browser storage** — the plugin also writes to `localStorage` under the key `adt_popup_state` to remember whether a visitor has already seen or closed the popup on a given post. Use **Danger Zone → Reset visitor state** to bump the storage version and force the popup to reappear for all previous visitors.

---

## Security

The plugin follows WordPress security best practices:

* All AJAX endpoints verify a nonce with `check_ajax_referer()` before processing any data.
* Admin-only actions (API test, danger zone, settings save) check `current_user_can('manage_options')`.
* Database writes use `$wpdb->insert()` with typed placeholders — no raw SQL.
* The AI API key is kept server-side and is never included in the localized JavaScript object.
* All user-supplied values are sanitized with the appropriate WordPress function (`sanitize_text_field`, `absint`, `esc_url_raw`, etc.) before being stored.

**Known risks to be aware of:**

* The `adt_save_journey` endpoint does not cap the number of journey steps in the POST body. A malicious request with a very large array could trigger many database inserts in one request. Consider adding a `count($journey) > 20` guard.
* If AI generation is enabled and a post has no tree yet, a guest visitor's request will schedule a background AI API call. Iterating through many post IDs could exhaust API quota. The transient lock (5 minutes per post) mitigates but does not fully prevent this.
* Questions and CTA content returned from the AI are inserted into the DOM via `innerHTML`. If the AI response were compromised, it could deliver an XSS payload. Consider sanitizing API responses before rendering, or replacing `innerHTML` with `textContent` for plain-text fields.

---

## Known Limitations

* Exit intent detection works on desktop browsers only (requires mouse movement tracking).
* The popup is shown on single post pages (`is_single()`) only. Pages, archives, and custom post types are not supported unless the code is modified.
* The static fallback tree is written in Arabic. If your site is in another language, replace the questions in `includes/decision-tree.php` and the preset CTAs in `plugin.php → adt_get_cta()`.
* The `ai-generator.php` file currently uses the Gemini API directly regardless of which provider is selected in settings. Full multi-provider support in the generator is not yet implemented.

---

## License

This plugin is released under the [MIT License](LICENSE).

---

*Built by Abdelrhman Essam*
