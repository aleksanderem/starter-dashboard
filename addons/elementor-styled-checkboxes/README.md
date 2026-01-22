# Styled Checkboxes

Adds comprehensive styling controls for Checkbox, Radio, and Acceptance fields in Elementor Pro Forms. Transform default browser inputs into beautiful, on-brand form elements.

## Features

- Custom checkbox and radio button styling
- Size, color, and border controls
- Hover and checked state styling
- Spacing and alignment options
- Works with Elementor's global colors
- No JavaScript required (pure CSS)

## Usage

1. Add a Form widget in Elementor
2. Add Checkbox, Radio, or Acceptance field
3. Go to Style tab → "Checkbox & Radio Style" section
4. Customize appearance

## Style Controls

### Size & Shape

| Control | Description |
|---------|-------------|
| Checkbox Size | Width and height of the input |
| Checkmark Size | Size of the check icon |
| Checkmark Thickness | Stroke width of the checkmark |
| Border Radius | Rounded corners (px or %) |
| Border Width | Thickness of the border |

### Spacing

| Control | Description |
|---------|-------------|
| Options Spacing | Gap between checkbox options |
| Label Gap | Space between input and label text |
| Margin | Outer spacing of the field group |
| Padding | Inner spacing of the field group |
| Option Item Margin | Margin around each option |
| Option Item Padding | Padding around each option label |
| Input Margin | Margin around the input element |

### Colors

Three-state color controls:

**Normal State**
- Background Color
- Border Color

**Hover State**
- Background Color
- Border Color
- Checkmark Color

**Active (Checked) State**
- Background Color
- Border Color
- Checkmark Color

## Default Styling

Out of the box, the addon provides modern checkbox styling:

- 22px square inputs
- 6px border radius
- 2px border
- Smooth transitions
- Pure CSS checkmark (no SVG, no images)
- Uses Elementor's primary color for checked state

## Radio Button Behavior

Radio buttons automatically get:
- Circular shape (50% border radius)
- Thick border when selected (instead of checkmark)
- Consistent sizing with checkboxes

## Global Colors Integration

The checked state uses Elementor's global primary color by default. You can override this in the style controls.

## Example Configurations

### Large Touch-Friendly

```
Checkbox Size: 28px
Border Radius: 8px
Label Gap: 16px
Options Spacing: 16px
```

### Minimal/Subtle

```
Checkbox Size: 16px
Border Width: 1px
Border Radius: 3px
Border Color: #e5e7eb
```

### Pill-Style

```
Checkbox Size: 24px
Border Radius: 50%
Background Color (checked): #10b981
```

## Acceptance Field

The Acceptance field (typically for Terms & Conditions) uses the same styling as Checkboxes, ensuring visual consistency.

## CSS Classes

For advanced customization:

```css
/* Checkbox field group */
.elementor-field-type-checkbox { }

/* Radio field group */
.elementor-field-type-radio { }

/* Acceptance field */
.elementor-field-type-acceptance { }

/* Option container */
.elementor-field-option { }

/* Option label */
.elementor-field-option label { }

/* The input itself */
.elementor-field-type-checkbox input[type="checkbox"] { }
.elementor-field-type-radio input[type="radio"] { }
```

## Browser Support

Pure CSS implementation works in:
- Chrome, Firefox, Safari, Edge
- iOS Safari, Chrome for Android
- IE11 (basic support, no custom checkmark)

## Tips

### Accessibility

- Maintain sufficient size (minimum 20px recommended)
- Ensure good color contrast
- Keep focus indicators visible (not disabled by default)

### Consistency

- Use same settings across all forms on your site
- Match checkbox colors to your button colors
- Keep spacing consistent with other form fields

### Performance

- No JavaScript overhead
- Minimal CSS (loaded only when addon active)
- Works with cached pages
