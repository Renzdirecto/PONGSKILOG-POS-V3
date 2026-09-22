/** Capture the receipt's solid backgrounds, borders, images and text at 2x resolution. */
export async function receiptPng(element: HTMLElement): Promise<Blob> {
    await document.fonts.ready;
    const images = Array.from(element.querySelectorAll('img'));
    await Promise.all(images.map((image) => image.decode()));

    const bounds = element.getBoundingClientRect();
    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(bounds.width * 2);
    canvas.height = Math.ceil(bounds.height * 2);
    const context = canvas.getContext('2d');
    if (!context)
        throw new Error('Unable to create receipt image. Please try again.');
    context.scale(2, 2);
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, bounds.width, bounds.height);

    const paint = (node: Element) => {
        const style = getComputedStyle(node);
        if (style.display === 'none' || style.visibility === 'hidden') return;
        const rect = node.getBoundingClientRect();
        const x = rect.left - bounds.left;
        const y = rect.top - bounds.top;
        const radius = Math.min(
            parseFloat(style.borderTopLeftRadius) || 0,
            rect.width / 2,
            rect.height / 2,
        );
        context.fillStyle = style.backgroundColor;
        context.beginPath();
        context.roundRect(x, y, rect.width, rect.height, radius);
        context.fill();

        const borders = [
            style.borderTopWidth,
            style.borderRightWidth,
            style.borderBottomWidth,
            style.borderLeftWidth,
        ].map((width) => parseFloat(width) || 0);
        if (borders.every((width) => width === borders[0]) && borders[0] > 0) {
            const width = borders[0];
            context.strokeStyle = style.borderTopColor;
            context.lineWidth = width;
            context.beginPath();
            context.roundRect(
                x + width / 2,
                y + width / 2,
                rect.width - width,
                rect.height - width,
                Math.max(0, radius - width / 2),
            );
            context.stroke();
        } else {
            const colors = [
                style.borderTopColor,
                style.borderRightColor,
                style.borderBottomColor,
                style.borderLeftColor,
            ];
            const edges = [
                [x, y, rect.width, borders[0]],
                [x + rect.width - borders[1], y, borders[1], rect.height],
                [x, y + rect.height - borders[2], rect.width, borders[2]],
                [x, y, borders[3], rect.height],
            ];
            edges.forEach(([left, top, width, height], index) => {
                context.fillStyle = colors[index];
                context.fillRect(left, top, width, height);
            });
        }

        if (node instanceof HTMLImageElement) {
            context.drawImage(node, x, y, rect.width, rect.height);
            return;
        }

        for (const child of node.childNodes) {
            if (child instanceof Element) {
                paint(child);
            } else if (child instanceof Text && child.textContent?.trim()) {
                context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
                context.fillStyle = style.color;
                context.textAlign = 'left';
                context.textBaseline = 'alphabetic';
                const metrics = context.measureText('Mg');
                const ascent =
                    metrics.fontBoundingBoxAscent ??
                    metrics.actualBoundingBoxAscent;
                const descent =
                    metrics.fontBoundingBoxDescent ??
                    metrics.actualBoundingBoxDescent;
                const range = document.createRange();
                let offset = 0;
                for (const character of child.textContent) {
                    range.setStart(child, offset);
                    offset += character.length;
                    range.setEnd(child, offset);
                    const textRect = range.getBoundingClientRect();
                    if (!character.trim() || textRect.width === 0) continue;
                    context.fillText(
                        character,
                        textRect.left - bounds.left,
                        textRect.top -
                            bounds.top +
                            (textRect.height - ascent - descent) / 2 +
                            ascent,
                    );
                }
            }
        }
    };
    paint(element);
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob) resolve(blob);
            else
                reject(
                    new Error(
                        'Unable to save receipt image. Please try again.',
                    ),
                );
        }, 'image/png');
    });
}
