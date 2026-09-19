import { Coffee, Grid2X2, UtensilsCrossed } from 'lucide-react';
import { useState } from 'react';
import type { PosProduct } from '@/types/pos';

export function PosProductMedia({
    product,
    detail = false,
}: {
    product: Pick<PosProduct, 'name' | 'category_name' | 'image_url'>;
    detail?: boolean;
}) {
    const [failed, setFailed] = useState(false);
    const Icon = /drink|coffee|beverage/i.test(product.category_name)
        ? Coffee
        : /silog|meal|rice/i.test(product.category_name)
          ? UtensilsCrossed
          : Grid2X2;
    return product.image_url && !failed ? (
        <img
            src={product.image_url}
            alt={product.name}
            loading="lazy"
            onError={() => setFailed(true)}
            className="h-full w-full object-contain"
        />
    ) : detail ? (
        <span
            aria-hidden="true"
            className="text-[52px] font-bold tracking-wide text-neutral-300"
        >
            {product.name
                .split(' ')
                .map((word) => word[0])
                .slice(0, 2)
                .join('')}
        </span>
    ) : (
        <Icon
            aria-hidden="true"
            className="size-7 text-neutral-300"
            strokeWidth={1.5}
        />
    );
}
