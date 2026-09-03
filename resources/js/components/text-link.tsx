import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: Props) {
    return (
        <Link
            className={cn(
                // Every "click me" signal is the single accent — Action Blue on
                // light surfaces, Sky Link Blue on dark ones.
                'text-link underline decoration-link/40 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
