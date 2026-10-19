/**
 * WCAG contrast between an element's text color and its background, read from the browser's computed styles. Colors are
 * painted onto a canvas so that any CSS color syntax the theme uses (oklch, color-mix, rgb) comes back as plain RGB.
 */

type Rgb = [number, number, number];

function toRgb(win: Window, color: string): Rgb {
  const canvas = win.document.createElement('canvas');
  canvas.width = canvas.height = 1;
  const context = canvas.getContext('2d', { willReadFrequently: true })!;
  context.clearRect(0, 0, 1, 1);
  context.fillStyle = color;
  context.fillRect(0, 0, 1, 1);
  const [r, g, b] = context.getImageData(0, 0, 1, 1).data;
  return [r!, g!, b!];
}

function luminance([r, g, b]: Rgb): number {
  const channel = (value: number) => {
    const s = value / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

export function contrastOf(element: HTMLElement): number {
  const win = element.ownerDocument.defaultView!;
  const style = win.getComputedStyle(element);
  const foreground = luminance(toRgb(win, style.color));
  const background = luminance(toRgb(win, style.backgroundColor));
  const [lighter, darker] =
    foreground > background ? [foreground, background] : [background, foreground];
  return (lighter + 0.05) / (darker + 0.05);
}
