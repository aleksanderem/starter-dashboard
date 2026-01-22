# Adaptive Gallery

Elementor widget that creates a photo carousel honoring original image dimensions. The gallery intelligently adjusts the number of visible slides based on image orientation (landscape, portrait, or square).

## Features

- **Orientation-aware**: Shows different number of slides based on current image orientation
- **Maintains aspect ratios**: Images keep their original proportions
- **Touch support**: Swipe navigation on mobile devices
- **Autoplay**: Optional automatic slideshow with configurable speed
- **Navigation**: Optional arrows and dot indicators
- **Responsive**: Adapts to different screen sizes

## Usage

1. Add the "Adaptive Gallery" widget to your Elementor page
2. Upload images to the gallery
3. Configure carousel settings:
   - Slides for landscape images (default: 2)
   - Slides for portrait images (default: 3)
   - Slides for square images (default: 2)
4. Customize appearance in the Style tab

## Settings

### Content Tab

**Gallery Images**
- Add images from media library
- Supports any image format

**Carousel Settings**
- **Slides for Landscape**: Number of slides when current image is landscape (ratio > 1.2)
- **Slides for Portrait**: Number of slides when current image is portrait (ratio < 0.8)
- **Slides for Square**: Number of slides when current image is square
- **Gallery Height**: Overall height of the gallery (px or vh)
- **Autoplay**: Enable automatic slideshow
- **Autoplay Speed**: Time between slides in milliseconds
- **Infinite Loop**: Continue from first slide after last
- **Show Arrows**: Display navigation arrows
- **Show Dots**: Display navigation dots

### Style Tab

**Images**
- Border Radius: Rounded corners for images
- Spacing: Gap between slides

**Navigation**
- Arrow Color: Color of navigation arrows
- Arrow Background: Background color of arrow buttons
- Arrow Size: Diameter of arrow buttons

## Image Orientation Detection

The widget automatically detects image orientation based on aspect ratio:
- **Landscape**: Width/Height > 1.2
- **Portrait**: Width/Height < 0.8
- **Square**: Width/Height between 0.8 and 1.2

## Example Use Cases

- Team photo galleries with mixed portrait/landscape photos
- Product showcases
- Event photo galleries
- Portfolio displays
