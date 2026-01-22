# Form CSV Options

Import options from CSV files for Select, Radio, and Checkbox fields in Elementor Pro Forms. Perfect for long option lists like countries, states, or product categories.

## Features

- Upload CSV files directly in Elementor editor
- Load options from external CSV URLs
- Supports Label, Value, and Selected columns
- Automatic header row detection
- Works with Select, Radio, and Checkbox fields

## Usage

1. Add a Form widget in Elementor
2. Add a Select, Radio, or Checkbox field
3. In field settings, find "Data Type" dropdown
4. Choose "CSV File"
5. Upload a CSV or enter URL
6. Click "Load Options from CSV"

## CSV Format

### Basic (Label only)
```csv
United States
Canada
Mexico
```

### With Values
```csv
United States,US
Canada,CA
Mexico,MX
```

### With Pre-selected
```csv
United States,US,false
Canada,CA,true
Mexico,MX,false
```

### With Header Row
```csv
Label,Value,Selected
United States,US,false
Canada,CA,true
Mexico,MX,false
```

## Column Reference

| Column | Required | Description |
|--------|----------|-------------|
| Label | Yes | Display text shown to users |
| Value | No | Submitted value (defaults to label) |
| Selected | No | Pre-select option: `true`, `yes`, `1`, or `selected` |

## Upload Methods

### File Upload

1. Select "Upload File" in File Type
2. Click "Choose CSV File"
3. Select from Media Library or upload new
4. Click "Load Options from CSV"

### External URL

1. Select "File URL" in File Type
2. Enter the full CSV URL
3. Click "Load Options from CSV"

Note: External URLs must be accessible (CORS-friendly or same domain).

## Tips

### Large Option Lists

For very long lists (100+ options), consider:
- Using Select field (vs Radio/Checkbox)
- Adding search functionality via custom CSS/JS
- Splitting into multiple fields

### Dynamic URLs

You can use URLs that generate CSV dynamically:
```
https://yoursite.com/api/get-options.php?type=countries
```

### Updating Options

To update options:
1. Upload new CSV file
2. Click "Load Options from CSV" again
3. Save the page

Options are stored in Elementor, so the CSV is only needed when updating.

## Supported Characters

- UTF-8 encoding supported
- Handles BOM (Byte Order Mark)
- Commas in values: wrap in quotes `"Value, with comma",value`
- Quotes in values: escape with double quotes `"Value ""quoted""",value`

## Troubleshooting

### "Could not parse CSV"
- Check file encoding (should be UTF-8)
- Verify CSV format is correct
- Remove any trailing empty rows

### Options Not Loading
- Ensure CSV URL is accessible
- Check browser console for errors
- Try uploading file instead of URL

### Wrong Values Submitted
- Verify Value column is correct in CSV
- Re-load options and save page
- Clear any caching plugins
