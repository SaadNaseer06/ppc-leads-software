# Connecting websites, live chat, and Tawk.to

Every website gets its own **website key** (Websites → open the site). Leads can arrive several ways; you
can use more than one on the same site. `YOUR-APP` below is the app's public address (`APP_URL` in `.env`);
the website's page in the dashboard shows the exact snippets with it filled in.

## 1. Embed script (recommended)

Paste the snippet from the website's page before `</body>` on every page:

```html
<script src="https://YOUR-APP/ppc-leads.js" data-site="pk_xxxxxxxx" async></script>
```

- Captures every `<form>` submission automatically (Contact Form 7, Elementor, WPForms, Gravity Forms,
  custom HTML, AJAX forms), without changing how the form itself works.
- Stores the ad click and campaign when the visitor lands (`gclid`, `gbraid`, `wbraid`, `msclkid`,
  `fbclid`, `utm_*`, landing page, external referrer) for 90 days, so a lead that converts on a later page
  or a later visit is still attributed to the ad. A newer ad click replaces the older one.
- Skips login/sign-up forms (anything with a password field), search forms, password/card fields and
  CAPTCHA tokens. Add `data-ppc-ignore` to any other form you don't want captured.
- Options: `data-forms="form.quote"` (only matching forms), `data-debug` (log to the browser console).
- For custom flows (multi-step widgets, SPAs): `window.PPCLeads.submit({ name, email, phone, message }, "Form name")`.

## 2. Webhook / API

`POST https://YOUR-APP/api/intake/pk_xxxxxxxx`

Accepts JSON, `application/x-www-form-urlencoded` or `multipart/form-data`, so it works as the webhook
action of Elementor Pro, WPForms, Gravity Forms, Zapier, Make, Unbounce or your own backend.

Field names are matched loosely (case and punctuation are ignored):

| Lead field | Accepted names (examples) |
|---|---|
| Name | `name`, `full_name`, `your-name`, or `first_name` + `last_name` |
| Email | `email`, `your-email`, `email_address` |
| Phone | `phone`, `tel`, `mobile`, `phone_number`, `whatsapp` |
| Company | `company`, `business_name`, `organization` |
| Message | `message`, `comments`, `your-message`, `enquiry`, `details` |
| Attribution | `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `gclid`, `msclkid`, `landing_page`, `page_url`, `referrer` |

Any other fields are kept and shown on the lead under "Enquiry". A submission needs at least an email,
phone or message. Responses: `201` new lead, `200` repeat submission merged into an existing lead,
`404` unknown key, `403` website paused, `422` empty submission, `429` rate limited (20 per IP per 10 min).

Plain HTML forms can post straight to the URL; add `<input type="hidden" name="_redirect" value="https://site/thank-you">`
to send the visitor to a thank-you page. A filled honeypot field (`_gotcha`, `honeypot`) silently drops spam.

### Duplicates

If the same email or phone (last 10 digits) submits again to the same brand within 30 days, no new lead is
created. The existing lead's submission count goes up, the new message appears on its timeline, and a
"Lost" lead is reopened as "New".

## 3. Native live chat (recommended)

Replaces Tawk.to for new traffic. Visitors chat in a first-party widget; agents reply only from this app's
**Chats** page. Realtime uses [Pusher](https://pusher.com) (cloud) so shared cPanel hosting does not need Redis
or a WebSocket server.

### Server `.env`

```ini
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=mt1
VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
```

Create a free Channels app at pusher.com, paste the keys, then rebuild the dashboard assets if you change
`VITE_*` (`npm run build`) so the Chats page can subscribe. The visitor widget reads key/cluster from the
session API response, so it does not need a Vite rebuild.

### Website snippet

On the website's page, copy the **Live chat widget** snippet:

```html
<script src="https://YOUR-APP/ppc-chat.js" data-site="pk_xxxxxxxx" async></script>
```

Optional attributes: `data-title`, `data-color`, `data-greeting`, `data-endpoint` (override app URL).

What happens:

- Opening the bubble creates/resumes a conversation (`provider=native`).
- The first visitor message creates a lead (source "Live chat") via the same intake path as forms/Tawk.
- Messages broadcast on private channel `chat.{id}`; if Pusher is missing or fails, the widget polls every 3s.
- Agents reply and end chats from **Chats**. Old Tawk rows stay visible as read-only history.

Toggle **Enable live chat widget** in website settings to reject new sessions without removing the snippet.

## 4. Tawk.to (legacy)

Keep only if you still need historical Tawk chats or offline tickets. New sites should use the native widget.

1. On the website's page in this app, open **Legacy · Tawk.to** and copy the webhook URL
   (`https://YOUR-APP/api/webhooks/tawk`).
2. In Tawk.to: **Administration → Settings → Webhooks → Create webhook**. Paste the URL and tick
   **Chat Start**, **Chat End**, **Chat Transcript Created** and **New Ticket**.
3. Copy the webhook's **secret key** and the **property ID** into the website's settings here.

What happens:

- **Chat Start** creates the conversation, and a lead (source "Live chat") as soon as the visitor has sent a message.
- **Chat End / Transcript** stores the full transcript and copies contact details onto the lead when shared.
- **New Ticket** creates a lead with source "Offline message".
- Replies stay in Tawk.to; this app shows the thread as read-only.

## Checking the setup

- **Send test lead** on the website's page posts through the real public endpoint.
- Open the chat bubble on the client site, send a message, and confirm it appears under **Chats** (and as a lead).
- Reply from **Chats** and confirm the visitor widget shows the answer (Pusher or poll).
- The website's page shows when the last form lead and chat arrived, plus legacy Tawk delivery status if linked.
