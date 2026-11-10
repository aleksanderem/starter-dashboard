# HubSpot Forms Integration

Connects Elementor Pro Forms to HubSpot, enabling form submissions to be sent directly to HubSpot forms with field mapping, submission logging, and a comprehensive dashboard.

## Features

- Submit Elementor Pro form data to HubSpot forms
- Visual field mapping interface
- HubSpot forms browser with search
- Submission log with status tracking
- Connection testing
- Support for all HubSpot field types

## Requirements

- Elementor Pro (for Forms widget)
- HubSpot account with Private App access

## Setup

### 1. Create HubSpot Private App

1. Go to HubSpot Settings → Integrations → Private Apps
2. Click "Create a private app"
3. Name it (e.g., "Elementor Forms Integration")
4. Under Scopes, enable:
   - `forms` (required)
   - `crm.objects.contacts.write` (optional, for contact creation)
5. Create the app and copy the access token

### 2. Configure in Dashboard

1. Go to Dashboard → Hub → Addons
2. Enable "HubSpot Forms" addon
3. Click the gear icon to open settings
4. Enter your Portal ID (found in HubSpot Settings → Account Setup)
5. Enter your Private App access token
6. Click "Test Connection" to verify

## Usage

### Adding HubSpot Action to Elementor Form

1. Edit a page with Elementor
2. Add or select a Form widget
3. Go to Actions After Submit
4. Add "HubSpot" action
5. Select a HubSpot form from the dropdown
6. Map Elementor fields to HubSpot fields

### Field Mapping

The integration supports automatic and manual field mapping:

**Automatic Mapping**: Fields with matching names are mapped automatically

**Manual Mapping**: Use the dropdown selectors to map each Elementor field to a HubSpot field

### Supported Field Types

- Text fields (single line, multi-line)
- Email
- Phone
- Number
- Select/Dropdown
- Radio buttons
- Checkboxes
- Date
- Hidden fields

## Dashboard Features

### HubSpot Forms Tab

Browse all forms in your HubSpot account:
- Search by form name
- View form GUID for reference
- See field count and creation date

### Elementor Mappings Tab

View all Elementor forms with HubSpot integration:
- See which HubSpot form is connected
- View field mapping configuration
- Quick link to edit form in Elementor

### Submission Log Tab

Track all form submissions:
- Timestamp and form name
- Success/failure status
- Submitted data preview
- HubSpot response details
- Clear log option

## Spam Protection

All checks run on the server before anything is sent to HubSpot, and only for Elementor forms that use the HubSpot action. Do not switch on CAPTCHA in HubSpot itself: the addon submits to HubSpot from the server and has no HubSpot CAPTCHA token.

| Setting | Default | What it does |
|---|---|---|
| Honeypot | on | Hidden "Credit Card" field; bots fill it in, the submission is rejected |
| Cloudflare Turnstile | off | Turnstile widget in the form, token checked with Cloudflare `siteverify`; no token / bad token / Cloudflare unreachable = rejected |
| Turnstile site key / secret key | empty | From Cloudflare. Without both keys Turnstile stays off (admin notice shown) |
| Limit submissions per IP | off | All forms: 10 per 600 s. One HubSpot form: 3 per 600 s. `0` switches a limit off |
| Behind Cloudflare / trusted proxy | off | Read the visitor IP from `CF-Connecting-IP` / `X-Forwarded-For` (rightmost entry). Only when all traffic goes through that proxy |
| Minimum fill time | off, 3 s | Signed time stamp in the form; faster, missing, forged or older than 6 h = rejected |

Rejected submissions appear in the Submission Log as "Rejected" with the reason, form, language and a hashed IP. Field values are never stored for them.

### Turnstile setup

1. Cloudflare dashboard → Turnstile → Add widget. Add every domain the forms run on (including language domains).
2. Widget mode: "Managed" (recommended) or "Invisible".
3. Copy the keys into Hub Dashboard → HubSpot Forms → Spam Protection:
   - Site Key: `YOUR_TURNSTILE_SITE_KEY`
   - Secret Key: `YOUR_TURNSTILE_SECRET_KEY`
4. Switch on "Cloudflare Turnstile", save, then clear the page cache.

Cloudflare test keys for staging: site key `1x00000000000000000000AA` (always passes), `2x00000000000000000000AB` (always fails); secret `1x0000000000000000000000000000000AA` (always passes), `2x0000000000000000000000000000000AA` (always fails).

## Form Changes & Missing Required Fields

HubSpot forms change without anyone touching WordPress (a marketer makes "Company" required in the Czech form). The addon keeps a copy of every HubSpot form it sends to — each source form and every Language Routing target — and compares it on a schedule.

| Setting | Default | What it does |
|---|---|---|
| Automatically add missing required fields | on | If the HubSpot form for the visitor's language requires fields the Elementor form does not send, they are added before the submit button with HubSpot's label, type (text, textarea, e-mail, phone, number, date, dropdown, radio as dropdown, checkbox, checkboxes) and options. File uploads are not added. A consent checkbox is added unticked and must be ticked by the visitor |
| Check HubSpot forms for changes | every hour | Also twice a day, daily or off. "Check now" runs it immediately |
| Placeholder for missing text fields | `-` | When HubSpot still answers `REQUIRED_FIELD` for a plain text field, the submission is sent once more with this value for just those fields and logged as "Repaired". Empty = never retry |
| E-mail notifications | on | Via `wp_mail`, at most one e-mail per form per day. A dashboard notice is always shown |
| Send to | empty | Empty = site administrator e-mail |

What counts as a change: a field became required or optional, a field was added or removed, a field type changed, dropdown options changed, consent settings changed. Label edits are ignored.

Rules that never change:
- Added fields use the real HubSpot field names (`bp_hs_extra[company]`); the server only accepts names that exist in the stored target form.
- Consent and legal checkboxes are never filled in for the visitor, also not on retry. A missing consent field produces a "consent missing" alert instead.
- Only text, textarea and phone fields get a placeholder; e-mail, dropdown, number, date and checkbox fields never do.
- At most one retry per submission.

The Mappings tab and the Elementor editor show for each language which required HubSpot fields are mapped and which are missing.

## Troubleshooting

### "Fields appeared in my form that I did not add"
The HubSpot form for that language requires them. Map them in Elementor (or make them optional in HubSpot) and they disappear after the next check. Switch off "Automatically add missing required fields" to stop this.

### New fields do not appear
The page is served from cache, or the form has not been checked yet. Click "Check now", then clear the page cache.

### "Please complete the security check"
- The Turnstile widget did not produce a token. Check that the site's domain is listed in the Turnstile widget settings and that a cache/optimization plugin does not delay or combine `challenges.cloudflare.com/turnstile/v0/api.js`.

### Everyone hits "Too many submissions"
- The site is behind Cloudflare or another proxy but "trusted proxy" is off, so all visitors share the proxy's IP. Switch the trusted proxy setting on (only if all traffic really goes through the proxy).

### "Connection Failed"
- Verify Portal ID is correct
- Check access token hasn't expired
- Ensure Private App has `forms` scope

### "Form Not Found"
- Refresh the forms list
- Check form is published in HubSpot
- Verify Portal ID matches form's account

### Submissions Not Appearing in HubSpot
- Check submission log for errors
- Verify field mapping is correct
- Ensure required HubSpot fields are mapped

## API Endpoints Used

- `GET /marketing/v3/forms` - List forms
- `GET /marketing/v3/forms/{formId}` - Get form details
- `POST /submissions/v3/integration/submit/{portalId}/{formGuid}` - Submit form
