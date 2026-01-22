# Phone Field (International)

Adds international phone input functionality to Tel fields in Elementor Pro Forms. Includes country selector with flags, dial codes, and automatic formatting.

## Features

- Country selector dropdown with flags
- Automatic dial code insertion
- Phone number validation
- Geo-IP country detection
- Placeholder formatting per country
- Full international number on submit

## How It Works

The addon automatically enhances all Tel fields in Elementor Forms with:

1. **Country Dropdown**: Click to select from 200+ countries
2. **Flag Display**: Visual country indicator
3. **Dial Code**: Automatically inserted based on country
4. **Smart Formatting**: Placeholder shows local format

When the form is submitted, the full international number (with country code) is sent.

## Usage

1. Add a Form widget in Elementor
2. Add a Tel field
3. The international phone input is automatically applied

No additional configuration needed - it works out of the box.

## Preferred Countries

These countries appear at the top of the dropdown:
- United States
- United Kingdom
- Canada
- Australia
- Germany
- France
- Poland

## Geo-IP Detection

On page load, the addon detects the user's country via IP and pre-selects it. This improves UX by showing the most likely country first.

Detection service: ipapi.co (free tier, no API key needed)

## Submitted Data Format

Phone numbers are submitted in E.164 international format:

```
+1 555-123-4567      → +15551234567
+44 20 7946 0958     → +442079460958
+49 30 12345678      → +493012345678
```

## Styling

### Default Appearance

The addon includes styles that work with most themes:
- Clean dropdown with country flags
- Proper input padding for dial code
- Mobile-friendly touch targets

### Custom CSS

```css
/* Change dropdown width */
.iti__country-container {
    min-width: 300px;
}

/* Adjust dial code spacing */
.iti--separate-dial-code input[type=tel] {
    padding-left: 90px !important;
}

/* Style selected country area */
.iti__selected-country {
    background: #f5f5f5;
    border-radius: 4px 0 0 4px;
}
```

## Validation

The library validates phone numbers based on:
- Country-specific length requirements
- Valid number patterns
- Proper formatting

Invalid numbers are flagged before submission.

## Browser Support

- Chrome, Firefox, Safari, Edge (latest versions)
- Mobile browsers (iOS Safari, Chrome for Android)
- IE11 not supported

## Technical Details

### Library Used

[intl-tel-input](https://intl-tel-input.com/) v18.2.1

Loaded from jsDelivr CDN for optimal performance.

### Scripts Loaded

- `intlTelInput.min.js` - Core functionality
- `intlTelInput.min.css` - Dropdown styles
- `utils.js` - Validation and formatting (loaded async)

### Compatibility

- Works with Elementor popups
- Supports dynamically loaded forms
- Compatible with AJAX form submission
- Works in Elementor editor preview

## Troubleshooting

### Dropdown Not Appearing

- Check for JavaScript errors in console
- Ensure jQuery is loaded
- Verify no CSS is hiding the dropdown

### Wrong Country Pre-selected

- Geo-IP detection may be blocked
- User may be using VPN
- Falls back to US if detection fails

### Number Format Issues

- Ensure form is not modifying the value
- Check for conflicting validation plugins
- Verify number is valid for selected country
