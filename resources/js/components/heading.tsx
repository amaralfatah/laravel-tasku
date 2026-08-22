/**
 * The default variant is the page title, so it renders the page's single `h1`;
 * the small variant is a section title one level below it.
 */
export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    const isSmall = variant === 'small';
    const Tag = isSmall ? 'h2' : 'h1';

    return (
        <header className={isSmall ? '' : 'mb-8 space-y-1'}>
            <Tag
                className={
                    isSmall
                        ? 'mb-0.5 text-base font-semibold'
                        : 'text-xl font-semibold tracking-tight text-foreground'
                }
            >
                {title}
            </Tag>
            {description && (
                <p className="max-w-prose text-sm text-muted-foreground">
                    {description}
                </p>
            )}
        </header>
    );
}
