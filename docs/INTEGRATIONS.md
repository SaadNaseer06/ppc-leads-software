# Connecting websites and Tawk.to

Every website gets its own **website key** (Websites → open the site). Leads can arrive three ways; you
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

## 3. Tawk.to

1. On the website's page in this app, copy the **Tawk.to webhook URL** (`https://YOUR-APP/api/webhooks/tawk`).
2. In Tawk.to: **Administration → Settings → Webhooks → Create webhook**. Paste the URL and tick
   **Chat Start**, **Chat End**, **Chat Transcript Created** and **New Ticket**.
3. Copy the webhook's **secret key** and the **property ID** (the first ID in the widget code:
   `embed.tawk.to/PROPERTY_ID/...`) into the website's settings here.

What happens:

- **Chat Start** creates the conversation, and a lead (source "Live chat") as soon as the visitor has sent a message.
  If the visitor's email matches a recent lead, the chat is attached to that lead instead.
- **Chat End / Transcript** stores the full transcript and copies the visitor's name and email onto the lead
  if they shared them during the chat.
- **New Ticket** (offline form when nobody is online) creates a lead with source "Offline message".
- Requests are verified with the HMAC-SHA1 signature Tawk.to sends; retries are de-duplicated by event ID.

If chats arrive from a property that isn't linked to any website (this needs `TAWK_WEBHOOK_SECRET` in `.env` so
they can be verified), they are kept and listed on the Websites page. Linking the property ID imports them as leads.

Replies are sent from Tawk.to; this app shows the conversation next to the lead.

## Checking the setup

- **Send test lead** on the website's page posts through the real public endpoint.
- The website's page shows when the last form lead and chat arrived, plus the latest Tawk.to deliveries
  with their status (`processed`, `rejected` with the reason, `failed`).
