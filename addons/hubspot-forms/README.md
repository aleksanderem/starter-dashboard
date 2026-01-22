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

## Troubleshooting

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
